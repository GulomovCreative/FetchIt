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

    public function testTheDifficultyIsCapped()
    {
        $this->modx->options['fetchit.protection.pow'] = 64;

        $this->assertSame(FetchItGuard::MAX_POW, $this->guard()->pow());
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

    public function providers()
    {
        return [
            ['turnstile', 'cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'response', 'remoteip'],
            ['recaptcha', 'g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify', 'response', 'remoteip'],
            ['smartcaptcha', 'smart-token', 'https://smartcaptcha.yandexcloud.net/validate', 'token', 'ip'],
        ];
    }

    /**
     * @dataProvider providers
     */
    public function testTheAnswerIsCheckedWithTheProvider($provider, $field, $url, $answer, $ip)
    {
        $this->captcha($provider);
        $this->answer = json_encode(['success' => true, 'status' => 'ok', 'score' => 0.9, 'action' => 'fetchit']);

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
        $this->captcha($provider);
        $this->answer = false;

        $this->assertSame('captcha', $this->submit([$field => 'x']) === null ? null : $this->guard()->reason());
        $this->assertStringContainsString('could not be reached', end($this->modx->logged)[1]);
    }

    public function refusals()
    {
        return [
            'turnstile refused' => ['turnstile', '{"success":false,"error-codes":["invalid-input-response"]}'],
            'recaptcha refused' => ['recaptcha', '{"success":false}'],
            'recaptcha low score' => ['recaptcha', '{"success":true,"score":0.3,"action":"fetchit"}'],
            'recaptcha other action' => ['recaptcha', '{"success":true,"score":0.9,"action":"login"}'],
            'smartcaptcha refused' => ['smartcaptcha', '{"status":"failed","message":"Token invalid"}'],
            'not JSON' => ['turnstile', '<html>Bad gateway</html>'],
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

        $this->assertNotNull($this->submit([$field => 'x']));
        $this->assertSame('captcha', $this->guard()->reason());
    }

    public function testTheRecaptchaScoreIsASetting()
    {
        $this->captcha('recaptcha');
        $this->modx->options['fetchit.captcha.min_score'] = 0.2;
        $this->answer = '{"success":true,"score":0.3,"action":"fetchit"}';

        $this->assertNull($this->submit(['g-recaptcha-response' => 'x']));
    }

    public function testTheCaptchaIsAskedLast()
    {
        // No request to the provider for a submission refused anyway.
        $this->captcha('turnstile');
        $post = [FetchItGuard::TOKEN => 'no token', 'cf-turnstile-response' => 'x'];

        $this->guard()->check(self::ACTION, $post);

        $this->assertSame([], $this->requests);
    }

    public function testTheScriptGetsTheProviderAndThePageItsScript()
    {
        $this->captcha('recaptcha');
        $this->fetchit->loadScript(self::ACTION);
        preg_match('/\.create\((\{.*?\})\);/', $this->modx->htmlBlocks[0], $match);

        $this->assertSame(['provider' => 'recaptcha', 'siteKey' => 'site-key'], json_decode($match[1], true)['captcha']);
        $this->assertSame('https://www.google.com/recaptcha/api.js?render=site-key', $this->guard()->captcha()->script());
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
