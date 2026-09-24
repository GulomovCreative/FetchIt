<?php

use PHPUnit\Framework\TestCase;

/**
 * The optional parts of the protection: the proof of work and the external
 * captchas (FetchItCaptcha, with the HTTP transport replaced).
 */
class PowCaptchaTest extends TestCase
{
    const ACTION = '0123456789abcdef0123456789abcdef';

    /** @var modX */
    private $modx;

    /** @var FetchIt */
    private $fetchit;

    /** @var int */
    private $now = 1700000000;

    /** @var array[] [url, params] of every request to a provider */
    private $requests = [];

    /** @var string|false What the provider answers */
    private $answer = '{"success":true}';

    protected function setUp(): void
    {
        $this->modx = new modX();
        $this->modx->options['fetchit.protection'] = true;
        $this->modx->options['fetchit.protection.min_time'] = 3;
        $this->fetchit = new FetchIt($this->modx);
        $this->fetchit->guard()->setClock(function () {
            return $this->now;
        });
        $this->fetchit->guard()->captcha()->setTransport(function ($url, array $params) {
            $this->requests[] = [$url, $params];

            return $this->answer;
        });
        $_POST = $_REQUEST = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        $this->modx->removeCache();
        $_POST = $_REQUEST = [];
    }

    private function guard()
    {
        return $this->fetchit->guard();
    }

    /**
     * Submit the form 10 s after the render with $fields; the token and the
     * trap are added. $fields may be a callable of the token.
     */
    private function submit($fields = [])
    {
        $token = $this->guard()->issue(self::ACTION);
        $this->now += 10;
        if (is_callable($fields)) {
            $fields = $fields($token);
        }
        $post = array_merge([FetchItGuard::TOKEN => $token], $fields);
        $_POST = $_REQUEST = $post;

        return $this->guard()->check(self::ACTION, $post);
    }

    /**
     * What the script does: count up until the hash has enough zero bits.
     */
    private function solve($token, $bits)
    {
        for ($n = 0; ; $n++) {
            if (FetchItGuard::solves($token, (string)$n, $bits)) {
                return (string)$n;
            }
        }
    }

    // ------------------------------------------------------ proof of work

    public function testSolutionsAreCheckedBitByBit()
    {
        // sha256("abc:0") starts with 0x5f = 0101 1111: one zero bit.
        $this->assertSame('5f', substr(hash('sha256', 'abc:0'), 0, 2));
        $this->assertTrue(FetchItGuard::solves('abc', '0', 1));
        $this->assertFalse(FetchItGuard::solves('abc', '0', 2));

        $solution = $this->solve('abc', 12);
        $this->assertStringStartsWith('000', hash('sha256', 'abc:' . $solution));
        $this->assertFalse(FetchItGuard::solves('abc', '1e3', 1), 'Only digits');
    }

    public function testNoProofOfWorkByDefault()
    {
        $this->assertSame(0, $this->guard()->pow());
        $this->assertNull($this->submit());
    }

    public function testASolvedSubmissionPassesWithoutTheField()
    {
        $this->modx->options['fetchit.protection.pow'] = 10;

        $result = $this->submit(function ($token) {
            return [FetchItGuard::POW => $this->solve($token, 10), 'email' => 'a@b.c'];
        });

        $this->assertNull($result);
        $this->assertSame(['email' => 'a@b.c'], $_POST);
    }

    public function testAMissingOrWrongSolutionIsRefused()
    {
        $this->modx->options['fetchit.protection.pow'] = 10;

        $this->submit();
        $this->assertSame('pow', $this->guard()->reason());

        // A solution of another token does not count.
        $this->submit([FetchItGuard::POW => $this->solve('another token', 10)]);
        $this->assertSame('pow', $this->guard()->reason());
        $this->assertNotNull($this->guard()->nextToken(), 'The script can solve the next one');
    }

    public function difficulties()
    {
        return [
            'too high' => ['64', FetchItGuard::MAX_POW],
            'negative' => ['-4', 0],
            'not a number' => ['hard', 0],
            'a fraction' => ['12.5', 12],
        ];
    }

