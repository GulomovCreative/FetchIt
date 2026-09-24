<?php

use PHPUnit\Framework\TestCase;

/**
 * FetchItGuard: the built-in protection against spam that every submission
 * passes before FormIt or the processing snippet runs.
 */
class GuardTest extends TestCase
{
    const ACTION = '0123456789abcdef0123456789abcdef';
    const OTHER = 'fedcba9876543210fedcba9876543210';

    /** @var modX */
    private $modx;

    /** @var FetchIt */
    private $fetchit;

    /** @var int */
    private $now = 1700000000;

    /** @var array */
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->modx = new modX();
        $this->modx->options['fetchit.protection'] = true;
        $this->modx->options['fetchit.protection.min_time'] = 3;
        $this->fetchit = $this->fetchit();
        $_POST = [];
        $_REQUEST = [];
        $handled = new ReflectionProperty(FetchIt::class, 'handled');
        if (PHP_VERSION_ID < 80100) {
            $handled->setAccessible(true);
        }
        $handled->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->modx->removeCache();
        $_POST = [];
        $_REQUEST = [];
        $_SERVER = $this->server;
    }

    private function fetchit()
    {
        $fetchit = new FetchIt($this->modx);
        $fetchit->guard()->setClock(function () {
            return $this->now;
        });

        return $fetchit;
    }

    private function guard()
    {
        return $this->fetchit->guard();
    }

    private function token($action = self::ACTION)
    {
        return $this->guard()->issue($action);
    }

    /**
     * A submission of the form with $token (a new one by default), $seconds
     * after the page was rendered.
     */
    private function submit(array $fields = [], $seconds = 10, $token = null, $action = self::ACTION)
    {
        if ($token === null) {
            $token = $this->token();
        }
        $this->now += $seconds;
        $post = array_merge([FetchItGuard::TOKEN => $token, $this->guard()->trapName() => ''], $fields);
        $_POST = $_REQUEST = $post;

        return $this->guard()->check($action, $post);
    }

    private function assertRefused($reason, $message, $result)
    {
        $this->assertNotNull($result, 'The submission passed');
        $this->assertSame('error', $result['status']);
        $this->assertSame($message, $result['message']);
        $this->assertSame($reason, $this->guard()->reason());
    }

    private function parts($token)
    {
        return explode('.', $token);
    }

    private function assertLogged($needle)
    {
        foreach (array_column($this->modx->logged, 1) as $message) {
            if (strpos($message, $needle) !== false) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("Nothing logged about \"{$needle}\": " . json_encode(array_column($this->modx->logged, 1)));
    }

    // ------------------------------------------------------------ the markup

    public function testFormsGetATokenAndATrap()
    {
        $html = $this->fetchit->prepareForm('<form class="f"><input name="email"></form>', self::ACTION);

        $this->assertMatchesRegularExpression('#<input type="hidden" name="fetchit_token" value="01234567\.\d+\.[0-9a-f]{16}\.[0-9a-f]{64}">#', $html);
        $this->assertStringContainsString('name="' . $this->guard()->trapName() . '"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testTheTrapNameIsNotRecognisableForAutofill()
    {
        $name = $this->guard()->trapName();

        $this->assertMatchesRegularExpression('/^fetchit_[0-9a-f]{8}$/', $name);
        $this->modx->options['fetchit.protection.secret'] = 'another site';
        $this->assertNotSame($name, $this->fetchit()->guard()->trapName(), 'Each installation has its own');
    }

    public function testEveryFormGetsItsOwnToken()
    {
        $html = $this->fetchit->prepareForm('<form></form><form></form>', self::ACTION);

        preg_match_all('#name="fetchit_token" value="([^"]+)"#', $html, $tokens);
        $this->assertCount(2, array_unique($tokens[1]));
    }

    public function testOnlyTheSystemSettingTurnsTheProtectionOff()
    {
        // A snippet call cannot turn it off: action.php would still refuse.
        $fetchit = new FetchIt($this->modx, ['fetchit.protection' => false]);
        $this->assertStringContainsString('fetchit_token', $fetchit->prepareForm('<form></form>', self::ACTION));

        $this->modx->options['fetchit.protection'] = false;
        $this->assertStringNotContainsString('fetchit_token', $this->fetchit()->prepareForm('<form></form>', self::ACTION));
    }

    // ------------------------------------------------------------- the token

    public function testAValidSubmissionPassesWithoutServiceFields()
    {
        $this->assertNull($this->submit(['email' => 'a@b.c']));

        // FormIt reads $_POST itself; the token must not reach the e-mails.
        $this->assertSame(['email' => 'a@b.c'], $_POST);
        $this->assertSame(['email' => 'a@b.c'], $_REQUEST);
    }

    public function testMissingTokenIsRefusedWithoutANextToken()
    {
        // Otherwise action.php would hand a valid token to any POST.
        $_POST = $post = ['email' => 'a@b.c'];

        $this->assertRefused('token', 'fetchit_err_token', $this->guard()->check(self::ACTION, $post));
        $this->assertNull($this->guard()->nextToken());
    }

    public function testBadSignatureIsRefusedWithoutANextToken()
    {
        list($form, $time, $nonce) = $this->parts($this->token());

        $result = $this->submit([], 10, "{$form}.{$time}.{$nonce}." . str_repeat('a', 64));

        $this->assertRefused('token', 'fetchit_err_token', $result);
        $this->assertNull($this->guard()->nextToken());
    }

    public function testTheSignatureCoversTheTime()
    {
        list($form, $time, $nonce, $signature) = $this->parts($this->token());

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $form . '.' . ($time - 60) . ".{$nonce}.{$signature}"));
    }

    public function testTheSignatureCoversTheNonce()
    {
        // A new nonce on an old signature would make a token reusable.
        list($form, $time, , $signature) = $this->parts($this->token());

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, "{$form}.{$time}." . bin2hex(random_bytes(8)) . ".{$signature}"));
    }

    public function testTokenOfAnotherFormIsRefused()
    {
        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $this->token(self::OTHER)));
    }

    public function testATokenWorksOnce()
    {
        $token = $this->token();
        $this->assertNull($this->submit([], 10, $token));

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $token));
    }

    public function testTheUsedMarkSurvivesClearingTheCache()
    {
        $token = $this->token();
        $this->assertNull($this->submit([], 10, $token));

        // "Clear cache" empties the cache partitions, not core/cache/fetchit.
        $this->modx->cacheManager->items = [];

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $token));
    }

    public function testAnUnwritableMarkRefusesAndLogs()
    {
        // Fail closed: a token that cannot be marked could be used again.
        file_put_contents($this->modx->getCachePath() . 'fetchit', 'a file where the directory should be');

        $this->assertRefused('store', 'fetchit_err_store', $this->submit());
        $this->assertLogged('forms are refused until it is writable');
    }

    public function testExpiredTokenIsRefusedWithATokenToSendAtOnce()
    {
        $this->modx->options['fetchit.protection.token_ttl'] = 3600;

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 3601));

        // The visitor took long enough: the next try is not "too fast".
        $this->assertNull($this->submit([], 0, $this->guard()->nextToken()));
    }

    public function testTtlZeroMeansThirtyDays()
    {
        $this->modx->options['fetchit.protection.token_ttl'] = 0;

        $this->assertNull($this->submit([], FetchItGuard::MAX_TTL));
        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], FetchItGuard::MAX_TTL + 1));
    }

    public function testTheSecretSettingSignsTheTokens()
    {
        $token = $this->token();
        $this->modx->options['fetchit.protection.secret'] = 'rotated';

        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $token));
    }

    public function testWithoutTheSettingTheKeyComesFromTheSiteId()
    {
        $token = $this->token();

        $this->modx->site_id = 'modxanotherinstallation.1';
        $this->assertRefused('token', 'fetchit_err_token', $this->submit([], 10, $token));

        $this->modx->site_id = 'modx0123456789abcdef.12345678';
        $this->assertNull($this->submit([], 10, $token));
    }

    public function testRecognisesTheTokensOfItsForm()
    {
        list($form, $time, $nonce) = $this->parts($this->token());

        $this->assertTrue($this->guard()->isFor(self::ACTION, [FetchItGuard::TOKEN => $this->token()]));
        $this->assertTrue($this->guard()->isFor(self::ACTION, [FetchItGuard::TOKEN => "{$form}.{$time}.{$nonce}." . str_repeat('0', 64)]), 'Even badly signed');
        $this->assertFalse($this->guard()->isFor(self::OTHER, [FetchItGuard::TOKEN => $this->token()]));
        $this->assertFalse($this->guard()->isFor(self::ACTION, ['email' => 'a@b.c']));
    }

    // -------------------------------------------------------------- the time

    public function testTooFastIsRefused()
    {
        $this->assertRefused('too_fast', 'fetchit_err_too_fast', $this->submit([], 2));
    }

    public function testTheNextTokenKeepsTheTimeOfTheFirstRender()
    {
        // A visitor fixing a field right after an error must not be "too fast"
        // over and over.
        $this->assertRefused('too_fast', 'fetchit_err_too_fast', $this->submit([], 2));

        $this->assertNull($this->submit([], 1, $this->guard()->nextToken()));
    }

    public function testMinTimeZeroTurnsTheTimeCheckOff()
    {
        $this->modx->options['fetchit.protection.min_time'] = 0;

        $this->assertNull($this->submit([], 0));
    }

    // -------------------------------------------------------------- the trap

    public function testFilledTrapGetsAFakeSuccessAndIsLogged()
    {
        $this->fetchit->storeActionProperties(self::ACTION, ['snippet' => 'FormIt', 'successMessage' => 'Thanks']);

        $result = $this->submit([$this->guard()->trapName() => 'http://spam.example']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Thanks', $result['message']);
        $this->assertLogged('the trap field was filled');
        $this->assertArrayNotHasKey($this->guard()->trapName(), $_POST);
    }

    // -------------------------------------------------------- the rate limit

    public function testRateLimitPerFormAndAddress()
    {
        $this->modx->options['fetchit.protection.rate_limit'] = 2;
        $this->modx->options['fetchit.protection.rate_window'] = 600;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->assertNull($this->submit());
        $this->assertNull($this->submit());
        $this->assertRefused('rate', 'fetchit_err_rate', $this->submit());
        $this->assertLogged('rate limit reached');

        $this->assertNull($this->submit([], 10, $this->token(self::OTHER), self::OTHER), 'Another form has its own limit');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        $this->assertNull($this->submit(), 'Another address has its own limit');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->now += 600;
        $this->assertNull($this->submit(), 'The window has passed');
    }

    public function testTheCounterLivesAsLongAsTheWindow()
    {
        $this->modx->options['fetchit.protection.rate_window'] = 900;

        $this->submit();

        $this->assertContains(900, $this->modx->cacheManager->lifetimes);
    }

    public function testAnUnwritableCounterIsLogged()
    {
        $this->modx->cacheManager->writable = false;

        $this->assertNull($this->submit(), 'Visitors are not refused for it');
        $this->assertLogged('the limit is not enforced');
    }

    public function testBehindATrustedProxyTheClientAddressComesFromTheHeader()
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 10.0.0.9';

        $this->assertSame('10.0.0.5', $this->guard()->clientAddress(), 'No proxy is trusted by default');

        $this->modx->options['fetchit.protection.proxies'] = '10.0.0.0/8';
        $this->assertSame('198.51.100.20', $this->guard()->clientAddress());

        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $this->assertSame('192.0.2.1', $this->guard()->clientAddress(), 'Others cannot forge the header');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $this->modx->options['fetchit.protection.ip_header'] = 'CF-Connecting-IP';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::7';
        $this->assertSame('2001:db8::7', $this->guard()->clientAddress());
    }

    // ---------------------------------------------------------- the plugins

    public function testAPluginCanRefuseASubmission()
    {
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function (array $params, modX $modx) {
            if (strpos($params['fields']['email'], 'spam') !== false) {
                $modx->event->output('Blocked address');
            }
        };

        $this->assertNull($this->submit(['email' => 'ann@example.com']));
        $this->assertRefused('plugin', 'Blocked address', $this->submit(['email' => 'spam@example.com']));

        list($event, $params) = end($this->modx->invoked);
        $this->assertSame('OnFetchItBeforeProcess', $event);
        $this->assertSame(self::ACTION, $params['action']);
        $this->assertSame($this->fetchit, $params['FetchIt']);
        $this->assertArrayNotHasKey(FetchItGuard::TOKEN, $params['fields']);
    }

    public function testReturningTextFromAPluginDoesNotRefuse()
    {
        // As in MODX: only $modx->event->output() counts.
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function () {
            return 'Spam';
        };

        $this->assertNull($this->submit(['email' => 'a@b.c']));
    }

    public function testNonTextPluginOutputIsLoggedNotObeyed()
    {
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function (array $params, modX $modx) {
            $modx->event->output(['email' => 'Blocked']);
        };

        $this->assertNull($this->submit(['email' => 'a@b.c']));
        $this->assertLogged('only a string refuses the submission');
    }

    public function testThePluginEventRunsWithProtectionOff()
    {
        $this->modx->options['fetchit.protection'] = false;
        $fetchit = $this->fetchit();
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function (array $params, modX $modx) {
            $modx->event->output('No');
        };
        $post = ['email' => 'a@b.c'];

        $this->assertSame('No', $fetchit->guard()->check(self::ACTION, $post)['message']);
    }

    public function testProtectionOffLetsSubmissionsWithoutATokenThrough()
    {
        $this->modx->options['fetchit.protection'] = false;
        $post = ['email' => 'a@b.c'];

        $this->assertNull($this->fetchit()->guard()->check(self::ACTION, $post));
    }

    // ------------------------------------------------------------ the log

    public function testLogLevels()
    {
        $this->modx->options['fetchit.protection.log'] = 0;
        $this->submit([$this->guard()->trapName() => 'x']);
        $this->assertSame([], $this->modx->logged, 'Level 0 logs nothing');

        $this->modx->options['fetchit.protection.log'] = 1;
        $this->submit([], 1);
        $this->assertSame([], $this->modx->logged, 'Level 1 leaves out plain refusals');

        $this->modx->options['fetchit.protection.log'] = 2;
        $this->submit([], 1);
        $this->assertLogged('after the page was rendered');
    }

    // ------------------------------------------------ a plain POST to the page

    private function handler()
    {
        $snippet = new FakeSnippet('Handler', function () {
            return '';
        });
        $this->modx->snippets['Handler'] = $snippet;
        $this->fetchit->storeActionProperties(self::ACTION, ['snippet' => 'Handler']);

        return $snippet;
    }

    public function testPagePostWithAValidTokenIsProcessed()
    {
        $snippet = $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->token(), $this->guard()->trapName() => '', 'email' => 'a@b.c'];
        $this->now += 10;

        $this->fetchit->processPage(self::ACTION, []);

        $this->assertSame(['email' => 'a@b.c'], $snippet->received['fields']);
        $this->assertSame(['email' => 'a@b.c'], $_POST);
    }

    public function testPagePostOfAnotherFormOnlyRunsThePreHooks()
    {
        // FormIt runs its preHooks on every page view; a POST without a
        // token of this form must not look like a submission to it.
        $snippet = $this->handler();
        $_POST = ['email' => 'bot@example.com'];

        $this->fetchit->processPage(self::ACTION, []);

        $this->assertSame([], $snippet->received['fields']);
        $this->assertSame([], $snippet->seenPost);
        $this->assertSame(['email' => 'bot@example.com'], $_POST, 'The POST is back for the rest of the page');
    }

    public function testPagePostThatIsRefusedKeepsTheValuesEscaped()
    {
        $snippet = $this->handler();
        $snippet->onProcess = function () {
            // A FormIt preHook that fills in placeholders runs first.
            $this->modx->setPlaceholder('form.name', 'prefilled');
        };
        $_POST = [FetchItGuard::TOKEN => $this->token(), 'name' => '<b>Ann</b>'];
        $this->now += 1;

        $this->fetchit->processPage(self::ACTION, ['placeholderPrefix' => 'form.']);

        $this->assertSame(1, $this->modx->placeholders['form.validation_error']);
        $this->assertSame('fetchit_err_too_fast', $this->modx->placeholders['form.validation_error_message']);
        $this->assertSame('&lt;b&gt;Ann&lt;/b&gt;', $this->modx->placeholders['form.name']);
        $this->assertSame([], $snippet->seenPost, 'FormIt only ran its preHooks');
    }

    public function testPagePostWithABadlySignedTokenOfTheFormIsShownAsExpired()
    {
        // After a change of the key the visitor sees why, and keeps the values.
        $this->handler();
        list($form, $time, $nonce) = $this->parts($this->token());
        $_POST = [FetchItGuard::TOKEN => "{$form}.{$time}.{$nonce}." . str_repeat('0', 64), 'name' => 'Ann'];

        $this->fetchit->processPage(self::ACTION, []);

        $this->assertSame('fetchit_err_token', $this->modx->placeholders['fi.validation_error_message']);
        $this->assertSame('Ann', $this->modx->placeholders['fi.name']);
    }

    public function testPagePostCaughtInTheTrapLooksSent()
    {
        $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->token(), $this->guard()->trapName() => 'x'];
        $this->now += 10;

        $this->fetchit->processPage(self::ACTION, []);

        $this->assertSame(1, $this->modx->placeholders['fi.success']);
        $this->assertSame('fetchit_success_submit', $this->modx->placeholders['fi.successMessage']);
    }

    public function testTheSameFormIsHandledOncePerRequest()
    {
        // Two identical snippet calls share the action: the second must not
        // report the token the first one used as expired.
        $snippet = $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->token(), 'email' => 'a@b.c'];
        $this->now += 10;

        $this->fetchit->processPage(self::ACTION, []);
        $_POST[FetchItGuard::TOKEN] = 'still there for the second call';
        $this->fetchit()->processPage(self::ACTION, []);

        $this->assertArrayNotHasKey('fi.validation_error', $this->modx->placeholders);
        $this->assertSame([], $snippet->received['fields'], 'The second call only ran the preHooks');
    }

    // ------------------------------------------------------- the responses

    public function testAPassedSubmissionGetsTheNextToken()
    {
        $this->assertNull($this->submit());

        $this->assertNull($this->submit([], 0, $this->guard()->nextToken()), 'The page can send again at once');
    }

    public function testFetchItTurnsTheResultIntoAResponse()
    {
        $_POST = $post = ['email' => 'a@b.c'];

        $response = json_decode($this->fetchit->protect(self::ACTION, $post), true);

        $this->assertFalse($response['success']);
        $this->assertSame('fetchit_err_token', $response['message']);
    }
}
