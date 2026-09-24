<?php

use PHPUnit\Framework\TestCase;

/**
 * FetchItGuard: the built-in protection against spam that every submission
 * passes before FormIt or the processing snippet runs.
 */
class GuardTest extends TestCase
{
    /** @var modX */
    private $modx;

    /** @var FetchIt */
    private $fetchit;

    /** @var int */
    private $now = 1700000000;

    protected function setUp(): void
    {
        $this->modx = new modX();
        $this->modx->options['fetchit.protection'] = true;
        $this->modx->options['fetchit.protection.min_time'] = 3;
        $this->fetchit = new FetchIt($this->modx);
        $this->fetchit->guard()->setClock(function () {
            return $this->now;
        });
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
    }

    private function guard()
    {
        return $this->fetchit->guard();
    }

    /**
     * A submission of the form with this action, $seconds after the page
     * was rendered.
     */
    private function submit(array $fields, $seconds = 10, $action = 'abc', $token = null)
    {
        if ($token === null) {
            $token = $this->guard()->issue('abc');
        }
        $this->now += $seconds;
        $post = array_merge([FetchItGuard::TOKEN => $token, FetchItGuard::TRAP => ''], $fields);
        $_POST = $_REQUEST = $post;

        return $this->guard()->check($action, $post);
    }

    private function assertRejected($messageKey, $result)
    {
        $this->assertNotNull($result, 'The submission passed');
        $this->assertSame('error', $result['status']);
        $this->assertSame($messageKey, $result['message']);
    }

    // ------------------------------------------------------------ the markup

