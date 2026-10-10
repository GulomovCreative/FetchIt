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

    /**
     * Mark the nonce of a token as used.
     *
     * @param string $nonce 16 hex characters
     * @param int $expires When the mark may go
     *
     * @return string "marked", "used" when it was already, or "failed"
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
     * @param string $action 32 hex characters
     *
     * @return string|null The properties of the form as JSON
     */
    public function loadForm($action);

    /**
     * Drop what expired; called now and then.
     *
     * @param int $now
     */
    public function prune($now);

    /**
     * Whether a counter that could not be stored refuses the submission.
     *
     * @return bool
     */
    public function strict();
}
