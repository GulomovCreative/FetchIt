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

    const ACTION = '0123456789abcdef0123456789abcdef';

    public function testFormPropertiesAreKeptAndRead()
    {
        $store = $this->store();

        $this->assertTrue($store->saveForm(self::ACTION, '{"snippet":"FormIt"}', 1000));

        $this->assertSame('{"snippet":"FormIt"}', $store->loadForm(self::ACTION));
        $this->assertNull($store->loadForm('fedcba9876543210fedcba9876543210'));
    }

    public function testFormPropertiesAreHiddenFromTheWeb()
    {
        // core/ may be inside the webroot on shared hosting, and the action
        // key is in the markup: PHP runs the file and outputs nothing.
        $this->store()->saveForm(self::ACTION, '{"emailTo":"boss@example.com"}', 1000);

        $file = $this->modx->getCachePath() . 'fetchit/forms/01/' . self::ACTION . '.php';
        $this->assertStringStartsWith("<?php exit; ?>\n", file_get_contents($file));
        $this->assertSame('', shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file)) ?? '');
    }

    public function testAFormIsWrittenOnceAndKeptAliveDaily()
    {
        $store = $this->store();
        $file = $this->modx->getCachePath() . 'fetchit/forms/01/' . self::ACTION . '.php';
        $store->saveForm(self::ACTION, '{"a":1}', 1000);
        touch($file, 1000);

        $store->saveForm(self::ACTION, '{"changed":1}', 1000 + 3600);
        clearstatcache();
        $this->assertSame(1000, filemtime($file), 'Not within a day');
        $this->assertSame('{"a":1}', $store->loadForm(self::ACTION), 'Never rewritten: the key is a hash of the content');

        $store->saveForm(self::ACTION, '{"a":1}', 1000 + 86401);
        clearstatcache();
        $this->assertSame(1000 + 86401, filemtime($file));
    }

    public function testAFormFileWithoutTheGuardIsAFailure()
    {
        $dir = $this->modx->getCachePath() . 'fetchit/forms/01/';
        mkdir($dir, 0775, true);
        file_put_contents($dir . self::ACTION . '.php', '{"snippet":"Evil"}');
        $store = $this->store();

        $this->assertFalse($store->loadForm(self::ACTION));
        $this->assertFalse($store->loadForm(self::ACTION));
        $this->assertCount(1, $this->logged, 'Logged once');
        $this->assertStringContainsString('cannot be read', $this->logged[0]);
    }

    public function testAFormFileThatCannotBeReadIsAFailure()
    {
        $this->store()->saveForm(self::ACTION, '{}', 1000);
        chmod($this->store()->formFile(self::ACTION), 0);
        if (is_readable($this->store()->formFile(self::ACTION))) {
            $this->markTestSkipped('Root reads any file');
        }

        $this->assertFalse($this->store()->loadForm(self::ACTION));
        $this->assertStringContainsString('cannot be read', $this->logged[0]);
    }

    public function testNoTemporaryFilesAreLeft()
    {
        $this->store()->saveForm(self::ACTION, '{"a":1}', 1000);

        $this->assertSame([self::ACTION . '.php'], array_values(array_diff(scandir($this->modx->getCachePath() . 'fetchit/forms/01/'), ['.', '..'])));
    }

    public function testFormsNotRenderedFor90DaysArePruned()
    {
        $store = $this->store();
        $old = 'ab' . str_repeat('0', 30);
        $new = 'ab' . str_repeat('1', 30);
        $store->saveForm($old, '{}', 1000);
        $store->saveForm($new, '{}', 1000);
        touch($store->formFile($old), 1000);
        touch($store->formFile($new), 1000 + FetchItStore::FORM_TTL);

        $store->pruneForms('ab', 1000 + FetchItStore::FORM_TTL + 1);

        $this->assertNull($store->loadForm($old));
        $this->assertSame('{}', $store->loadForm($new));
    }

    public function testAFormThatCannotBeWrittenIsLogged()
    {
        file_put_contents($this->modx->getCachePath() . 'fetchit', 'a file where the directory should be');

        $this->assertFalse($this->store()->saveForm(self::ACTION, '{}', 1000));
        $this->assertStringContainsString('could not', $this->logged[0]);
    }

    const KEY = 'ab0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    public function testASubmissionIsTakenOnce()
    {
        $store = $this->store();

        $this->assertSame('marked', $store->claim(self::KEY, 1000));
        $this->assertSame('used', $store->claim(self::KEY, 1000));
        $this->assertSame('', $store->loadAnswer(self::KEY, 1000));
        $this->assertNull($store->loadAnswer(str_repeat('c', 64), 1000));
    }

    public function testAnAnswerIsKeptAndRead()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);

        $this->assertTrue($store->saveAnswer(self::KEY, '{"success":true,"message":"Sent"}', 1000));
        $this->assertSame('{"success":true,"message":"Sent"}', $store->loadAnswer(self::KEY, 1000));
        $this->assertSame('used', $store->claim(self::KEY, 1000));
    }

    public function testAnAnswerIsHiddenFromTheWeb()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);
        $store->saveAnswer(self::KEY, '{"success":true}', 1000);

        $file = $store->answerFile(self::KEY);
        $this->assertStringEndsWith('fetchit/answers/ab/' . self::KEY . '.php', $file);
        $this->assertStringStartsWith(FetchItFileStore::HIDE, file_get_contents($file));
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

    public function testOldAnswersArePruned()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);
        $kept = 'ab' . str_repeat('e', 62);
        $store->claim($kept, 1000 + FetchItStore::ANSWER_TTL);

        $store->pruneAnswers('ab', 1000 + FetchItStore::ANSWER_TTL + 1);

        $this->assertFileDoesNotExist($store->answerFile(self::KEY));
        $this->assertFileExists($store->answerFile($kept));
    }

    public function testAnAnswerThatCannotBeWrittenFails()
    {
        // A file where the directory of the answers should be.
        mkdir($this->modx->getCachePath() . 'fetchit', 0775, true);
        touch($this->modx->getCachePath() . 'fetchit/answers');
        $store = $this->store();

        $this->assertSame('failed', $store->claim(self::KEY, 1000));
        $this->assertFalse($store->saveAnswer(self::KEY, '{}', 1000));
        $this->assertNotEmpty($this->logged);
    }

    public function testAnAnswerFileWithoutTheGuardIsAFailure()
    {
        $store = $this->store();
        $file = $store->answerFile(self::KEY);
        mkdir(dirname($file), 0775, true);
        file_put_contents($file, '{"success":true}');
        touch($file, 1000);

        $this->assertFalse($store->loadAnswer(self::KEY, 1000));
        $this->assertNotEmpty($this->logged);
    }

    public function testAnAnswerFileJustCreatedIsTakenWithoutAnAnswer()
    {
        // claim() creates the file, then writes its guard: a retry may read it in between.
        $store = $this->store();
        $file = $store->answerFile(self::KEY);
        mkdir(dirname($file), 0775, true);
        touch($file, 1000);

        $this->assertSame('', $store->loadAnswer(self::KEY, 1000));
        $this->assertSame([], $this->logged);
    }

    public function testAReleasedKeyIsFreeAgain()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);

        $this->assertTrue($store->release(self::KEY));
        $this->assertNull($store->loadAnswer(self::KEY, 1000));
        $this->assertSame('marked', $store->claim(self::KEY, 1000));
        $this->assertTrue($store->release(str_repeat('c', 64)), 'A free key stays free');
        $this->assertSame([], $this->logged);
    }

    public function testAKeyWithAnAnswerIsNotReleased()
    {
        $store = $this->store();
        $store->claim(self::KEY, 1000);
        $store->saveAnswer(self::KEY, '{"success":true}', 1000);

        $store->release(self::KEY);

        $this->assertSame('{"success":true}', $store->loadAnswer(self::KEY, 1000));
    }
}
