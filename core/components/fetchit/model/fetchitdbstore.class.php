<?php

/**
 * The store of FetchItGuard in a table of the site's database, shared by
 * several servers: fetchit.protection.store db. The installer creates the
 * table {table_prefix}fetchit_store; a row holds a mark ("m:<nonce>"), a
 * rate counter ("r:<key>"), the properties of a form ("f:<action>") or the
 * answer to a submission ("s:<key>").
 *
 * The SQL is the part MySQL, MariaDB, SQL Server and SQLite share, with
 * positional parameters only: named ones used twice need emulated prepares.
 * Any error fails closed and is logged once per request and operation, with
 * the SQLSTATE and the driver code only: neither the query nor the message
 * of the driver, which may hold its values.
 */
class FetchItDbStore implements FetchItStore
{
    /** SQLSTATE of an integrity constraint violation */
    const INTEGRITY = '23000';

    /** Driver codes of a duplicate key: MySQL and MariaDB; SQL Server (2); SQLite */
    const DUPLICATE = [1062, 2627, 2601, 19];

    /** The longest properties of a form in bytes: TEXT of MySQL, the smallest of the databases */
    const MAX_FORM = 65535;

    /** What a failure of each operation means for the site, for the log */
    const CONSEQUENCES = [
        'mark' => '; forms are refused',
        'hit' => '; forms are refused',
        'saveForm' => '; the form does not work from a full-page cache',
        'loadForm' => '; the form is answered with fetchit_err_store',
        'claim' => '; a retry of a form may process it twice',
        'saveAnswer' => '; a retry of a form is answered that it is still processed',
        'loadAnswer' => '; a retry of a form may process it twice',
        'release' => '; a retry of a form is answered that it is still processed',
    ];

    /** @var modX */
    protected $modx;

    /** @var callable Logs a problem, given what went wrong */
    protected $log;

    /** @var bool[] The operations whose problems were logged in this request */
    protected $warned = [];


    /**
     * @param modX $modx
     * @param callable $log
     */
    public function __construct($modx, callable $log)
    {
        $this->modx = $modx;
        $this->log = $log;
    }


    /**
     * The INSERT fails on the primary key when the nonce is there, so of two
     * requests with one token only one wins.
     *
     * {@inheritdoc}
     */
    public function mark($nonce, $expires)
    {
        $result = $this->run('INSERT INTO ' . $this->table() . ' (k, since, ' . $this->count() . ', expires, v) VALUES (?, 0, 0, ?, NULL)', [
            'm:' . $nonce,
            (int)$expires,
        ], 'mark');
        if ($result === 'duplicate') {
            return self::USED;
        }
        if (!$result instanceof PDOStatement) {
            return self::FAILED;
        }

        if (mt_rand(1, 100) === 1) {
            $this->prune(time());
        }

        return self::MARKED;
    }


    /**
     * One UPDATE counts or starts a new window; a missing row is inserted,
     * and when another request inserted it meanwhile, the UPDATE runs again.
     * The counter only grows, so a race may read it higher, never lower.
     *
     * MySQL and MariaDB apply SET from left to right, each expression seeing
     * the columns set before it: the count goes first, so that both CASEs
     * see the old since. SQL Server and SQLite always see the old values.
     *
     * {@inheritdoc}
     */
    public function hit($key, $window, $now)
    {
        $table = $this->table();
        $count = $this->count();
        $row = 'r:' . $key;
        $start = $now - $window;
        $expires = $now + $window;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $updated = $this->run("UPDATE {$table} SET {$count} = CASE WHEN since <= ? THEN 1 ELSE {$count} + 1 END,"
                . ' since = CASE WHEN since <= ? THEN ? ELSE since END, expires = ? WHERE k = ?', [
                $start, $start, $now, $expires, $row,
            ], 'hit');
            if (!$updated instanceof PDOStatement) {
                return null;
            }
            if ($updated->rowCount() > 0) {
                break;
            }

            $inserted = $this->run("INSERT INTO {$table} (k, since, {$count}, expires, v) VALUES (?, ?, 1, ?, NULL)", [$row, $now, $expires], 'hit');
            if ($inserted instanceof PDOStatement) {
                return 1;
            }
            if ($inserted !== 'duplicate' || $attempt === 1) {
                return null;
            }
        }

        $read = $this->run("SELECT {$count} FROM {$table} WHERE k = ?", [$row], 'hit');
        $value = $read instanceof PDOStatement ? $read->fetchColumn() : false;

