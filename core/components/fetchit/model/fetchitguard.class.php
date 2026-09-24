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
 * - the token belongs to the form, is well signed and not expired
 *   (fetchit.protection.token_ttl; 0 means 30 days) and not used;
 * - the trap, a hidden field only bots fill, gets a fake success;
 * - a form sent sooner than fetchit.protection.min_time seconds after it was
 *   rendered is refused;
 * - at most fetchit.protection.rate_limit submissions of a form per client
 *   address within fetchit.protection.rate_window seconds (counted in the
 *   MODX cache: approximate, and reset by "Clear cache").
 * Then plugins on OnFetchItBeforeProcess may refuse, also with protection off.
 *
 * Only a request that brought a well-signed token of the form gets the next
 * one (nextToken()): a request without a token is refused and gets none, so a
 * bot has to load the page.
 *
 * The service fields are removed from the fields, $_POST and $_REQUEST before
 * FormIt or the processing snippet runs, so they never reach e-mails.
 */
class FetchItGuard
{
    /** The field with the token */
    const TOKEN = 'fetchit_token';

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

    /** @var string|null Why the last check refused: token, too_fast, rate, plugin or store */
    protected $reason;


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
        $trap = $this->trapName();
        $caught = isset($post[$trap]) && trim((string)$post[$trap]) !== '';
        $this->strip($post);

        if ($this->enabled()) {
            $refused = $this->checkProtection($action, $token, $caught);
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
     * @param string $action
     * @param array|null $token
     * @param bool $caught The trap is filled
     *
     * @return array|null
     */
    protected function checkProtection($action, $token, $caught)
    {
        if ($token === null || $token['form'] !== substr($action, 0, 8)
            || !hash_equals($this->sign($action, $token['time'], $token['nonce']), $token['signature'])
        ) {
            $this->log(self::LOG_ALL, 'no valid token', $action);

            return $this->refuse('token', 'fetchit_err_token');
        }

        $age = $this->now() - $token['time'];
        $ttl = $this->ttl();
        $minTime = max(0, (int)$this->modx->getOption('fetchit.protection.min_time', null, 3));
        // A retry keeps the time of the first render; an expired page starts
        // anew, as if it had been filled in already.
        $this->next = $age > $ttl || $age < 0
            ? $this->issue($action, $this->now() - $minTime)
            : $this->issue($action, $token['time']);

        if ($age > $ttl || $age < 0) {
            $this->log(self::LOG_ALL, 'expired token', $action);

            return $this->refuse('token', 'fetchit_err_token');
        }

        $mark = $this->mark($token['nonce']);
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

        if ($age < $minTime) {
            $this->log(self::LOG_ALL, "sent {$age} s after the page was rendered", $action);

            return $this->refuse('too_fast', 'fetchit_err_too_fast');
        }

        if (!$this->withinRateLimit($action)) {
            $this->log(self::LOG_PROBLEMS, 'rate limit reached', $action);

            return $this->refuse('rate', 'fetchit_err_rate');
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
     * Mark a nonce as used: "marked", "used" when it was already, or "failed"
     * when the mark could not be written. The mark is a file created with
     * fopen(..., 'x'), so of two requests with one token only one wins.
     *
     * @param string $nonce
     *
     * @return string
     */
    protected function mark($nonce)
    {
        $dir = $this->marksPath() . substr($nonce, 0, 2) . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->log(self::LOG_PROBLEMS, "could not create {$dir}; forms are refused until it is writable");

            return 'failed';
        }

        $handle = @fopen($dir . $nonce, 'x');
        if ($handle === false) {
            if (file_exists($dir . $nonce)) {
                return 'used';
            }
            $this->log(self::LOG_PROBLEMS, "could not write to {$dir}; forms are refused until it is writable");

            return 'failed';
        }
        fclose($handle);

        // Now and then, drop the marks of tokens that expired anyway.
        if (mt_rand(1, 100) === 1) {
            $this->prune();
        }

        return 'marked';
    }


    /**
     * Delete the marks older than the token lifetime.
     */
    public function prune()
    {
        $before = time() - $this->ttl() - 60;
        foreach (glob($this->marksPath() . '*/*') ?: [] as $file) {
            if (@filemtime($file) < $before) {
                @unlink($file);
            }
        }
    }


    /**
     * @return string
     */
    protected function marksPath()
    {
        return $this->modx->getCachePath() . 'fetchit/tokens/';
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
     * Count this submission and check the limit of the form for the client.
     *
     * @param string $action
     *
     * @return bool
     */
    protected function withinRateLimit($action)
    {
        $limit = (int)$this->modx->getOption('fetchit.protection.rate_limit', null, 10);
        $window = (int)$this->modx->getOption('fetchit.protection.rate_window', null, 600);
        if ($limit <= 0 || $window <= 0) {
            return true;
        }

        $key = 'fetchit/rate/' . md5($action . '|' . $this->clientAddress());
        $count = $this->modx->cacheManager->get($key);
        $now = $this->now();
        if (!is_array($count) || $now - $count['since'] >= $window) {
            $count = ['since' => $now, 'count' => 0];
        }
        $count['count']++;
        if (!$this->modx->cacheManager->set($key, $count, $window)) {
            $this->log(self::LOG_PROBLEMS, 'could not store the rate limit counter; the limit is not enforced', $action);
        }

        return $count['count'] <= $limit;
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
     * @return array|null form, time, nonce, signature
     */
    protected function read(array $post)
    {
        $token = isset($post[self::TOKEN]) && is_string($post[self::TOKEN]) ? $post[self::TOKEN] : '';
        if (!preg_match('/^([0-9a-f]{8})\.(\d{1,12})\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $token, $match)) {
            return null;
        }

        return ['form' => $match[1], 'time' => (int)$match[2], 'nonce' => $match[3], 'signature' => $match[4]];
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
     * Remove the service fields from $post, $_POST and $_REQUEST.
     *
     * @param array $post
     */
    protected function strip(array &$post)
    {
        foreach ([self::TOKEN, $this->trapName()] as $field) {
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