    /**
     * @dataProvider difficulties
     */
    public function testAnUnusableDifficultyIsLoggedAndClamped($setting, $bits)
    {
        $this->modx->options['fetchit.protection.pow'] = $setting;

        $this->assertSame($bits, $this->guard()->pow());
        $this->guard()->pow();

        $this->assertCount(1, $this->modx->logged, 'Once per request');
        $this->assertStringContainsString("fetchit.protection.pow is \"{$setting}\"", $this->modx->logged[0][1]);
    }

    public function testSolvesTakesAnyNumberOfBits()
    {
        $this->assertTrue(FetchItGuard::solves('abc', '0', 0));
        $this->assertTrue(FetchItGuard::solves('abc', '0', -3));
        $this->assertFalse(FetchItGuard::solves('abc', '0', 300), 'No hash has more than 256 zero bits');
    }

    public function testAFormSentWithoutJavaScriptIsRefusedForTheProofOfWork()
    {
        $this->modx->options['fetchit.protection.pow'] = 8;
        $this->modx->snippets['Handler'] = $snippet = new FakeSnippet('Handler', function () {
            return '';
        });
        $this->fetchit->storeActionProperties(self::ACTION, ['snippet' => 'Handler']);
        $_POST = [FetchItGuard::TOKEN => $this->guard()->issue(self::ACTION), 'name' => 'Ann'];
        $this->now += 10;

        $this->fetchit->processPage(self::ACTION, []);

        $this->assertSame('fetchit_err_pow', $this->modx->placeholders['fi.validation_error_message']);
        $this->assertSame([], $snippet->received['fields'], 'Not processed');
    }

    public function testTheScriptGetsTheDifficulty()
    {
        $this->modx->options['fetchit.protection.pow'] = 16;

        $this->fetchit->loadScript(self::ACTION);

        preg_match('/\.create\((\{.*?\})\);/', $this->modx->htmlBlocks[0], $match);
        $this->assertSame(16, json_decode($match[1], true)['pow']);
    }

    // ------------------------------------------------------------ captchas

    private function captcha($provider)
    {
        $this->modx->options['fetchit.captcha'] = $provider;
        $this->modx->options['fetchit.captcha.site_key'] = 'site-key';
        $this->modx->options['fetchit.captcha.secret_key'] = 'secret-key';
    }

    public function testNoCaptchaWithoutBothKeys()
    {
        $this->modx->options['fetchit.captcha'] = 'turnstile';
        $this->modx->options['fetchit.captcha.site_key'] = 'site-key';

        $this->assertNull($this->guard()->captcha()->provider());
        $this->assertNull($this->submit());
        $this->assertSame([], $this->requests);
    }

    /**
     * The providers with an answer that passes, in the shape each one sends.
     */
    public function providers()
    {
        return [
            'turnstile' => ['turnstile', 'cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'response', 'remoteip',
                '{"success":true,"error-codes":[],"challenge_ts":"2026-09-24T10:00:00.000Z","hostname":"example.com","action":"","cdata":""}'],
            'recaptcha' => ['recaptcha', 'g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify', 'response', 'remoteip',
                '{"success":true,"challenge_ts":"2026-09-24T10:00:00Z","hostname":"example.com","score":0.9,"action":"fetchit"}'],
            'smartcaptcha' => ['smartcaptcha', 'smart-token', 'https://smartcaptcha.yandexcloud.net/validate', 'token', 'ip',
                '{"status":"ok","message":"","host":"example.com"}'],
        ];
    }

    /**
     * @dataProvider providers
     */
    public function testTheAnswerIsCheckedWithTheProvider($provider, $field, $url, $answer, $ip, $passes)
    {
        $this->captcha($provider);
        $this->answer = $passes;

        $this->assertNull($this->submit([$field => 'answer-of-the-widget', 'email' => 'a@b.c']));

        $this->assertSame([[$url, ['secret' => 'secret-key', $answer => 'answer-of-the-widget', $ip => '203.0.113.7']]], $this->requests);
        $this->assertSame(['email' => 'a@b.c'], $_POST, 'The answer does not reach FormIt');
    }

    /**
     * @dataProvider providers
     */
    public function testAMissingAnswerIsRefusedWithoutAsking($provider)
    {
        $this->captcha($provider);

        $this->assertSame('fetchit_err_captcha', $this->submit()['message']);
        $this->assertSame('captcha', $this->guard()->reason());
        $this->assertSame([], $this->requests);
    }

