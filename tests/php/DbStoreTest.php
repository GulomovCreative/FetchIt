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

        $this->assertSame('marked', $store->mark(self::NONCE, time() + 3600));
        $this->assertSame('used', $store->mark(self::NONCE, time() + 3600));
        $this->assertSame([], $this->logged);
    }

    /**
     * @dataProvider errorModes
     */
    public function testWithoutTheTableEachOperationFailsAndIsLoggedOnce($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();
        $store->uninstall();

        $this->assertSame('failed', $store->mark(self::NONCE, time() + 3600));
        $this->assertSame('failed', $store->mark(self::NONCE, time() + 3600));
        $this->assertNull($store->hit('k', 600, 1000));
        $this->assertFalse($store->saveForm(self::ACTION, '{}', 1000));
        $this->assertFalse($store->loadForm(self::ACTION));
        $store->prune(1000);

        // The driver message may hold values of the query: only its codes.
        $code = '\(SQLSTATE [0-9A-Z]{5}, code [^)]+\)';
        $this->assertCount(5, $this->logged);
        $this->assertMatchesRegularExpression("#^The table fetchit_test_fetchit_store failed on mark {$code}; forms are refused$#", $this->logged[0]);
        $this->assertMatchesRegularExpression("#^The table fetchit_test_fetchit_store failed on hit {$code}; forms are refused$#", $this->logged[1]);
        $this->assertMatchesRegularExpression("#^The table fetchit_test_fetchit_store failed on saveForm {$code}; the form does not work from a full-page cache$#", $this->logged[2]);
        $this->assertMatchesRegularExpression("#^The table fetchit_test_fetchit_store failed on loadForm {$code}; the form is answered with fetchit_err_store$#", $this->logged[3]);
        $this->assertMatchesRegularExpression("#^The table fetchit_test_fetchit_store failed on prune {$code}$#", $this->logged[4]);
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

            protected function run($sql, array $params, $operation)
            {
                if (!$this->raced && strpos($sql, 'INSERT') === 0) {
                    $this->raced = true;
                    parent::run($sql, [$params[0], $params[1], $params[2]], $operation);
                }

                return parent::run($sql, $params, $operation);
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

    const ACTION = '0123456789abcdef0123456789abcdef';

    /**
     * @dataProvider errorModes
     */
    public function testFormPropertiesAreKeptAndRead($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();

        $this->assertTrue($store->saveForm(self::ACTION, '{"snippet":"FormIt"}', 1000));
        $this->assertTrue($store->saveForm(self::ACTION, '{"snippet":"FormIt"}', 1000), 'A second render is fine');

        $this->assertSame('{"snippet":"FormIt"}', $store->loadForm(self::ACTION));
        $this->assertNull($store->loadForm('fedcba9876543210fedcba9876543210'));
        $this->assertSame([], $this->logged);
    }

    public function testAFormIsKeptAliveDailyAndPrunedAfter90Days()
    {
        $store = $this->store();
        $store->saveForm(self::ACTION, '{}', 1000);
        $store->saveForm(self::ACTION, '{}', 1000 + 86401);

        $store->prune(1000 + FetchItStore::FORM_TTL + 1);
        $this->assertSame('{}', $store->loadForm(self::ACTION), 'Kept alive by the second render');

        $store->prune(1000 + 86401 + FetchItStore::FORM_TTL + 1);
        $this->assertNull($store->loadForm(self::ACTION));
    }

    public function testAFormWithoutTheTableIsNotKept()
    {
        $store = $this->store();
        $store->uninstall();

        $this->assertFalse($store->saveForm(self::ACTION, '{}', 1000));
        $this->assertFalse($store->loadForm(self::ACTION), 'A failure, not a form that is not there');
    }

    /**
     * @dataProvider errorModes
     */
    public function testLongFormPropertiesAreKept($errorMode)
    {
        $this->connect($errorMode);
        $json = json_encode(['text' => str_repeat('a', 60000 - 11)]);
        $this->assertSame(60000, strlen($json));

        $this->assertTrue($this->store()->saveForm(self::ACTION, $json, 1000));

        $this->assertSame($json, $this->store()->loadForm(self::ACTION));
        $this->assertSame([], $this->logged);
    }

    public function testFormPropertiesLongerThanTheTableKeepsAreRefused()
    {
        // TEXT of MySQL, the smallest of the databases, holds 65535 bytes.
        $json = json_encode(['text' => str_repeat('a', 70000 - 11)]);
        $store = $this->store();

        $this->assertFalse($store->saveForm(self::ACTION, $json, 1000));
        $this->assertFalse($store->saveForm(self::ACTION, $json, 1000));

        $this->assertNull($store->loadForm(self::ACTION));
        $this->assertSame(['The properties of form 01234567 are 70000 bytes, more than the table keeps (65535);'
            . ' it does not work from a full-page cache'], $this->logged);
    }

    public function testOneOfManyAtOnceGetsTheMark()
    {
        if (!getenv('FETCHIT_TEST_DSN')) {
            $this->markTestSkipped('Needs a database server: FETCHIT_TEST_DSN');
        }
        $start = microtime(true) + 1;
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $processes[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/store-race.php')
                . ' ' . self::NONCE . ' ' . $start, 'r');
        }
        $results = [];
        foreach ($processes as $process) {
            $results[] = stream_get_contents($process);
            pclose($process);
        }
        sort($results);

        $this->assertSame(['marked', 'used', 'used', 'used'], $results);
    }

    const KEY = 'ab0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    /**
     * @dataProvider errorModes
     */
    public function testASubmissionIsTakenOnceAndItsAnswerKept($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();

        $this->assertSame('marked', $store->claim(self::KEY, 1000));
        $this->assertSame('used', $store->claim(self::KEY, 1000));
        $this->assertSame('', $store->loadAnswer(self::KEY, 1000));
        $this->assertTrue($store->saveAnswer(self::KEY, '{"success":true,"message":"Sent"}', 1000));
        $this->assertSame('{"success":true,"message":"Sent"}', $store->loadAnswer(self::KEY, 1000));
        $this->assertNull($store->loadAnswer(str_repeat('c', 64), 1000));
        $this->assertSame([], $this->logged);
    }

    public function testTwoStoresTakeOneKeyOnce()
    {
        $this->assertSame('marked', $this->store()->claim(self::KEY, 1000));
        $this->assertSame('used', $this->store()->claim(self::KEY, 1000));
    }

    public function testAnAnswerExpiresAfterAnHourAndTheKeyIsFreeAgain()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);
        $store->saveAnswer(self::KEY, '{"success":true}', 1000);
        $later = 1000 + FetchItStore::ANSWER_TTL + 1;

        $this->assertNull($store->loadAnswer(self::KEY, $later));
        $this->assertSame('marked', $store->claim(self::KEY, $later));
        $this->assertSame('', $store->loadAnswer(self::KEY, $later));
    }

    public function testALongAnswerIsKeptWithoutItsData()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);
        $long = json_encode(['success' => true, 'message' => 'Sent', 'data' => ['text' => str_repeat('x', 70000)], 'redirect' => 'https://example.com/']);

        $this->assertTrue($store->saveAnswer(self::KEY, $long, 1000));
        $this->assertSame(['success' => true, 'message' => 'Sent', 'redirect' => 'https://example.com/'], json_decode($store->loadAnswer(self::KEY, 1000), true));
    }

    public function testAnAnswerWithAVeryLongMessageKeepsOnlyItsOutcome()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);

        $store->saveAnswer(self::KEY, json_encode(['success' => false, 'message' => str_repeat('x', 70000)]), 1000);

        $this->assertSame(['success' => false, 'message' => ''], json_decode($store->loadAnswer(self::KEY, 1000), true));
    }

    public function testPruneDropsOldAnswers()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);

        $store->prune(1000 + FetchItStore::ANSWER_TTL + 1);

        $this->assertSame('marked', $store->claim(self::KEY, 1000));
    }

    /**
     * @dataProvider errorModes
     */
    public function testWithoutTheTableTheAnswersFailAndAreLoggedOnce($errorMode)
    {
        $this->connect($errorMode);
        $store = $this->store();
        $store->uninstall();

        $this->assertSame('failed', $store->claim(self::KEY, 1000));
        $this->assertSame('failed', $store->claim(self::KEY, 1000));
        $this->assertFalse($store->saveAnswer(self::KEY, '{}', 1000));
        $this->assertFalse($store->loadAnswer(self::KEY, 1000));
        $this->assertCount(3, $this->logged);
        $this->assertStringContainsString('failed on claim', $this->logged[0]);
    }
}
