<?php

/**
 * The protection every submission passes before FormIt or the processing
 * snippet runs, on the AJAX path (action.php) and on a plain POST to the page.
 *
 * The token. Every form gets a hidden field with "form.time.nonce.signature":
 * the first 8 characters of the form's action (public, it is in the markup),
 * the time the form was rendered, a random nonce, and the HMAC of the whole
 * action, the time and the nonce. No session is needed. A token is used once:
 * its nonce gets a mark file under core/cache/fetchit/tokens/, created
 * atomically, which "Clear cache" does not touch. The next token for the page
 * keeps the time of the first render, so the fill time goes on counting.
 *
 * The checks, in this order, with protection on:
 * - the token belongs to the form and is well signed;
 * - at most fetchit.protection.rate_limit submissions of a form per client
 *   address within fetchit.protection.rate_window seconds, every attempt
 *   with a well-signed token counted (in the MODX cache: approximate, and
 *   reset by "Clear cache");
 * - the token is not expired (fetchit.protection.token_ttl; 0 means 30
 *   days) and not used;
 * - the trap, a hidden field only bots fill, gets a fake success;
 * - with fetchit.protection.pow above 0, the script must have solved a
 *   proof of work on the token: a number n such that sha256("token:n") starts
 *   with that many zero bits (see solve() in src/pow.ts);
 * - a form sent sooner than fetchit.protection.min_time seconds after it was
 *   rendered is refused;
 * - with fetchit.captcha set up, the provider confirms the captcha
 *   (FetchItCaptcha); when it cannot tell, the form is refused too.
 * Then plugins on OnFetchItBeforeProcess may refuse, also with protection off.
 *
 * Only a request that brought a well-signed token of the form gets the next
 * one (nextToken()): a request without a token is refused and gets none, so a
 * bot has to load the page. With fetchit.page_cache, exchange() gives the
 * script a fresh token for the token of a page from a full-page cache, and a
 * used or expired token gets a next one timed now.
 *
 * The service fields are removed from the fields, $_POST and $_REQUEST before
 * FormIt or the processing snippet runs, so they never reach e-mails.
 */
class FetchItGuard
{
    /** The field with the token */
    const TOKEN = 'fetchit_token';

    /** The field with the solution of the proof of work */
    const POW = 'fetchit_pow';

    /** The largest proof of work, in bits: 24 takes a computer about a minute on average */
    const MAX_POW = 24;

    /** Seconds a token and its mark live when fetchit.protection.token_ttl is 0 */
    const MAX_TTL = 2592000;

    /** Log nothing, problems and refusals that may hit people, or everything */
    const LOG_NONE = 0;
    const LOG_PROBLEMS = 1;
    const LOG_ALL = 2;

    /** @var FetchIt */
    protected $fetchit;

    /** @var modX */
    protected $modx;

    /** @var callable|null Returns the current time; tests replace it */
    protected $clock;

    /** @var string|null The token for the next submission, see nextToken() */
    protected $next;

    /**
     * @var string|null Why the last check refused: token, rate, store, pow,
     *     too_fast, captcha, captcha_unavailable or plugin
     */
    protected $reason;

    /** @var FetchItCaptcha|null */
    protected $captcha;

    /** @var bool[] The setting problems logged in this request */
    protected $warned = [];

    /** @var FetchItStore|null|false Where marks and counters live; false until store() picked it */
    protected $store = false;

    /** @var bool Whether the last withinRateLimit() refused because its counter could not be stored */
    protected $storeFailed = false;


    /**
     * @param FetchIt $fetchit
     */
    public function __construct(FetchIt $fetchit)
    {
        $this->fetchit = $fetchit;
        $this->modx = $fetchit->modx;
    }


    /**
     * @param callable $clock
     */
    public function setClock(callable $clock)
    {
        $this->clock = $clock;
    }


    /**
     * The system setting only: a snippet call cannot turn it off for its own
     * form, since action.php would still check the token.
     *
     * @return bool
     */
    public function enabled()
    {
        return (bool)$this->modx->getOption('fetchit.protection', null, true);
    }