        return $value === false ? null : (int)$value;
    }


    /**
     * Inserted once: the action key is a hash of the properties. A render a
     * day later keeps it for FORM_TTL more. Properties longer than MAX_FORM
     * are not kept (logged once per form): one database would cut them,
     * another refuse them.
     *
     * {@inheritdoc}
     */
    public function saveForm($action, $json, $now)
    {
        $length = strlen($json);
        if ($length > self::MAX_FORM) {
            if (!isset($this->warned['long:' . $action])) {
                $this->warned['long:' . $action] = true;
                call_user_func($this->log, 'The properties of form ' . substr($action, 0, 8) . " are {$length} bytes, more than the table keeps ("
                    . self::MAX_FORM . '); it does not work from a full-page cache');
            }

            return false;
        }

        $row = 'f:' . $action;
        $expires = $now + self::FORM_TTL;
        $inserted = $this->run('INSERT INTO ' . $this->table() . ' (k, since, ' . $this->count() . ', expires, v) VALUES (?, 0, 0, ?, ?)', [
            $row, $expires, $json,
        ], 'saveForm');
        if ($inserted !== 'duplicate') {
            return $inserted instanceof PDOStatement;
        }

        return $this->run('UPDATE ' . $this->table() . ' SET expires = ? WHERE k = ? AND expires < ?', [
            $expires, $row, $expires - 86400,
        ], 'saveForm') instanceof PDOStatement;
    }


    /**
     * No row: null. A failed query: false.
     *
     * {@inheritdoc}
     */
    public function loadForm($action)
    {
        $read = $this->run('SELECT v FROM ' . $this->table() . ' WHERE k = ?', ['f:' . $action], 'loadForm');
        if (!$read instanceof PDOStatement) {
            return false;
        }
        $value = $read->fetchColumn();

        return is_string($value) ? $value : null;
    }


    /**
     * The INSERT fails on the primary key when the key is taken, so of two
     * attempts of one submission only one is processed. A row that expired
     * is taken over.
     *
     * {@inheritdoc}
     */
    public function claim($key, $now)
    {
        $table = $this->table();
        $row = 's:' . $key;
        $expires = $now + self::ANSWER_TTL;
        $inserted = $this->run("INSERT INTO {$table} (k, since, " . $this->count() . ', expires, v) VALUES (?, 0, 0, ?, NULL)', [$row, $expires], 'claim');
        if ($inserted instanceof PDOStatement) {
            return self::MARKED;
        }
        if ($inserted !== 'duplicate') {
            return self::FAILED;
        }

        $taken = $this->run("UPDATE {$table} SET expires = ?, v = NULL WHERE k = ? AND expires < ?", [$expires, $row, $now], 'claim');
        if (!$taken instanceof PDOStatement) {
            return self::FAILED;
        }

        return $taken->rowCount() > 0 ? self::MARKED : self::USED;
    }


    /**
     * An answer longer than MAX_FORM is kept without its data.
     *
     * {@inheritdoc}
     */
    public function saveAnswer($key, $json, $now)
    {
        $table = $this->table();
        $row = 's:' . $key;
        $json = $this->fitAnswer($json);
        $expires = $now + self::ANSWER_TTL;
        $updated = $this->run("UPDATE {$table} SET v = ?, expires = ? WHERE k = ?", [$json, $expires, $row], 'saveAnswer');
        if (!$updated instanceof PDOStatement) {
            return false;
        }
        if ($updated->rowCount() > 0) {
            return true;
        }
        // The row went meanwhile (a prune of a slow submission).
        $inserted = $this->run("INSERT INTO {$table} (k, since, " . $this->count() . ', expires, v) VALUES (?, 0, 0, ?, ?)', [$row, $expires, $json], 'saveAnswer');

        return $inserted instanceof PDOStatement || $inserted === 'duplicate';
    }


    /**
     * {@inheritdoc}
     */
    public function loadAnswer($key, $now)
    {
        $read = $this->run('SELECT v, expires FROM ' . $this->table() . ' WHERE k = ?', ['s:' . $key], 'loadAnswer');
        if (!$read instanceof PDOStatement) {
            return false;
        }
        $row = $read->fetch(PDO::FETCH_NUM);
        if ($row === false || (int)$row[1] < $now) {
            return null;
        }

        return $row[0] === null ? '' : (string)$row[0];
    }


    /**
     * Only a row without an answer is deleted.
     *
     * {@inheritdoc}
     */
    public function release($key)
    {
        return $this->run('DELETE FROM ' . $this->table() . ' WHERE k = ? AND v IS NULL', ['s:' . $key], 'release') instanceof PDOStatement;
    }


    /**
     * An answer that fits the column: without its data when it is too long,
     * and with only its outcome when even its message is.
     *
     * @param string $json
     *
     * @return string
     */
    protected function fitAnswer($json)
    {
        if (strlen($json) <= self::MAX_FORM) {
            return $json;
        }
        $answer = json_decode($json, true);
        $success = is_array($answer) && isset($answer['success']) && $answer['success'] === true;
        $short = [
            'success' => $success,
            'message' => is_array($answer) && isset($answer['message']) && is_string($answer['message']) ? $answer['message'] : '',
        ];
        if (is_array($answer) && isset($answer['redirect']) && is_string($answer['redirect'])) {
            $short['redirect'] = $answer['redirect'];
        }
        $json = json_encode($short);
        if ($json !== false && strlen($json) <= self::MAX_FORM) {
            return $json;
        }

        return json_encode(['success' => $success, 'message' => '']);
    }


    /**
     * {@inheritdoc}
     */
    public function prune($now)
    {
        $this->run('DELETE FROM ' . $this->table() . ' WHERE expires < ?', [(int)$now], 'prune');
    }


    /**
     * The table was chosen for a strict limit: a counter that cannot be
     * stored refuses.
     *
     * {@inheritdoc}
     */
    public function strict()
    {
        return true;
    }


    /**
     * Create the table and its index unless the table is there.
     *
     * @return bool Whether the table is there afterwards
     */
    public function install()
    {
        if ($this->exists()) {
            return true;
        }

        return $this->run('CREATE TABLE ' . $this->table() . ' (k VARCHAR(80) NOT NULL PRIMARY KEY, since INT NOT NULL, '
                . $this->count() . ' INT NOT NULL, expires INT NOT NULL, v TEXT NULL)', [], 'install') instanceof PDOStatement
            && $this->run('CREATE INDEX ' . $this->name('store_expires') . ' ON ' . $this->table() . ' (expires)', [], 'install') instanceof PDOStatement;
    }


    /**
     * Drop the table if it is there.
     *
     * @return bool Whether the table is gone afterwards
     */
    public function uninstall()
    {
        return !$this->exists() || $this->run('DROP TABLE ' . $this->table(), [], 'uninstall') instanceof PDOStatement;
    }


    /**
     * @return bool
     */
    public function exists()
    {
        return $this->run('SELECT 1 FROM ' . $this->table() . ' WHERE 1 = 0', [], null) instanceof PDOStatement;
    }


    /**
     * Run a statement.
     *
     * @param string $sql
     * @param array $params
     * @param string|null $operation The operation for the log (a key of
     *     CONSEQUENCES, prune, install or uninstall); null logs nothing
     *
     * @return PDOStatement|string|null The statement; "duplicate" when the
     *     primary key was taken; null on any other error
     */
    protected function run($sql, array $params, $operation)
    {
        $error = null;
        try {
            $statement = $this->modx->prepare($sql);
            if ($statement !== false && $statement->execute($params)) {
                return $statement;
            }
            $error = $statement !== false ? $statement->errorInfo() : $this->modx->errorInfo();
        } catch (PDOException $exception) {
            $error = $exception->errorInfo ?: [(string)$exception->getCode(), null];
        }

        if (isset($error[0], $error[1]) && (string)$error[0] === self::INTEGRITY && in_array((int)$error[1], self::DUPLICATE, true)) {
            return 'duplicate';
        }
        if ($operation !== null && !isset($this->warned[$operation])) {
            $this->warned[$operation] = true;
            call_user_func($this->log, 'The table ' . $this->prefixed('store') . " failed on {$operation}"
                . ' (SQLSTATE ' . (isset($error[0]) ? $error[0] : '?') . ', code ' . (isset($error[1]) ? $error[1] : '?') . ')'
                . (isset(self::CONSEQUENCES[$operation]) ? self::CONSEQUENCES[$operation] : ''));
        }

        return null;
    }


    /**
     * @return string
     */
    protected function table()
    {
        return $this->name('store');
    }


    /**
     * @param string $name The name without "fetchit_"
     *
     * @return string The name with the table prefix, quoted
     */
    protected function name($name)
    {
        return $this->modx->escape($this->prefixed($name));
    }


    /**
     * @param string $name The name without "fetchit_"
     *
     * @return string The name with the table prefix, not quoted
     */
    protected function prefixed($name)
    {
        return $this->modx->getOption('table_prefix', null, '') . 'fetchit_' . $name;
    }


    /**
     * @return string The column count, quoted: a keyword in some databases
     */
    protected function count()
    {
        return $this->modx->escape('count');
    }
}
