<?php

/**
 * The store of FetchItGuard in files, as FetchIt 4.0 kept it. A used token
 * gets a mark file under core/cache/fetchit/tokens/, created atomically,
 * which "Clear cache" does not touch. A rate counter lives in the MODX cache:
 * approximate, and reset by "Clear cache". Each server has its own files;
 * FetchItDbStore is for several.
 */
class FetchItFileStore implements FetchItStore
{
    /** The start of a form file: run through the web, it outputs nothing */
    const HIDE = "<?php exit; ?>\n";

    /** @var modX */
    protected $modx;

    /** @var callable Logs a problem, given what went wrong */
    protected $log;

    /** @var int Seconds a mark lives */
    protected $ttl;

    /** @var bool Whether a form file that cannot be read was logged in this request */
    protected $unreadable = false;

    /** @var bool Whether an answer file that cannot be read was logged in this request */
    protected $unreadableAnswer = false;


    /**
     * @param modX $modx
     * @param callable $log
     * @param int $ttl
     */
    public function __construct($modx, callable $log, $ttl)
    {
        $this->modx = $modx;
        $this->log = $log;
        $this->ttl = (int)$ttl;
    }


    /**
     * The mark is a file created with fopen(..., 'x'), so of two requests
     * with one token only one wins. $expires is not needed: prune() goes by
     * the age of the file.
     *
     * {@inheritdoc}
     */
    public function mark($nonce, $expires)
    {
        $dir = $this->path() . substr($nonce, 0, 2) . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            call_user_func($this->log, "could not create {$dir}; forms are refused until it is writable");

            return self::FAILED;
        }

        $handle = @fopen($dir . $nonce, 'x');
        if ($handle === false) {
            if (file_exists($dir . $nonce)) {
                return self::USED;
            }
            call_user_func($this->log, "could not write to {$dir}; forms are refused until it is writable");

            return self::FAILED;
        }
        fclose($handle);

        // Now and then, drop the marks of tokens that expired anyway.
        if (mt_rand(1, 100) === 1) {
            $this->prune(time());
        }