    /**
     * @dataProvider providers
     */
    public function testAnUnreachableProviderRefusesAndLogs($provider, $field)
    {
        // Fail closed: bots would love a captcha that is skipped on errors.
        // The visitor is not told to prove anything, though.
        $this->captcha($provider);
        $this->answer = ['status' => 0, 'body' => null, 'error' => 'curl error 28: Operation timed out'];

        $this->assertSame('fetchit_err_captcha_unavailable', $this->submit([$field => 'x'])['message']);
        $this->assertSame('captcha_unavailable', $this->guard()->reason());
        $this->assertStringContainsString("captcha: {$provider} could not be reached: curl error 28: Operation timed out", end($this->modx->logged)[1]);
    }

    public function outages()
    {
        return [
            'an HTML error page' => ['turnstile', ['status' => 502, 'body' => '<html><body><h1>502 Bad Gateway</h1></body></html>'], 'turnstile answered HTTP 502: 502 Bad Gateway'],
            'not JSON' => ['recaptcha', '<html>maintenance</html>', 'recaptcha answered HTTP 200: maintenance'],
            'a wrong Turnstile secret' => ['turnstile', ['status' => 400, 'body' => '{"success":false,"error-codes":["invalid-input-secret"]}'], 'turnstile answered HTTP 400'],
            'a wrong reCAPTCHA secret' => ['recaptcha', '{"success":false,"error-codes":["invalid-input-secret"]}', 'recaptcha does not accept fetchit.captcha.secret_key'],
            'a wrong SmartCaptcha secret' => ['smartcaptcha', ['status' => 403, 'body' => '{"status":"failed","message":"Authentication failed. Invalid secret."}'], 'Invalid secret'],
        ];
    }

    /**
     * @dataProvider outages
     */
    public function testAProviderThatCannotTellIsAnOutage($provider, $answer, $logged)
    {
        $this->captcha($provider);
        $this->answer = $answer;

        $this->submit([FetchItCaptcha::PROVIDERS[$provider]['field'] => 'x']);

        $this->assertSame('captcha_unavailable', $this->guard()->reason());
        $this->assertStringContainsString($logged, end($this->modx->logged)[1]);
    }

