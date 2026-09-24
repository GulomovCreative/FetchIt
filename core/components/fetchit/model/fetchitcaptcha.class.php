<?php

/**
 * Server-side check of an external captcha: Cloudflare Turnstile, Google
 * reCAPTCHA v3 or Yandex SmartCaptcha, chosen with fetchit.captcha and the
 * keys fetchit.captcha.site_key and fetchit.captcha.secret_key.
 *
 * FetchIt's script gets the answer from the provider's script and sends it in
 * the provider's usual field; verify() sends it to the provider. When the
 * provider cannot be reached, the submission is refused: a captcha that is
 * skipped on errors would be skipped by bots too. verify() tells such an
 * outage from a refused answer, so the visitor gets the right message and the
 * log the cause.
 */
class FetchItCaptcha
{
    /**
     * The providers: the form field with the answer, the verification URL,
     * the names of its parameters besides "secret", and the script for the
     * page.
     */
    const PROVIDERS = [
        'turnstile' => [
            'field' => 'cf-turnstile-response',
            'url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'answer' => 'response',
            'ip' => 'remoteip',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
        ],
        'recaptcha' => [
            'field' => 'g-recaptcha-response',
            'url' => 'https://www.google.com/recaptcha/api/siteverify',
            'answer' => 'response',
            'ip' => 'remoteip',
            'script' => 'https://www.google.com/recaptcha/api.js?render={siteKey}',
        ],
        'smartcaptcha' => [
            'field' => 'smart-token',
            'url' => 'https://smartcaptcha.yandexcloud.net/validate',
            'answer' => 'token',
            'ip' => 'ip',
            'script' => 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload',
        ],
    ];

    /** The reCAPTCHA v3 action FetchIt's script asks for */
    const RECAPTCHA_ACTION = 'fetchit';

    /** The minimum reCAPTCHA score when fetchit.captcha.min_score is not usable */
    const MIN_SCORE = 0.5;

    /** Error codes of Turnstile and reCAPTCHA that mean a wrong secret key */
    const SECRET_ERRORS = ['missing-input-secret', 'invalid-input-secret'];

    /** @var modX */
    protected $modx;

    /** @var callable|null Posts ($url, array $params); tests replace it, see post() */
    protected $transport;

    /** @var bool[] The configuration problems logged in this request */
    protected $warned = [];


    /**
     * @param modX $modx
     */
    public function __construct($modx)
    {
        $this->modx = $modx;
    }


    /**
     * @param callable $transport Gets ($url, array $params) and returns the
     *     body, false when there was no answer, or an array like post()'s
     */
    public function setTransport(callable $transport)
    {
        $this->transport = $transport;
    }


    /**
     * The chosen provider, or null when there is none or it is not set up;
     * a setting that names no provider or lacks a key is logged.
     *
     * @return string|null
     */
    public function provider()
    {
        $provider = strtolower(trim((string)$this->modx->getOption('fetchit.captcha', null, '')));
        if ($provider === '') {
            return null;
        }
        if (!isset(self::PROVIDERS[$provider])) {
            $this->warn("fetchit.captcha is \"{$provider}\", not one of " . implode(', ', array_keys(self::PROVIDERS)) . '; the captcha is off');

            return null;
        }
        if ($this->siteKey() === '' || $this->secretKey() === '') {
            $this->warn("fetchit.captcha is {$provider}, but fetchit.captcha.site_key or fetchit.captcha.secret_key is empty; the captcha is off");

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
     * @return array|null null when the answer is good or no captcha is set
     *     up; otherwise problem (what went wrong, for the log) and
     *     unavailable (true when the provider could not tell: it could not
     *     be reached, answered with an error, or the secret key is wrong)
     */
    public function verify($answer, $address)
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }
        if (!is_string($answer) || trim($answer) === '') {
            return $this->refused('no answer of the captcha in the form');
        }

        $spec = self::PROVIDERS[$provider];
        $response = $this->post($spec['url'], [
            'secret' => $this->secretKey(),
            $spec['answer'] => $answer,
            $spec['ip'] => $address,
        ]);
        if ($response['body'] === null || $response['body'] === '') {
            return $this->unavailable("{$provider} could not be reached: "
                . ($response['error'] !== null ? $response['error'] : "HTTP {$response['status']} with no body"));
        }
        $result = json_decode($response['body'], true);
        if ($response['status'] >= 400 || !is_array($result)) {
            return $this->unavailable("{$provider} answered HTTP {$response['status']}: " . $this->excerpt($response['body']));
        }

        switch ($provider) {
            case 'turnstile':
            case 'recaptcha':
                $codes = isset($result['error-codes']) && is_array($result['error-codes']) ? $result['error-codes'] : [];
                if (array_intersect($codes, self::SECRET_ERRORS)) {
                    return $this->unavailable("{$provider} does not accept fetchit.captcha.secret_key: " . json_encode($codes));
                }
                if (empty($result['success'])) {
                    return $this->refused("{$provider} refused: " . json_encode($codes));
                }
                if ($provider === 'turnstile') {
                    return null;
                }
                $minScore = $this->minScore();
                $score = isset($result['score']) ? (float)$result['score'] : 0.0;
                if ($score < $minScore) {
                    return $this->refused("recaptcha score {$score} is below {$minScore}");
                }
                if (!isset($result['action']) || $result['action'] !== self::RECAPTCHA_ACTION) {
                    return $this->refused('recaptcha answer for another action: '
                        . json_encode(isset($result['action']) ? $result['action'] : null) . ' (is the site key for reCAPTCHA v3?)');
                }

                return null;

            case 'smartcaptcha':
                if (isset($result['status']) && $result['status'] === 'ok') {
                    return null;
                }

                return $this->refused('smartcaptcha refused: ' . json_encode($result));

            default:
                return $this->unavailable("no check for the provider {$provider}");
        }
    }


