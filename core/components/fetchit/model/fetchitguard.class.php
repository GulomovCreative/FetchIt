<?php

/**
 * The protection every submission passes before FormIt or the processing
 * snippet runs, on the AJAX path (action.php) and on a plain POST to the page.
 *
 * - Every form gets a signed, single-use token: the HMAC of the form's action,
 *   the time it was issued and a random nonce. It needs no session, and a
 *   used nonce is kept in the MODX cache until the token would expire.
 * - A form sent sooner than fetchit.protection.min_time seconds after it was
 *   rendered is refused: people do not fill forms that fast.
 * - A hidden trap field that only bots fill gets a fake success.
 * - fetchit.protection.rate_limit submissions of a form per address and
 *   fetchit.protection.rate_window seconds.
 * - Plugins on OnFetchItBeforeProcess can refuse a submission; the event
 *   also runs with the protection off.
 *
 * The service fields are removed from the fields, $_POST and $_REQUEST, so
 * they never reach FormIt or its e-mails.
 */
class FetchItGuard
{
    /** The field with the token */
    const TOKEN = 'fetchit_token';

    /** The trap field, hidden from people */
    const TRAP = 'fetchit_website';

    /** @var FetchIt */
    protected $fetchit;

    /** @var modX */
    protected $modx;

    /** @var callable|null Returns the current time; tests replace it */
    protected $clock;


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
     * @return bool
     */
    public function enabled()
    {
        return (bool)$this->modx->getOption('fetchit.protection', $this->fetchit->config, true);
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
            . '<label>' . $label . ' <input type="text" name="' . self::TRAP . '" value="" tabindex="-1" autocomplete="off"></label>'
            . '</div>';
    }


    /**
     * A new token for the form with this action.
     *
     * @param string $action
     *
     * @return string time.nonce.signature
     */
    public function issue($action)
    {
        $time = $this->now();
        $nonce = bin2hex(random_bytes(8));

        return $time . '.' . $nonce . '.' . $this->sign($action, $time, $nonce);
    }


    /**
     * Whether a POST carries a token of this form, whatever its age: the
     * snippet leaves other POSTs alone.
     *
     * @param string $action
     * @param array $post
     *
     * @return bool
     */
    public function isSubmission($action, array $post)
    {
        return $this->parse($action, $post) !== null;
    }


    /**
     * Check a submission. The service fields are removed from $post, $_POST
     * and $_REQUEST.
     *
     * @param string $action
     * @param array $post
     *
     * @return array|null null to go on; otherwise status ("error" or, for a
     *     bot caught in the trap, "success"), message (a lexicon key or a
     *     plugin's text) and token (a fresh one for the next try)
     */
    public function check($action, array &$post)
    {
        $token = $this->parse($action, $post);
        $trap = isset($post[self::TRAP]) ? trim((string)$post[self::TRAP]) : '';
        $this->strip($post);

        if ($this->enabled()) {
            $refused = $this->checkProtection($action, $token, $trap);
            if ($refused !== null) {
                return $refused + ['token' => $this->issue($action)];
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
                return ['status' => 'error', 'message' => trim($output), 'token' => $this->issue($action)];
            }
        }

        return null;
    }


    /**
     * @param string $action
     * @param array|null $token [time, nonce] of a well-signed token, or null
     * @param string $trap
     *
     * @return array|null
     */
    protected function checkProtection($action, $token, $trap)
    {
        if ($token === null) {
            return ['status' => 'error', 'message' => 'fetchit_err_token'];
        }
        list($time, $nonce) = $token;

        $age = $this->now() - $time;
        $ttl = (int)$this->modx->getOption('fetchit.protection.token_ttl', null, 86400);
        if ($age < 0 || ($ttl > 0 && $age > $ttl)) {
            return ['status' => 'error', 'message' => 'fetchit_err_token'];
        }

        $used = 'fetchit/tokens/' . $nonce;
        if ($this->modx->cacheManager->get($used)) {
            return ['status' => 'error', 'message' => 'fetchit_err_token'];
        }
        // xPDOCacheManager::set() takes the value by reference.
        $mark = 1;
        $this->modx->cacheManager->set($used, $mark, $ttl > 0 ? $ttl : 86400);

        if ($trap !== '') {
            $properties = $this->fetchit->loadActionProperties($action);

            return [
                'status' => 'success',
                'message' => !empty($properties['successMessage']) ? $properties['successMessage'] : 'fetchit_success_submit',
            ];
        }

        if ($age < (int)$this->modx->getOption('fetchit.protection.min_time', null, 3)) {
            return ['status' => 'error', 'message' => 'fetchit_err_too_fast'];
        }

        if (!$this->withinRateLimit($action)) {
            return ['status' => 'error', 'message' => 'fetchit_err_rate'];
        }

        return null;
    }


    /**
     * Count this submission and check the limit of the form for the address.
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

        $address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $key = 'fetchit/rate/' . md5($action . '|' . $address);
        $count = $this->modx->cacheManager->get($key);
        $now = $this->now();
        if (!is_array($count) || $now - $count['since'] >= $window) {
            $count = ['since' => $now, 'count' => 0];
        }
        $count['count']++;
        $this->modx->cacheManager->set($key, $count, $window);

        return $count['count'] <= $limit;
    }


    /**
     * [time, nonce] of a token in $post signed for this action, or null.
     *
     * @param string $action
     * @param array $post
     *
     * @return array|null
     */
    protected function parse($action, array $post)
    {
        $token = isset($post[self::TOKEN]) && is_string($post[self::TOKEN]) ? $post[self::TOKEN] : '';
        if (!preg_match('/^(\d{1,12})\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $token, $match)) {
            return null;
        }
        if (!hash_equals($this->sign($action, $match[1], $match[2]), $match[3])) {
            return null;
        }

        return [(int)$match[1], $match[2]];
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
     * fetchit.protection.secret, or one derived from the $site_id of the
     * MODX config, which only the server knows.
     *
     * @return string
     */
    protected function secret()
    {
        $secret = (string)$this->modx->getOption('fetchit.protection.secret');
        if ($secret !== '') {
            return $secret;
        }

        return hash('sha256', 'fetchit|' . $this->modx->site_id . '|' . MODX_CORE_PATH);
    }


    /**
     * Remove the service fields from $post, $_POST and $_REQUEST.
     *
     * @param array $post
     */
    protected function strip(array &$post)
    {
        foreach ([self::TOKEN, self::TRAP] as $field) {
            unset($post[$field], $_POST[$field], $_REQUEST[$field]);
        }
    }


    /**
     * @return int
     */
    protected function now()
    {
        return $this->clock ? (int)call_user_func($this->clock) : time();
    }
}