    /**
     * Whether the script exchanges the token of a page for a fresh one:
     * fetchit.page_cache with the protection on.
     *
     * @return bool
     */
    public function freshToken()
    {
        return $this->enabled() && $this->fetchit->pageCache();
    }


    /**
     * The external captcha.
     *
     * @return FetchItCaptcha
     */
    public function captcha()
    {
        if ($this->captcha === null) {
            $this->captcha = new FetchItCaptcha($this->modx);
        }

        return $this->captcha;
    }


    /**
     * The captcha for the page and the check: provider, siteKey, field and
     * script; null when protection is off or no captcha is set up.
     *
     * @return array|null
     */
    public function activeCaptcha()
    {
        if (!$this->enabled() || ($provider = $this->captcha()->provider()) === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'siteKey' => $this->captcha()->siteKey(),
            'field' => $this->captcha()->field(),
            'script' => $this->captcha()->script(),
        ];
    }


    /**
     * The proof of work asked of the script, in bits; 0 when there is none.
     * A setting that is not a whole number from 0 to MAX_POW is logged.
     *
     * @return int
     */
    public function pow()
    {
        if (!$this->enabled()) {
            return 0;
        }

        $setting = trim((string)$this->modx->getOption('fetchit.protection.pow', null, 0));
        $bits = max(0, min(self::MAX_POW, (int)$setting));
        if ($setting !== '' && $setting !== (string)$bits && !isset($this->warned['pow'])) {
            $this->warned['pow'] = true;
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] fetchit.protection.pow is \"{$setting}\", not a whole number from 0 to "
                . self::MAX_POW . "; using {$bits}");
        }

        return $bits;
    }


    /**
     * Whether $solution solves the proof of work of $token: sha256 of
     * "token:solution" starts with $bits zero bits.
     *
     * @param string $token
     * @param string $solution
     * @param int $bits
     *
     * @return bool
     */
    public static function solves($token, $solution, $bits)
    {
        if (!is_string($token) || !is_string($solution) || !preg_match('/^\d{1,12}$/', $solution)) {
            return false;
        }
        // A hash has 256 bits.
        $bits = max(0, min(256, (int)$bits));
        $hash = hash('sha256', $token . ':' . $solution, true);
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($hash, 0, $bytes) !== str_repeat("\0", $bytes)) {
            return false;
        }
        $rest = $bits % 8;

        return $rest === 0 || (ord($hash[$bytes]) >> (8 - $rest)) === 0;
    }


    /**
     * The name of the trap field: random per installation, so browser
     * autofill and password managers do not recognise and fill it.
     *
     * @return string
     */
    public function trapName()
    {
        return 'fetchit_' . substr(hash_hmac('sha256', 'trap', $this->secret()), 0, 8);
    }


    /**
     * The service fields for one form: a fresh token and the trap.
     *
     * @param string $action
     *
     * @return string
     */
    public function fields($action)
    {
        if (!$this->enabled()) {
            return '';
        }

        $label = htmlspecialchars($this->modx->lexicon('fetchit_trap_label'), ENT_QUOTES);

        return '<input type="hidden" name="' . self::TOKEN . '" value="' . $this->issue($action) . '">'
            . '<div style="position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
            . '<label>' . $label . ' <input type="text" name="' . $this->trapName() . '" value="" tabindex="-1" autocomplete="off"></label>'
            . '</div>';
    }


    /**
     * A new token for the form with this action.
     *
     * @param string $action
     * @param int|null $time The time the form was rendered; now by default
     *
     * @return string
     */
    public function issue($action, $time = null)
    {
        $time = $time === null ? $this->now() : (int)$time;
        $nonce = bin2hex(random_bytes(8));

        return substr($action, 0, 8) . '.' . $time . '.' . $nonce . '.' . $this->sign($action, $time, $nonce);
    }


    /**
     * Whether a POST carries a token of this form, well signed or not: the
     * page processes such a POST, or shows why it was refused.
     *
     * @param string $action
     * @param array $post
     *
     * @return bool
     */
    public function isFor($action, array $post)
    {
        $token = $this->read($post);

        return $token !== null && $token['form'] === substr($action, 0, 8);
    }


    /**
     * Check a submission. Marks its token as used and counts it for the rate
     * limit; removes the service fields from $post, $_POST and $_REQUEST.
     * Afterwards nextToken() and reason() tell what to send back.
     *
     * @param string $action
     * @param array $post
     *
     * @return array|null null to go on; otherwise status ("error", or
     *     "success" for a bot caught in the trap) and message (a lexicon key,
     *     the form's successMessage, or a plugin's text)
     */
    public function check($action, array &$post)
    {
        $this->next = null;
        $this->reason = null;
        $token = $this->read($post);
        $sent = $post;
        $this->strip($post);

        if ($this->enabled()) {
            $refused = $this->checkProtection($action, $token, $sent);
            if ($refused !== null) {
                return $refused;
            }
        }

        $outputs = $this->modx->invokeEvent('OnFetchItBeforeProcess', [
            'action' => $action,
            'fields' => $post,
            'properties' => $this->fetchit->loadActionProperties($action),
            'FetchIt' => $this->fetchit,
        ]);
        foreach (is_array($outputs) ? $outputs : [] as $output) {
            if (is_string($output) && trim($output) !== '') {
                $this->log(self::LOG_ALL, 'refused by a plugin on OnFetchItBeforeProcess', $action);

                return $this->refuse('plugin', trim($output));
            }
            if (!empty($output) && !is_string($output)) {
                $this->log(self::LOG_PROBLEMS, 'a plugin on OnFetchItBeforeProcess gave ' . gettype($output)
                    . ' to $modx->event->output(); only a string refuses the submission', $action);
            }
        }

        return null;
    }


    /**
     * Exchange the token of a page for a fresh one, timed now: with
     * fetchit.page_cache the script asks for it on the first focus on the
     * form, or before the first submission if there was none, since a page
     * from a full-page cache holds a token many got.
     * The token must belong to the form and be well signed, of any age, used
     * or not; it is not marked. Exchanges have a rate limit of their own.
     * Afterwards reason() tells why there is none: token, rate or store.
     *
     * @param string $action
     * @param array $post
     *
     * @return string|null
     */
    public function exchange($action, array $post)
    {
        $this->next = null;
        $this->reason = null;
        if (!$this->freshToken()) {
            return null;
        }

        if (!$this->valid($action, $this->read($post))) {
            $this->log(self::LOG_ALL, 'exchange: no valid token', $action);
            $this->refuse('token', 'fetchit_err_token');

            return null;
        }
        if (!$this->withinRateLimit($action, 'exchange')) {
            if ($this->storeFailed) {
                $this->refuse('store', 'fetchit_err_store');

                return null;
            }
            $this->log(self::LOG_PROBLEMS, 'exchange: rate limit reached', $action);
            $this->refuse('rate', 'fetchit_err_rate');

            return null;
        }

        return $this->next = $this->issue($action);
    }


    /**
     * The token for the next submission of the page, after check(): null when
     * the request did not bring a well-signed token of the form.
     *
     * @return string|null
     */
    public function nextToken()
    {
        return $this->next;
    }


    /**
     * Why the last check() refused, or null.
     *
     * @return string|null
     */
    public function reason()
    {
        return $this->reason;
    }


    /**
     * Whether a token belongs to the form and is well signed.
     *
     * @param string $action
     * @param array|null $token From read()
     *
     * @return bool
     */
    protected function valid($action, $token)
    {
        return $token !== null && $token['form'] === substr($action, 0, 8)
            && hash_equals($this->sign($action, $token['time'], $token['nonce']), $token['signature']);
    }


    /**
     * fetchit.protection.min_time, in seconds.
     *
     * @return int
     */
    public function minTime()
    {
        return max(0, (int)$this->modx->getOption('fetchit.protection.min_time', null, 3));
    }


    /**
     * @param string $action
     * @param array|null $token
     * @param array $sent The POST as sent, service fields included
     *
     * @return array|null
     */
    protected function checkProtection($action, $token, array $sent)
    {
        $trap = $this->trapName();
        $caught = isset($sent[$trap]) && trim((string)$sent[$trap]) !== '';
        if (!$this->valid($action, $token)) {
            $this->log(self::LOG_ALL, 'no valid token', $action);

            return $this->refuse('token', 'fetchit_err_token');
        }

        // Every attempt with a well-signed token counts, used and expired ones
        // too, before a next token or a mark: past the limit a replayed token
        // mints nothing and writes nothing.
        if (!$this->withinRateLimit($action)) {
            if ($this->storeFailed) {
                return $this->refuse('store', 'fetchit_err_store');
            }
            $this->log(self::LOG_PROBLEMS, 'rate limit reached', $action);

            return $this->refuse('rate', 'fetchit_err_rate');
        }

        $age = $this->now() - $token['time'];
        $ttl = $this->ttl();
        $minTime = $this->minTime();
        $restart = $this->freshToken();
        $expired = $age > $ttl || $age < 0;
        // An expired token is not marked: its mark would be pruned already.
        $mark = $expired ? null : $this->mark($token['nonce']);

        // The next token. With a full-page cache many visitors hold one
        // token: a used or expired one gets a token timed now, so the fill
        // time starts anew, or a bot replaying it would never wait.
        // Otherwise a retry keeps the time of the first render, and an
        // expired page starts anew, as if it had been filled in already.
        if ($restart && ($expired || $mark === 'used')) {
            $this->next = $this->issue($action);
        } elseif ($expired) {
            $this->next = $this->issue($action, $this->now() - $minTime);
        } else {
            $this->next = $this->issue($action, $token['time']);
        }

        if ($expired) {
            $this->log(self::LOG_ALL, 'expired token', $action);

            return $this->refuse('token', 'fetchit_err_token');
        }

        if ($mark === 'used') {
            $this->log(self::LOG_ALL, 'used token', $action);

            return $this->refuse('token', 'fetchit_err_token');
        }
        if ($mark === 'failed') {
            // Fail closed: without the mark the token could be used again.
            return $this->refuse('store', 'fetchit_err_store');
        }

        if ($caught) {
            $this->log(self::LOG_PROBLEMS, 'the trap field was filled; a fake success was sent', $action);
            $properties = $this->fetchit->loadActionProperties($action);

            return [
                'status' => 'success',
                'message' => !empty($properties['successMessage']) ? $properties['successMessage'] : 'fetchit_success_submit',
            ];
        }

        $bits = $this->pow();
        if ($bits > 0 && !self::solves($token['raw'], isset($sent[self::POW]) ? $sent[self::POW] : '', $bits)) {
            $this->log(self::LOG_ALL, 'no solution of the proof of work', $action);

            return $this->refuse('pow', 'fetchit_err_pow');
        }

        if ($age < $minTime) {
            $this->log(self::LOG_ALL, "sent {$age} s after the page was rendered", $action);

            return $this->refuse('too_fast', 'fetchit_err_too_fast');
        }

        // Last: it asks the provider over the network. The script also
        // relies on it: after a "token" refusal it sends the same answer
        // again, which is still unused only because it was not checked.
        $captcha = $this->activeCaptcha();
        if ($captcha !== null) {
            $field = $captcha['field'];
            $problem = $this->captcha()->verify(isset($sent[$field]) ? $sent[$field] : '', $this->clientAddress());
            if ($problem !== null && $problem['unavailable']) {
                $this->log(self::LOG_PROBLEMS, 'captcha: ' . $problem['problem'], $action);

                return $this->refuse('captcha_unavailable', 'fetchit_err_captcha_unavailable');
            }
            if ($problem !== null) {
                $this->log(self::LOG_ALL, 'captcha: ' . $problem['problem'], $action);

                return $this->refuse('captcha', 'fetchit_err_captcha');
            }
        }

        return null;
    }


    /**
     * @param string $reason
     * @param string $message
     *
     * @return array
     */
    protected function refuse($reason, $message)
    {
        $this->reason = $reason;

        return ['status' => 'error', 'message' => $message];
    }


    /**
     * Where marks, counters and the properties of forms live.
     * fetchit.protection.store: files (the default) or db; null for anything
     * else, and the forms are refused.
     *
     * @return FetchItStore|null
     */
    public function store()
    {
        if ($this->store === false) {
            $setting = strtolower(trim((string)$this->modx->getOption('fetchit.protection.store', null, 'files')));
            $log = function ($what) {
                $this->log(self::LOG_PROBLEMS, $what);
            };
            if ($setting === '' || $setting === 'files') {
                $this->store = new FetchItFileStore($this->modx, $log, $this->ttl());
            } elseif ($setting === 'db') {
                $this->store = new FetchItDbStore($this->modx, $log);
            } else {
                // Fail closed: a typo must not quietly drop to the files of one server.
                $this->store = null;
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] fetchit.protection.store is \"{$setting}\", not files or db;"
                    . ' forms are refused until it is fixed');
            }
        }

        return $this->store;
    }


    /**
     * Mark a nonce as used: "marked", "used" when it was already, or "failed"
     * when the mark could not be written; see FetchItStore::mark().
     *
     * @param string $nonce
     *
     * @return string
     */
    protected function mark($nonce)
    {
        $store = $this->store();

        // A minute past the token, as the marks in files: the clocks of
        // several servers may be a little apart.
        return $store !== null ? $store->mark($nonce, $this->now() + $this->ttl() + 60) : 'failed';
    }


    /**
     * Delete the marks older than the token lifetime from one of the 256
     * shard directories (a random one by default), so that one run stays
     * small however many marks there are.
     *
     * @param string|null $shard Two hex characters
     */
    public function prune($shard = null)
    {
        $store = $this->store();
        if ($store instanceof FetchItFileStore) {
            $store->pruneShard($shard === null ? sprintf('%02x', mt_rand(0, 255)) : $shard);
        } elseif ($store !== null) {
            $store->prune($this->now());
        }
    }


    /**
     * @return int
     */
    protected function ttl()
    {
        $ttl = (int)$this->modx->getOption('fetchit.protection.token_ttl', null, 86400);

        return $ttl > 0 && $ttl < self::MAX_TTL ? $ttl : self::MAX_TTL;
    }


    /**
     * Count this request and check the limit of the form for the client.
     * Submissions and token exchanges ($scope "submit" or "exchange") have
     * counters of their own. The key is an HMAC: an md5 of an IPv4 address
     * is reversed in minutes.
     *
     * @param string $action
     * @param string $scope
     *
     * @return bool
     */
    protected function withinRateLimit($action, $scope = 'submit')
    {
        $this->storeFailed = false;
        $limit = (int)$this->modx->getOption('fetchit.protection.rate_limit', null, 10);
        $window = (int)$this->modx->getOption('fetchit.protection.rate_window', null, 600);
        if ($limit <= 0 || $window <= 0) {
            return true;
        }

        $store = $this->store();
        $key = hash_hmac('sha256', $scope . '|' . $action . '|' . $this->clientAddress(), $this->secret());
        $count = $store !== null ? $store->hit($key, $window, $this->now()) : null;
        if ($count !== null) {
            return $count <= $limit;
        }
        if ($store !== null && !$store->strict()) {
            $this->log(self::LOG_PROBLEMS, 'could not store the rate limit counter; the limit is not enforced', $action);

            return true;
        }
        $this->storeFailed = true;

        return false;
    }


    /**
     * The address of the client. Behind a proxy listed in
     * fetchit.protection.proxies (IPs or CIDR ranges), the last address in the
     * fetchit.protection.ip_header header that is not a proxy itself;
     * otherwise REMOTE_ADDR, since the header can be forged.
     *
     * @return string
     */
    public function clientAddress()
    {
        $address = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $proxies = array_filter(array_map('trim', explode(',', (string)$this->modx->getOption('fetchit.protection.proxies', null, ''))));
        if (!$proxies || !$this->inRanges($address, $proxies)) {
            return $address;
        }

        $header = (string)$this->modx->getOption('fetchit.protection.ip_header', null, 'X-Forwarded-For');
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $chain = isset($_SERVER[$key]) ? array_map('trim', explode(',', (string)$_SERVER[$key])) : [];
        foreach (array_reverse($chain) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) && !$this->inRanges($hop, $proxies)) {
                return $hop;
            }
        }

        return $address;
    }


    /**
     * @param string $address
     * @param string[] $ranges IPs or CIDR ranges, IPv4 or IPv6
     *
     * @return bool
     */
    protected function inRanges($address, array $ranges)
    {
        $ip = @inet_pton($address);
        if ($ip === false) {
            return false;
        }
        foreach ($ranges as $range) {
            list($subnet, $bits) = array_pad(explode('/', $range, 2), 2, null);
            $net = @inet_pton($subnet);
            if ($net === false || strlen($net) !== strlen($ip)) {
                continue;
            }
            $bits = $bits === null ? strlen($ip) * 8 : (int)$bits;
            $bytes = intdiv($bits, 8);
            if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xff << (8 - $rest)) & 0xff);
            if ((substr($ip, $bytes, 1) & $mask) === (substr($net, $bytes, 1) & $mask)) {
                return true;
            }
        }

        return false;
    }


    /**
     * The parts of the token in $post, or null when there is none.
     *
     * @param array $post
     *
     * @return array|null raw (the whole token), form, time, nonce, signature
     */
    protected function read(array $post)
    {
        $token = isset($post[self::TOKEN]) && is_string($post[self::TOKEN]) ? $post[self::TOKEN] : '';
        if (!preg_match('/^([0-9a-f]{8})\.(\d{1,12})\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $token, $match)) {
            return null;
        }

        return ['raw' => $token, 'form' => $match[1], 'time' => (int)$match[2], 'nonce' => $match[3], 'signature' => $match[4]];
    }


    /**
     * @param string $action
     * @param int|string $time
     * @param string $nonce
     *
     * @return string
     */
    protected function sign($action, $time, $nonce)
    {
        return hash_hmac('sha256', $action . '|' . $time . '|' . $nonce, $this->secret());
    }


    /**
     * fetchit.protection.secret, which the installer fills with a random key;
     * or one derived from the $site_id of the MODX config.
     *
     * @return string
     */
    protected function secret()
    {
        $secret = (string)$this->modx->getOption('fetchit.protection.secret', null, '');
        if ($secret !== '') {
            return $secret;
        }

        return hash('sha256', 'fetchit|' . $this->modx->site_id);
    }


    /**
     * Remove the service fields from $post, $_POST and $_REQUEST. The answer
     * field of a captcha only when it is FetchIt's: a site may check its own
     * captcha with a FormIt hook, which reads the same field.
     *
     * @param array $post
     */
    protected function strip(array &$post)
    {
        $fields = [self::TOKEN, self::POW, $this->trapName()];
        $captcha = $this->activeCaptcha();
        if ($captcha !== null) {
            $fields[] = $captcha['field'];
        }
        foreach ($fields as $field) {
            unset($post[$field], $_POST[$field], $_REQUEST[$field]);
        }
    }


    /**
     * Log a refusal or a problem when fetchit.protection.log allows it.
     *
     * @param int $level self::LOG_PROBLEMS or self::LOG_ALL
     * @param string $what
     * @param string $action
     */
    protected function log($level, $what, $action = '')
    {
        if ((int)$this->modx->getOption('fetchit.protection.log', null, self::LOG_PROBLEMS) < $level) {
            return;
        }

        $page = !empty($this->modx->resource) ? $this->modx->resource->get('id') : 0;
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Protection: ' . $what
            . ($action !== '' ? ' (form ' . substr($action, 0, 8) . ', page ' . $page . ', address ' . $this->clientAddress() . ')' : ''));
    }


    /**
     * @return int
     */
    protected function now()
    {
        return $this->clock ? (int)call_user_func($this->clock) : time();
    }
}