    /**
     * fetchit.captcha.min_score; a value that is not a number from 0 to 1 is
     * logged and replaced with 0.5. A comma is taken for a decimal point.
     *
     * @return float
     */
    protected function minScore()
    {
        $value = str_replace(',', '.', trim((string)$this->modx->getOption('fetchit.captcha.min_score', null, self::MIN_SCORE)));
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 1) {
            $this->warn("fetchit.captcha.min_score is \"{$value}\", not a number from 0 to 1; using " . self::MIN_SCORE);

            return self::MIN_SCORE;
        }

        return (float)$value;
    }


    /**
     * @param string $problem
     *
     * @return array
     */
    protected function refused($problem)
    {
        return ['problem' => $problem, 'unavailable' => false];
    }


    /**
     * @param string $problem
     *
     * @return array
     */
    protected function unavailable($problem)
    {
        return ['problem' => $problem, 'unavailable' => true];
    }


    /**
     * The start of a body for the log, on one line.
     *
     * @param string $body
     *
     * @return string
     */
    protected function excerpt($body)
    {
        $line = trim(preg_replace('/\s+/', ' ', strip_tags($body)));

        return strlen($line) > 200 ? substr($line, 0, 200) . '...' : $line;
    }


    /**
     * Log a configuration problem once per request.
     *
     * @param string $message
     */
    protected function warn($message)
    {
        if (isset($this->warned[$message])) {
            return;
        }
        $this->warned[$message] = true;
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] ' . $message);
    }


    /**
     * @param string $url
     * @param array $params
     *
     * @return array status (0 without an answer), body (null without one)
     *     and error (why there is no answer, or null)
     */
    protected function post($url, array $params)
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $url, $params);
            if (is_array($result)) {
                return $result + ['status' => 200, 'body' => null, 'error' => null];
            }

            return $result === false
                ? ['status' => 0, 'body' => null, 'error' => 'no answer']
                : ['status' => 200, 'body' => (string)$result, 'error' => null];
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
            $response = [
                'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body' => $result === false ? null : (string)$result,
                'error' => $result === false ? 'curl error ' . curl_errno($ch) . ': ' . curl_error($ch) : null,
            ];
            // A no-op since PHP 8.0, deprecated in 8.5.
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            return $response;
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return ['status' => 0, 'body' => null, 'error' => 'neither the curl extension nor allow_url_fopen is available'];
        }
        $result = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
            // The body of an HTTP error too: it tells what is wrong.
            'ignore_errors' => true,
        ]]));
        if ($result === false) {
            $error = error_get_last();

            return ['status' => 0, 'body' => null, 'error' => $error ? $error['message'] : 'file_get_contents failed'];
        }
        $status = 0;
        // Before PHP 8.4 file_get_contents() sets $http_response_header in
        // this scope; PHP 8.5 deprecates using that variable by name.
        $legacy = 'http_response_header';
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : (isset($$legacy) ? $$legacy : []);
        if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $match)) {
            $status = (int)$match[1];
        }

        return ['status' => $status, 'body' => $result, 'error' => null];
    }
}
