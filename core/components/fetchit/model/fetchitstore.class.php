<?php

/**
 * Where FetchItGuard keeps what must outlive a request: the marks of used
 * tokens, the rate limit counters and, with fetchit.page_cache, the
 * properties of the forms. fetchit.protection.store picks one:
 * FetchItFileStore (files, the default) or FetchItDbStore (a table, shared
 * by several servers).
 */
interface FetchItStore
{
    /** Seconds the properties of a form are kept after its last render: 90 days */
    const FORM_TTL = 7776000;

    /** What mark() returns: the nonce is marked now */
    const MARKED = 'marked';

    /** What mark() returns: the nonce was marked already */
    const USED = 'used';

    /** What mark() returns: the mark could not be written */
    const FAILED = 'failed';

    /**
     * Mark the nonce of a token as used.
     *
     * @param string $nonce 16 hex characters
     * @param int $expires When the mark may go
     *
     * @return string MARKED, USED when it was already, or FAILED
     */
    public function mark($nonce, $expires);

    /**
     * Count a hit of a rate limit counter.
     *
     * @param string $key 64 hex characters
     * @param int $window Seconds
     * @param int $now
     *
     * @return int|null The counter after this hit, or null when it could not be stored
     */
    public function hit($key, $window, $now);

    /**
     * Keep the properties of a form for a page from a full-page cache.
     *
     * @param string $action 32 hex characters
     * @param string $json
     * @param int $now
     *
     * @return bool
     */
    public function saveForm($action, $json, $now);

    /**
     * Read the properties of a form kept by saveForm().
     *
     * @param string $action 32 hex characters
     *
     * @return string|null|false The properties of the form as JSON; null
     *     when there are none; false when the store failed (logged)
     */
    public function loadForm($action);

    /**
     * Drop what expired; called now and then.
     *
     * @param int $now
     */
    public function prune($now);

    /**
     * Whether a counter that could not be stored refuses the request: a
     * submission or a token exchange.
     *
     * @return bool
     */
    public function strict();
}
