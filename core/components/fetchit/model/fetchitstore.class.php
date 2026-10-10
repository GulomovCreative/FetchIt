<?php

/**
 * Where FetchItGuard keeps what must outlive a request: the marks of used
 * tokens, the rate limit counters, the answers to submissions sent with retries
 * and, with fetchit.page_cache, the properties of the forms. fetchit.protection.store picks one:
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

    /** Seconds the answer to a submission is kept for its retries */
    const ANSWER_TTL = 3600;

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
     * Take the key of a submission before it is processed, so that a retry
     * of it is not processed again. A key taken more than ANSWER_TTL ago is
     * free again.
     *
     * @param string $key 64 hex characters
     * @param int $now
     *
     * @return string MARKED, USED when it was taken already, or FAILED
     */
    public function claim($key, $now);

    /**
     * Keep the answer to a submission whose key was taken, for ANSWER_TTL.
     *
     * @param string $key 64 hex characters
     * @param string $json
     * @param int $now
     *
     * @return bool
     */
    public function saveAnswer($key, $json, $now);

    /**
     * The answer to a submission kept by saveAnswer().
     *
     * @param string $key 64 hex characters
     * @param int $now
     *
     * @return string|null|false The answer; '' when the key is taken and the
     *     answer not there yet; null when the key is free; false when the
     *     store failed (logged)
     */
    public function loadAnswer($key, $now);

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