        return self::MARKED;
    }


    /**
     * {@inheritdoc}
     */
    public function hit($key, $window, $now)
    {
        $key = 'fetchit/rate/' . $key;
        $count = $this->modx->cacheManager->get($key);
        if (!is_array($count) || $now - $count['since'] >= $window) {
            $count = ['since' => $now, 'count' => 0];
        }
        $count['count']++;
        if (!$this->modx->cacheManager->set($key, $count, $window)) {
            return null;
        }

        return $count['count'];
    }


    /**
     * A file under core/cache/fetchit/forms/, which "Clear cache" does not
     * touch, written once: the action key is a hash of the properties. A
     * render a day later keeps it for FORM_TTL more.
     *
     * {@inheritdoc}
     */
    public function saveForm($action, $json, $now)
    {
        $file = $this->formFile($action);
        $time = @filemtime($file);
        if ($time !== false) {
            if ($now - $time > 86400) {
                @touch($file, $now);
            }

            return true;
        }

        $dir = dirname($file) . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            call_user_func($this->log, "could not create {$dir}; forms from a full-page cache are not found");

            return false;
        }
        // A whole file or none: a submission may read it meanwhile. A
        // leftover ends with .php too, so the web server runs its exit.
        $temporary = $dir . '.' . $action . '.' . bin2hex(random_bytes(4)) . '.php';
        if (@file_put_contents($temporary, self::HIDE . $json) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            call_user_func($this->log, "could not write to {$dir}; forms from a full-page cache are not found");

            return false;
        }
        @touch($file, $now);

        if (mt_rand(1, 100) === 1) {
            $this->pruneForms(sprintf('%02x', mt_rand(0, 255)), $now);
        }

        return true;
    }


    /**
     * No file: null. A file that cannot be read or does not start with HIDE
     * (not written by saveForm()): false, logged once per request.
     *
     * {@inheritdoc}
     */
    public function loadForm($action)
    {
        $file = $this->formFile($action);
        if (!file_exists($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if ($content === false || strpos($content, self::HIDE) !== 0) {
            if (!$this->unreadable) {
                $this->unreadable = true;
                call_user_func($this->log, "the properties of form " . substr($action, 0, 8) . " in {$file} cannot be read;"
                    . ' the form is answered with fetchit_err_store');
            }

            return false;
        }

        return substr($content, strlen(self::HIDE));
    }


    /**
     * The file of the properties of a form.
     *
     * @param string $action 32 hex characters
     *
     * @return string
     */
    public function formFile($action)
    {
        return $this->modx->getCachePath() . 'fetchit/forms/' . substr($action, 0, 2) . '/' . $action . '.php';
    }


    /**
     * Delete the forms of one shard not rendered for FORM_TTL.
     *
     * @param string $shard Two hex characters
     * @param int $now
     */
    public function pruneForms($shard, $now)
    {
        $dir = $this->modx->getCachePath() . 'fetchit/forms/' . $shard . '/';
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $before = $now - self::FORM_TTL;
        while (($file = readdir($handle)) !== false) {
            if ($file !== '.' && $file !== '..' && @filemtime($dir . $file) < $before) {
                @unlink($dir . $file);
            }
        }
        closedir($handle);
    }


    /**
     * The key is a file created with fopen(..., 'x'), so of two attempts of
     * one submission only one is processed. Its time is $now: a file older
     * than ANSWER_TTL is free again.
     *
     * {@inheritdoc}
     */
    public function claim($key, $now)
    {
        $file = $this->answerFile($key);
        $dir = dirname($file) . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            call_user_func($this->log, "could not create {$dir}; a retry of a form may process it twice");

            return self::FAILED;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, self::HIDE);
                fclose($handle);
                @touch($file, $now);

                if (mt_rand(1, 100) === 1) {
                    $this->pruneAnswers(sprintf('%02x', mt_rand(0, 255)), $now);
                }

                return self::MARKED;
            }
            $time = @filemtime($file);
            if ($time === false) {
                break;
            }
            if ($time >= $now - self::ANSWER_TTL || $attempt === 1) {
                return self::USED;
            }
            // A submission of more than an hour ago: its key is free again.
            @unlink($file);
        }
        call_user_func($this->log, "could not write to {$dir}; a retry of a form may process it twice");

        return self::FAILED;
    }


    /**
     * A whole file or none: a retry may read it meanwhile.
     *
     * {@inheritdoc}
     */
    public function saveAnswer($key, $json, $now)
    {
        $file = $this->answerFile($key);
        $dir = dirname($file) . '/';
        $temporary = $dir . '.' . $key . '.' . bin2hex(random_bytes(4)) . '.php';
        if (!is_dir($dir) || @file_put_contents($temporary, self::HIDE . $json) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            call_user_func($this->log, "could not write to {$dir}; a retry of a form is answered that it is still processed");

            return false;
        }
        @touch($file, $now);

        return true;
    }


    /**
     * No file, or one older than ANSWER_TTL: null. A file that cannot be
     * read or does not start with HIDE: false, logged once per request.
     *
     * {@inheritdoc}
     */
    public function loadAnswer($key, $now)
    {
        $file = $this->answerFile($key);
        $time = @filemtime($file);
        if ($time === false || $time < $now - self::ANSWER_TTL) {
            return null;
        }
        $content = @file_get_contents($file);
        if ($content === false || strpos($content, self::HIDE) !== 0) {
            if (!$this->unreadableAnswer) {
                $this->unreadableAnswer = true;
                call_user_func($this->log, "the answer in {$file} cannot be read; a retry of a form may process it twice");
            }

            return false;
        }

        return substr($content, strlen(self::HIDE));
    }


    /**
     * The file of the answer to a submission, under
     * core/cache/fetchit/answers/, which "Clear cache" does not touch.
     *
     * @param string $key 64 hex characters
     *
     * @return string
     */
    public function answerFile($key)
    {
        return $this->modx->getCachePath() . 'fetchit/answers/' . substr($key, 0, 2) . '/' . $key . '.php';
    }


    /**
     * Delete the answers of one shard older than ANSWER_TTL.
     *
     * @param string $shard Two hex characters
     * @param int $now
     */
    public function pruneAnswers($shard, $now)
    {
        $dir = $this->modx->getCachePath() . 'fetchit/answers/' . $shard . '/';
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $before = $now - self::ANSWER_TTL;
        while (($file = readdir($handle)) !== false) {
            if ($file !== '.' && $file !== '..' && @filemtime($dir . $file) < $before) {
                @unlink($dir . $file);
            }
        }
        closedir($handle);
    }


    /**
     * One random shard of the marks, one of the forms and one of the answers,
     * so that one run stays small however many there are.
     *
     * {@inheritdoc}
     */
    public function prune($now)
    {
        $this->pruneShard(sprintf('%02x', mt_rand(0, 255)));
        $this->pruneForms(sprintf('%02x', mt_rand(0, 255)), $now);
        $this->pruneAnswers(sprintf('%02x', mt_rand(0, 255)), $now);
    }


    /**
     * Delete the marks older than the token lifetime from one shard.
     *
     * @param string $shard Two hex characters
     */
    public function pruneShard($shard)
    {
        $dir = $this->path() . $shard . '/';
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $before = time() - $this->ttl - 60;
        while (($file = readdir($handle)) !== false) {
            if ($file[0] !== '.' && @filemtime($dir . $file) < $before) {
                @unlink($dir . $file);
            }
        }
        closedir($handle);
    }


    /**
     * {@inheritdoc}
     */
    public function strict()
    {
        return false;
    }


    /**
     * The directory of the marks.
     *
     * @return string
     */
    public function path()
    {
        return $this->modx->getCachePath() . 'fetchit/tokens/';
    }
}
