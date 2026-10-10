<?php

use PHPUnit\Framework\TestCase;

/**
 * FetchItDbStore: marks, counters and form properties in one table, in SQL
 * that MySQL, MariaDB, SQL Server and SQLite all run. On SQLite in memory by
 * default; CI also runs it on the others through FETCHIT_TEST_DSN.
 */
class DbStoreTest extends TestCase
{
    const NONCE = 'ab12cd34ef56ab78';

    /** @var modX */
    private $modx;

    /** @var string[] */
    private $logged = [];

    protected function setUp(): void
    {
        $this->connect(PDO::ERRMODE_SILENT);
    }

    protected function tearDown(): void
    {
        $this->store()->uninstall();
    }

    private function connect($errorMode)
    {
        $this->modx = fetchit_test_modx($errorMode);
        $this->store()->uninstall();
        $this->assertTrue($this->store()->install());
    }

    private function store()
    {
        return new FetchItDbStore($this->modx, function ($what) {
            $this->logged[] = $what;
        });
    }

    public function errorModes()
    {
        return [
            'silent' => [PDO::ERRMODE_SILENT],
            'exceptions' => [PDO::ERRMODE_EXCEPTION],
        ];
    }

    /**
     * @dataProvider errorModes
     */
    public function testATokenIsMarkedOnce($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();

        $this->assertSame('marked', $store->mark(self::NONCE, 2000000000));
        $this->assertSame('used', $store->mark(self::NONCE, 2000000000));
        $this->assertSame([], $this->logged);
    }

    /**
     * @dataProvider errorModes
     */
    public function testWithoutTheTableEverythingFailsAndIsLoggedOnce($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();
        $store->uninstall();

        $this->assertSame('failed', $store->mark(self::NONCE, 2000000000));
        $this->assertNull($store->hit('k', 600, 1000));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('fetchit_test_fetchit_store', $this->logged[0]);
        $this->assertStringContainsString('SQLSTATE', $this->logged[0]);
        $this->assertStringNotContainsString('INSERT', $this->logged[0], 'No SQL in the log');
    }

    /**
     * @dataProvider errorModes
     */
    public function testTheCounterGrowsAndStartsAgainAfterTheWindow($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();

        $this->assertSame(1, $store->hit('k', 600, 1000));
        $this->assertSame(2, $store->hit('k', 600, 1100));
        $this->assertSame(3, $store->hit('k', 600, 1599));
        $this->assertSame(1, $store->hit('k', 600, 1600), 'A new window');
        $this->assertSame(2, $store->hit('k', 600, 1700), 'counted from 1600');
    }

    public function testCountersAreSeparate()
    {
        $store = $this->store();

        $store->hit('a', 600, 1000);
        $store->hit('a', 600, 1000);

        $this->assertSame(1, $store->hit('b', 600, 1000));
    }

    public function testACounterCreatedMeanwhileIsCountedOn()
    {
        // Another request inserts the counter between this one's UPDATE and
        // INSERT: the INSERT hits a duplicate, and the UPDATE runs again.
        $store = new class($this->modx, function () {}) extends FetchItDbStore {
            public $raced = false;

            protected function run($sql, array $params, $quiet = false)
            {
                if (!$this->raced && strpos($sql, 'INSERT') === 0) {
                    $this->raced = true;
                    parent::run($sql, [$params[0], $params[1], $params[2]]);
                }

                return parent::run($sql, $params, $quiet);
            }
        };

        $this->assertSame(2, $store->hit('k', 600, 1000));
    }

    public function testPruneDropsOnlyWhatExpired()
    {
        // mark() prunes at random against the real clock, so the expiry
        // times are relative to it.
        $now = time();
        $store = $this->store();
        $store->mark('aa00000000000000', $now + 100);
        $store->mark('bb00000000000000', $now + 300);

        $store->prune($now + 200);

        $this->assertSame('marked', $store->mark('aa00000000000000', $now + 100));
        $this->assertSame('used', $store->mark('bb00000000000000', $now + 300));
    }

    public function testInstallTwiceAndUninstall()
    {
        $store = $this->store();

        $this->assertTrue($store->install());
        $this->assertTrue($store->exists());
        $this->assertTrue($store->uninstall());
        $this->assertFalse($store->exists());
        $this->assertTrue($store->uninstall(), 'Nothing to drop is fine');
    }

    public function testItIsStrict()
    {
        $this->assertTrue($this->store()->strict());
    }
}
