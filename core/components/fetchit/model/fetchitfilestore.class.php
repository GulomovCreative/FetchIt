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
    /** @var modX */
    protected $modx;

    /** @var callable Logs a problem, given what went wrong */
    protected $log;

    /** @var int Seconds a mark lives */
    protected $ttl;


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

            return 'failed';
        }

        $handle = @fopen($dir . $nonce, 'x');
        if ($handle === false) {
            if (file_exists($dir . $nonce)) {
                return 'used';
            }
            call_user_func($this->log, "could not write to {$dir}; forms are refused until it is writable");

            return 'failed';
        }
        fclose($handle);

        // Now and then, drop the marks of tokens that expired anyway.
        if (mt_rand(1, 100) === 1) {
            $this->prune(time());
        }

        return 'marked';
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
     * {@inheritdoc}
     */
    public function saveForm($action, $json, $now)
    {
        return false;
    }


    /**
     * {@inheritdoc}
     */
    public function loadForm($action)
    {
        return null;
    }


    /**
     * The marks of one of the 256 shard directories, a random one, so that
     * one run stays small however many marks there are.
     *
     * {@inheritdoc}
     */
    public function prune($now)
    {
        $this->pruneShard(sprintf('%02x', mt_rand(0, 255)));
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