    public function testFormsGetATokenAndATrap()
    {
        $html = $this->fetchit->prepareForm('<form class="f"><input name="email"></form>', 'abc');

        $this->assertMatchesRegularExpression('#<input type="hidden" name="fetchit_token" value="\d+\.[0-9a-f]+\.[0-9a-f]+">#', $html);
        $this->assertStringContainsString('name="' . FetchItGuard::TRAP . '"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testEveryFormGetsItsOwnToken()
    {
        $html = $this->fetchit->prepareForm('<form></form><form></form>', 'abc');

        preg_match_all('#name="fetchit_token" value="([^"]+)"#', $html, $tokens);
        $this->assertCount(2, array_unique($tokens[1]));
    }

    public function testNothingIsAddedWithProtectionOff()
    {
        $this->modx->options['fetchit.protection'] = false;
        $fetchit = new FetchIt($this->modx);

        $html = $fetchit->prepareForm('<form></form>', 'abc');

        $this->assertStringNotContainsString('fetchit_token', $html);
    }

    // ------------------------------------------------------------- the token

    public function testAValidSubmissionPassesWithoutServiceFields()
    {
        $result = $this->submit(['email' => 'a@b.c']);

        $this->assertNull($result);
        // FormIt reads $_POST itself; the token must not reach the e-mails.
        $this->assertSame(['email' => 'a@b.c'], $_POST);
        $this->assertSame(['email' => 'a@b.c'], $_REQUEST);
    }

    public function testMissingTokenIsRejected()
    {
        $_POST = $post = ['email' => 'a@b.c'];

        $this->assertRejected('fetchit_err_token', $this->guard()->check('abc', $post));
    }

    public function testTamperedTokenIsRejected()
    {
        list($time, $nonce, $signature) = explode('.', $this->guard()->issue('abc'));

        $result = $this->submit([], 10, 'abc', ($time - 3600) . ".{$nonce}.{$signature}");

        $this->assertRejected('fetchit_err_token', $result);
    }

    public function testTokenOfAnotherFormIsRejected()
    {
        $this->assertRejected('fetchit_err_token', $this->submit([], 10, 'other'));
    }

    public function testExpiredTokenIsRejected()
    {
        $this->modx->options['fetchit.protection.token_ttl'] = 3600;

        $this->assertRejected('fetchit_err_token', $this->submit([], 3601));
    }

    public function testATokenWorksOnce()
    {
        $token = $this->guard()->issue('abc');
        $this->assertNull($this->submit(['email' => 'a@b.c'], 10, 'abc', $token));

        $this->assertRejected('fetchit_err_token', $this->submit(['email' => 'a@b.c'], 10, 'abc', $token));
    }

    public function testASecretOfItsOwnSignsTheTokens()
    {
        $token = $this->guard()->issue('abc');
        $this->modx->options['fetchit.protection.secret'] = 'rotated';

        $this->assertRejected('fetchit_err_token', $this->submit([], 10, 'abc', $token));
    }

    public function testRecognisesItsOwnSubmissions()
    {
        // The snippet only processes page POSTs that carry a token of its form.
        $token = $this->guard()->issue('abc');

        $this->assertTrue($this->guard()->isSubmission('abc', [FetchItGuard::TOKEN => $token]));
        $this->assertFalse($this->guard()->isSubmission('other', [FetchItGuard::TOKEN => $token]));
        $this->assertFalse($this->guard()->isSubmission('abc', ['email' => 'a@b.c']));
    }

    // -------------------------------------------------------------- the time

    public function testTooFastIsRejected()
    {
        $this->assertRejected('fetchit_err_too_fast', $this->submit([], 2));
    }

    public function testMinTimeZeroTurnsTheTimeCheckOff()
    {
        $this->modx->options['fetchit.protection.min_time'] = 0;

        $this->assertNull($this->submit([], 0));
    }

    // -------------------------------------------------------------- the trap

    public function testFilledTrapGetsAFakeSuccess()
    {
        $this->fetchit->storeActionProperties('abc', ['snippet' => 'FormIt', 'successMessage' => 'Thanks']);

        $result = $this->submit([FetchItGuard::TRAP => 'http://spam.example']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Thanks', $result['message']);
    }

    // -------------------------------------------------------- the rate limit

    public function testRateLimitPerFormAndAddress()
    {
        $this->modx->options['fetchit.protection.rate_limit'] = 2;
        $this->modx->options['fetchit.protection.rate_window'] = 600;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->assertNull($this->submit([]));
        $this->assertNull($this->submit([]));
        $this->assertRejected('fetchit_err_rate', $this->submit([]));

        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        $this->assertNull($this->submit([]), 'Another address has its own limit');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->now += 600;
        $this->assertNull($this->submit([]), 'The window has passed');
    }

    // ---------------------------------------------------------- the plugins

    public function testAPluginCanRejectASubmission()
    {
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function (array $params) {
            return strpos($params['fields']['email'], 'spam') !== false ? 'Blocked address' : '';
        };

        $this->assertNull($this->submit(['email' => 'ann@example.com']));
        $result = $this->submit(['email' => 'spam@example.com']);

        $this->assertSame('error', $result['status']);
        $this->assertSame('Blocked address', $result['message']);
        list($event, $params) = end($this->modx->invoked);
        $this->assertSame('OnFetchItBeforeProcess', $event);
        $this->assertSame('abc', $params['action']);
        $this->assertSame($this->fetchit, $params['FetchIt']);
        $this->assertArrayNotHasKey(FetchItGuard::TOKEN, $params['fields']);
    }

    public function testThePluginEventRunsWithProtectionOff()
    {
        $this->modx->options['fetchit.protection'] = false;
        $fetchit = new FetchIt($this->modx);
        $this->modx->plugins['OnFetchItBeforeProcess'][] = function () {
            return 'No';
        };
        $post = ['email' => 'a@b.c'];

        $this->assertSame('No', $fetchit->guard()->check('abc', $post)['message']);
    }

    public function testProtectionOffLetsSubmissionsWithoutATokenThrough()
    {
        $this->modx->options['fetchit.protection'] = false;
        $fetchit = new FetchIt($this->modx);
        $post = ['email' => 'a@b.c'];

        $this->assertNull($fetchit->guard()->check('abc', $post));
    }

    // ------------------------------------------------------- the responses

    public function testRejectionsCarryAFreshToken()
    {
        $result = $this->submit([], 1);

        $this->assertTrue($this->guard()->isSubmission('abc', [FetchItGuard::TOKEN => $result['token']]));
    }

    // ------------------------------------------------ a plain POST to the page

    private function handler()
    {
        $snippet = new FakeSnippet('Handler', function () {
            return '';
        });
        $this->modx->snippets['Handler'] = $snippet;
        $this->fetchit->storeActionProperties('abc', ['snippet' => 'Handler']);

        return $snippet;
    }

    public function testPagePostWithAValidTokenIsProcessed()
    {
        $snippet = $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->guard()->issue('abc'), FetchItGuard::TRAP => '', 'email' => 'a@b.c'];
        $this->now += 10;

        $this->fetchit->processPage('abc', []);

        $this->assertSame(['email' => 'a@b.c'], $snippet->received['fields']);
        $this->assertSame(['email' => 'a@b.c'], $_POST);
    }

    public function testPagePostOfAnotherFormOnlyRunsThePreHooks()
    {
        // FormIt runs its preHooks on every page view; a POST without a
        // token of this form must not look like a submission to it.
        $snippet = $this->handler();
        $_POST = ['email' => 'bot@example.com'];

        $this->fetchit->processPage('abc', []);

        $this->assertSame([], $snippet->received['fields']);
        $this->assertSame([], $snippet->seenPost);
        $this->assertSame(['email' => 'bot@example.com'], $_POST, 'The POST is back for the rest of the page');
    }

    public function testPagePostThatIsRefusedKeepsTheValuesEscaped()
    {
        $snippet = $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->guard()->issue('abc'), 'name' => '<b>Ann</b>'];
        $this->now += 1;

        $this->fetchit->processPage('abc', ['placeholderPrefix' => 'form.']);

        $this->assertSame(1, $this->modx->placeholders['form.validation_error']);
        $this->assertSame('fetchit_err_too_fast', $this->modx->placeholders['form.validation_error_message']);
        $this->assertSame('&lt;b&gt;Ann&lt;/b&gt;', $this->modx->placeholders['form.name']);
        $this->assertSame([], $snippet->seenPost, 'FormIt only ran its preHooks');
    }

    public function testPagePostCaughtInTheTrapLooksSent()
    {
        $this->handler();
        $_POST = [FetchItGuard::TOKEN => $this->guard()->issue('abc'), FetchItGuard::TRAP => 'x'];
        $this->now += 10;

        $this->fetchit->processPage('abc', []);

        $this->assertSame(1, $this->modx->placeholders['fi.success']);
        $this->assertSame('fetchit_success_submit', $this->modx->placeholders['fi.successMessage']);
    }

    public function testFetchItTurnsTheResultIntoAResponse()
    {
        $_POST = $post = ['email' => 'a@b.c'];

        $response = json_decode($this->fetchit->protect('abc', $post), true);

        $this->assertFalse($response['success']);
        $this->assertSame('fetchit_err_token', $response['message']);
    }
}
