<?php

use PHPUnit\Framework\TestCase;

/**
 * FetchItFileStore: the marks of used tokens in files, the rate counters in
 * the MODX cache, as FetchIt 4.0 kept them.
 */
class FileStoreTest extends TestCase
{
    const NONCE = 'ab12cd34ef56ab78';

    /** @var modX */
    private $modx;

    /** @var string[] */
    private $logged = [];

    protected function setUp(): void
    {
        $this->modx = new modX();
    }

    protected function tearDown(): void
    {
        $this->modx->removeCache();
    }

    private function store($ttl = 86400)
    {
        return new FetchItFileStore($this->modx, function ($what) {
            $this->logged[] = $what;
        }, $ttl);
    }

    public function testATokenIsMarkedOnce()
    {
        $store = $this->store();

        $this->assertSame('marked', $store->mark(self::NONCE, 0));
        $this->assertSame('used', $store->mark(self::NONCE, 0));
    }

    public function testAMarkOfFetchIt40IsHonoured()
    {
        // Marks written before the upgrade keep their tokens used.
        $dir = $this->modx->getCachePath() . 'fetchit/tokens/ab/';
        mkdir($dir, 0775, true);
        touch($dir . self::NONCE);

        $this->assertSame('used', $this->store()->mark(self::NONCE, 0));
    }

    public function testAMarkThatCannotBeWrittenFails()
    {
        file_put_contents($this->modx->getCachePath() . 'fetchit', 'a file where the directory should be');

        $this->assertSame('failed', $this->store()->mark(self::NONCE, 0));
        $this->assertStringContainsString('forms are refused until it is writable', $this->logged[0]);
    }

    public function testTheCounterGrowsAndStartsAgainAfterTheWindow()
    {
        $store = $this->store();

        $this->assertSame(1, $store->hit('k', 600, 1000));
        $this->assertSame(2, $store->hit('k', 600, 1599));
        $this->assertSame(1, $store->hit('k', 600, 1600));
        $this->assertSame(600, $this->modx->cacheManager->lifetimes['fetchit/rate/k']);
    }

    public function testACounterThatCannotBeStoredGivesNothing()
    {
        $this->modx->cacheManager->writable = false;

        $this->assertNull($this->store()->hit('k', 600, 1000));
    }

    public function testPruneDropsTheOldMarksOfOneShard()
    {
        $dir = $this->modx->getCachePath() . 'fetchit/tokens/ab/';
        mkdir($dir, 0775, true);
        touch($dir . 'ab00000000000old', time() - 86400 - 120);
        touch($dir . 'ab00000000000new');

        $this->store()->pruneShard('ab');

        $this->assertFileDoesNotExist($dir . 'ab00000000000old');
        $this->assertFileExists($dir . 'ab00000000000new');
    }

    public function testItIsNotStrict()
    {
        $this->assertFalse($this->store()->strict());
    }
}