    public function refusals()
    {
        return [
            'turnstile refused' => ['turnstile', '{"success":false,"error-codes":["invalid-input-response"]}'],
            'turnstile, an answer in the shape of SmartCaptcha' => ['turnstile', '{"status":"ok","message":""}'],
            'recaptcha refused' => ['recaptcha', '{"success":false,"error-codes":["timeout-or-duplicate"]}'],
            'recaptcha low score' => ['recaptcha', '{"success":true,"score":0.3,"action":"fetchit"}'],
            'recaptcha other action' => ['recaptcha', '{"success":true,"score":0.9,"action":"login"}'],
            'recaptcha without an action (a v2 key)' => ['recaptcha', '{"success":true,"score":0.9}'],
            'smartcaptcha refused' => ['smartcaptcha', '{"status":"failed","message":"Token invalid"}'],
            'smartcaptcha, an answer in the shape of Turnstile' => ['smartcaptcha', '{"success":true}'],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testRefusalsOfTheProvider($provider, $answer)
    {
        $this->captcha($provider);
        $this->answer = $answer;
        $field = FetchItCaptcha::PROVIDERS[$provider]['field'];

        $this->assertSame('fetchit_err_captcha', $this->submit([$field => 'x'])['message']);
        $this->assertSame('captcha', $this->guard()->reason());
    }

    public function testRefusedAnswersAreLoggedOnlyWithEverything()
    {
        // Every bot that got a token is refused so: the default log keeps
        // them out, so an outage stands out.
        $this->captcha('turnstile');
        $this->answer = '{"success":false,"error-codes":["invalid-input-response"]}';

        $this->submit(['cf-turnstile-response' => 'x']);
        $this->submit();
        $this->assertSame([], $this->modx->logged);

        $this->modx->options['fetchit.protection.log'] = FetchItGuard::LOG_ALL;
        $this->submit(['cf-turnstile-response' => 'x']);
        $this->assertStringContainsString('captcha: turnstile refused: ["invalid-input-response"]', end($this->modx->logged)[1]);
    }

    public function misconfigurations()
    {
        return [
            'a typo' => [['fetchit.captcha' => 'recapcha'], 'fetchit.captcha is "recapcha", not one of turnstile, recaptcha, smartcaptcha'],
            'no secret key' => [['fetchit.captcha.secret_key' => ''], 'fetchit.captcha.site_key or fetchit.captcha.secret_key is empty'],
            'no site key' => [['fetchit.captcha.site_key' => ' '], 'fetchit.captcha.site_key or fetchit.captcha.secret_key is empty'],
        ];
    }

    /**
     * @dataProvider misconfigurations
     */
    public function testACaptchaThatIsNotSetUpIsOffAndLoggedOnce(array $options, $logged)
    {
        $this->captcha('turnstile');
        $this->modx->options = array_merge($this->modx->options, $options);

        $this->assertNull($this->submit());
        $this->fetchit->loadScript(self::ACTION);

        $this->assertSame([], $this->requests);
        $this->assertCount(1, $this->modx->logged);
        $this->assertStringContainsString($logged, $this->modx->logged[0][1]);
    }

    public function testNothingIsLoggedWithoutACaptcha()
    {
        $this->submit();
        $this->fetchit->loadScript(self::ACTION);

        $this->assertSame([], $this->modx->logged);
    }

    public function testTheRecaptchaScoreIsASetting()
    {
        $this->captcha('recaptcha');
        $this->modx->options['fetchit.captcha.min_score'] = 0.2;
        $this->answer = '{"success":true,"score":0.3,"action":"fetchit"}';

        $this->assertNull($this->submit(['g-recaptcha-response' => 'x']));

        // As typed with a Russian keyboard layout.
        $this->modx->options['fetchit.captcha.min_score'] = '0,2';
        $this->assertNull($this->submit(['g-recaptcha-response' => 'x']));
        $this->assertSame([], $this->modx->logged);
    }

    public function unusableScores()
    {
        return ['empty' => [''], 'not a number' => ['high'], 'above 1' => ['5'], 'below 0' => ['-1']];
    }

    /**
     * @dataProvider unusableScores
     */
    public function testAnUnusableScoreIsLoggedAndTheDefaultUsed($setting)
    {
        // An empty setting used to let every score through.
        $this->captcha('recaptcha');
        $this->modx->options['fetchit.captcha.min_score'] = $setting;
        $this->answer = '{"success":true,"score":0.3,"action":"fetchit"}';

        $this->submit(['g-recaptcha-response' => 'x']);

        $this->assertSame('captcha', $this->guard()->reason(), 'Refused below 0.5');
        $this->assertStringContainsString('fetchit.captcha.min_score is', $this->modx->logged[0][1]);
    }

    public function testCaptchaFieldsOfTheSiteReachFormIt()
    {
        // A site may check its own captcha with a FormIt hook: without
        // FetchIt's captcha, or with another one, its field stays.
        $sent = ['g-recaptcha-response' => 'answer', 'smart-token' => 'token', 'email' => 'a@b.c'];

        $this->submit($sent);
        $this->assertSame($sent, $_POST);

        $this->modx->options['fetchit.protection'] = false;
        $this->submit($sent);
        $this->assertSame($sent, $_POST, 'With protection off too');

        $this->modx->options['fetchit.protection'] = true;
        $this->captcha('turnstile');
        $this->answer = '{"success":true}';
        $this->submit($sent + ['cf-turnstile-response' => 'x']);
        $this->assertSame($sent, $_POST, 'Only the field of FetchIt\'s captcha is removed');
    }

    public function testUsedAndExpiredTokensDoNotReachTheProvider()
    {
        // The script sends a captcha answer again after a "token" refusal,
        // so the answer must not have been spent on that refusal.
        $this->captcha('turnstile');
        $this->answer = '{"success":true}';
        $token = $this->guard()->issue(self::ACTION);
        $this->now += 10;
        $post = [FetchItGuard::TOKEN => $token, 'cf-turnstile-response' => 'x'];
        $this->guard()->check(self::ACTION, $post);
        $this->requests = [];

        $post = [FetchItGuard::TOKEN => $token, 'cf-turnstile-response' => 'x'];
        $this->guard()->check(self::ACTION, $post);
        $this->assertSame('token', $this->guard()->reason(), 'Used');

        $post = [FetchItGuard::TOKEN => $this->guard()->issue(self::ACTION, $this->now - FetchItGuard::MAX_TTL - 1), 'cf-turnstile-response' => 'x'];
        $this->guard()->check(self::ACTION, $post);
        $this->assertSame('token', $this->guard()->reason(), 'Expired');

        $this->assertSame([], $this->requests);
    }

    public function testFieldsThatAreArraysAreRefused()
    {
        $this->modx->options['fetchit.protection.pow'] = 8;
        $this->submit([FetchItGuard::POW => ['1']]);
        $this->assertSame('pow', $this->guard()->reason());

        $this->modx->options['fetchit.protection.pow'] = 0;
        $this->captcha('turnstile');
        $this->submit(['cf-turnstile-response' => ['x']]);
        $this->assertSame('captcha', $this->guard()->reason());
        $this->assertSame([], $this->requests);
    }

    public function testTheCaptchaIsAskedLast()
    {
        // No request to the provider for a submission refused anyway.
        $this->captcha('turnstile');
        $post = [FetchItGuard::TOKEN => 'no token', 'cf-turnstile-response' => 'x'];

        $this->guard()->check(self::ACTION, $post);

        $this->assertSame([], $this->requests);
    }

    private function scriptConfig()
    {
        $this->modx->htmlBlocks = [];
        $this->fetchit->loadScript(self::ACTION);
        preg_match('/\.create\((\{.*?\})\);/', $this->modx->htmlBlocks[0], $match);

        return json_decode($match[1], true);
    }

    public function testTheScriptGetsTheProviderAndThePageItsScript()
    {
        $this->captcha('recaptcha');
        $config = $this->scriptConfig();

        $this->assertSame(['provider' => 'recaptcha', 'siteKey' => 'site-key'], $config['captcha']);
        $this->assertSame('fetchit_err_captcha_client', $config['captchaErrorMessage']);
        $this->assertSame('https://www.google.com/recaptcha/api.js?render=site-key', $this->guard()->captcha()->script());
    }

    public function testTheScriptGetsNoCaptchaThatIsOff()
    {
        $this->captcha('turnstile');
        $this->modx->options['fetchit.captcha.site_key'] = '';
        $this->assertNull($this->scriptConfig()['captcha']);

        $this->captcha('turnstile');
        $this->modx->options['fetchit.protection'] = false;
        $this->assertNull($this->scriptConfig()['captcha']);
    }

    private function page()
    {
        $this->modx->options['fetchit.frontend.js'] = '[[+assetsUrl]]js/fetchit.js';
        $this->modx->options['fetchit.frontend.default.notifier'] = false;
        $fetchit = new FetchIt($this->modx);
        $fetchit->loadScript(self::ACTION);
        $this->modx->resource = new FakeResource(1);
        $this->modx->resource->_output = '<html><head><title>T</title></head><body></body></html>';
        $fetchit->registerScript();

        return $this->modx->resource->_output;
    }

    public function testThePageLinksTheScriptOfTheProviderFirst()
    {
        $this->captcha('recaptcha');
        $this->modx->options['fetchit.captcha.site_key'] = 'a&b';

        $this->assertMatchesRegularExpression(
            '#<script src="https://www\.google\.com/recaptcha/api\.js\?render=a%26b" defer></script>\s*<script src="/assets/components/fetchit/js/fetchit\.js\?v=#',
            $this->page()
        );
    }

    public function testThePageLinksNoScriptOfACaptchaThatIsOff()
    {
        $this->captcha('turnstile');
        $this->modx->options['fetchit.protection'] = false;

        $this->assertStringNotContainsString('challenges.cloudflare.com', $this->page());
    }

    public function testTheProtectionSettingTurnsTheCaptchaOffToo()
    {
        $this->captcha('turnstile');
        $this->modx->options['fetchit.protection'] = false;
        $post = ['email' => 'a@b.c'];

        $this->assertNull($this->fetchit->guard()->check(self::ACTION, $post));
        $this->assertSame(0, $this->guard()->pow());
    }
}
