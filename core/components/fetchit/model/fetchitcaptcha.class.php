<?php

/**
 * Server-side check of an external captcha: Cloudflare Turnstile, Google
 * reCAPTCHA v3 or Yandex SmartCaptcha, chosen with fetchit.captcha and the
 * keys fetchit.captcha.site_key and fetchit.captcha.secret_key.
 *
 * The script of the provider puts its answer into a form field (or FetchIt's
 * script does, for reCAPTCHA v3); verify() sends it to the provider. When the
 * provider cannot be reached, the submission is refused: a captcha that is
 * skipped on errors would be skipped by bots too.
 */
class FetchItCaptcha
{
    /**
     * The providers: the form field with the answer, the verification URL,
     * the names of its parameters, and the script for the page.
     */
    const PROVIDERS = [
        'turnstile' => [
            'field' => 'cf-turnstile-response',
            'url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'secret' => 'secret',
            'answer' => 'response',
            'ip' => 'remoteip',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
        ],
        'recaptcha' => [
            'field' => 'g-recaptcha-response',
            'url' => 'https://www.google.com/recaptcha/api/siteverify',
            'secret' => 'secret',
            'answer' => 'response',
            'ip' => 'remoteip',
            'script' => 'https://www.google.com/recaptcha/api.js?render={siteKey}',
        ],
        'smartcaptcha' => [
            'field' => 'smart-token',
            'url' => 'https://smartcaptcha.yandexcloud.net/validate',
            'secret' => 'secret',
            'answer' => 'token',
            'ip' => 'ip',
            'script' => 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload',
        ],
    ];

    /** The reCAPTCHA v3 action FetchIt's script asks for */
    const RECAPTCHA_ACTION = 'fetchit';

    /** @var modX */
    protected $modx;

    /** @var callable|null Posts ($url, array $params) and returns the body or false; tests replace it */
    protected $transport;


    /**
     * @param modX $modx
     */
    public function __construct($modx)
    {
        $this->modx = $modx;
    }


    /**
     * @param callable $transport
     */
    public function setTransport(callable $transport)
    {
        $this->transport = $transport;
    }


    /**
     * The chosen provider, or null when there is none or it is not set up.
     *
     * @return string|null
     */
    public function provider()
    {
        $provider = strtolower(trim((string)$this->modx->getOption('fetchit.captcha', null, '')));
        if (!isset(self::PROVIDERS[$provider])) {
            return null;
        }
        if ($this->siteKey() === '' || $this->secretKey() === '') {
            return null;
        }

        return $provider;
    }


    /**
     * @return string
     */
    public function siteKey()
    {
        return trim((string)$this->modx->getOption('fetchit.captcha.site_key', null, ''));
    }


    /**
     * @return string
     */
    protected function secretKey()
    {
        return trim((string)$this->modx->getOption('fetchit.captcha.secret_key', null, ''));
    }


    /**
     * The script of the provider for the page, or null.
     *
     * @return string|null
     */
    public function script()
    {
        $provider = $this->provider();

        return $provider === null
            ? null
            : str_replace('{siteKey}', rawurlencode($this->siteKey()), self::PROVIDERS[$provider]['script']);
    }


    /**
     * The form field the answer of the provider comes in, or null.
     *
     * @return string|null
     */
    public function field()
    {
        $provider = $this->provider();

        return $provider === null ? null : self::PROVIDERS[$provider]['field'];
    }


    /**
     * Ask the provider whether the answer is good.
     *
     * @param string $answer
     * @param string $address The client address
     *
     * @return string|null null when it is; otherwise what went wrong, for the log
     */
    public function verify($answer, $address)
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }
        if (!is_string($answer) || trim($answer) === '') {
            return 'no answer of the captcha in the form';
        }

        $spec = self::PROVIDERS[$provider];
        $body = $this->post($spec['url'], [
            $spec['secret'] => $this->secretKey(),
            $spec['answer'] => $answer,
            $spec['ip'] => $address,
        ]);
        if ($body === false || $body === '') {
            return "{$provider} could not be reached";
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            return "{$provider} answered with something that is not JSON";
        }

        switch ($provider) {
            case 'smartcaptcha':
                return isset($result['status']) && $result['status'] === 'ok'
                    ? null
                    : 'smartcaptcha refused: ' . (isset($result['message']) ? $result['message'] : 'status ' . json_encode(isset($result['status']) ? $result['status'] : null));

            case 'recaptcha':
                if (empty($result['success'])) {
                    return 'recaptcha refused: ' . json_encode(isset($result['error-codes']) ? $result['error-codes'] : []);
                }
                $minScore = (float)$this->modx->getOption('fetchit.captcha.min_score', null, 0.5);
                $score = isset($result['score']) ? (float)$result['score'] : 0.0;
                if ($score < $minScore) {
                    return "recaptcha score {$score} is below {$minScore}";
                }
                if (isset($result['action']) && $result['action'] !== self::RECAPTCHA_ACTION) {
                    return "recaptcha answer for another action: {$result['action']}";
                }

                return null;

            default:
                return !empty($result['success'])
                    ? null
                    : 'turnstile refused: ' . json_encode(isset($result['error-codes']) ? $result['error-codes'] : []);
        }
    }


    /**
     * @param string $url
     * @param array $params
     *
     * @return string|false
     */
    protected function post($url, array $params)
    {
        if ($this->transport) {
            return call_user_func($this->transport, $url, $params);
        }

        $body = http_build_query($params);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $result = curl_exec($ch);
            curl_close($ch);

            return $result;
        }

        return @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
        ]]));
    }
}
