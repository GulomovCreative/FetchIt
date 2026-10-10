<?php

require_once dirname(__FILE__) . '/fetchitstore.class.php';
require_once dirname(__FILE__) . '/fetchitfilestore.class.php';
require_once dirname(__FILE__) . '/fetchitdbstore.class.php';
require_once dirname(__FILE__) . '/fetchitguard.class.php';
require_once dirname(__FILE__) . '/fetchitcaptcha.class.php';

class FetchIt
{
    /**
     * What FetchItScript outputs. A cached call keeps it in the cached page,
     * so the plugin finds it on every view, and takes it out.
     */
    const SCRIPT_MARKER = '<!--FetchItScript-->';

    public $version = '4.0.0';
    /** @var modX $modx */
    public $modx;
    /** @var array $config */
    public $config;

    /** @var FetchItGuard|null */
    protected $guard;

    /**
     * Whether the last loadActionProperties() found no properties because
     * the store of fetchit.page_cache failed: process() then answers
     * fetchit_err_store instead of "form not found".
     *
     * @var bool
     */
    protected $storeFailed = false;

    /** @var bool[] The actions whose stored properties were logged as not valid JSON */
    protected $invalidJson = [];

    /**
     * Set by loadScript() when the FetchIt snippet puts a form on the page,
     * or FetchItScript asks for the script of forms that come later, so the
     * plugin adds the scripts at OnWebPagePrerender of the same request.
     * Kept per request, not in the session, which may not exist. Only an
     * uncached call sets it on every page view; a cached FetchItScript is
     * found by its SCRIPT_MARKER.
     *
     * @var bool
     */
    protected static $scriptRequested = false;

    /**
     * The actions whose page POST this request has handled already.
     *
     * @var bool[]
     */
    protected static $handled = [];

    /**
     * What submit() is processing, for finishRedirect(): action, key (of
     * the kept answer, or null) and fields. Null outside processing.
     *
     * @var array|null
     */
    protected $processing;

    /** @var bool Whether finishRedirect() is registered in this request */
    protected static $catching = false;


    /**
     * The shared instance, the one custom snippets should use. On MODX 3 it
     * is "FetchIt" in the service container (bootstrap.php registers it, or
     * this method does), also found under "fetchit", the name FetchIt 1.x
     * code passes to getService(). On MODX 2 it is getService('fetchit').
     *
     * @param modX $modx
     *
     * @return FetchIt
     */
    public static function service($modx)
    {
        $container = self::container($modx);
        if ($container === null) {
            return $modx->getService('fetchit', 'FetchIt', dirname(__FILE__) . '/');
        }

        self::register($modx);

        return $container->get('FetchIt');
    }


    /**
     * Register the shared instance in the MODX 3 service container as
     * "FetchIt" (FetchIt 3.x) and "fetchit" (getService() calls of 1.x code;
     * the container keys are case-sensitive).
     *
     * @param modX $modx
     */
    public static function register($modx)
    {
        $container = self::container($modx);
        if (!$container->has('FetchIt')) {
            $container->add('FetchIt', function () use ($modx) {
                return new FetchIt($modx);
            });
        }
        if (!$container->has('fetchit')) {
            $container->add('fetchit', function () use ($container) {
                return $container->get('FetchIt');
            });
        }
    }


    /**
     * pdoTools, if it is installed: pdoTools 3 on MODX 3 is only a service in
     * the container, pdoTools 2 on MODX 2 is the pdoTools class.
     *
     * @param modX $modx
     *
     * @return object|null
     */
    public static function pdoTools($modx)
    {
        $container = self::container($modx);
        if ($container !== null) {
            return $container->has('pdoTools') ? $container->get('pdoTools') : null;
        }

        return class_exists('pdoTools') ? $modx->getService('pdoTools') : null;
    }


    /**
     * The MODX 3 service container, or null on MODX 2, where
     * $modx->services is a plain array.
     *
     * @param modX $modx
     *
     * @return object|null
     */
    protected static function container($modx)
    {
        return isset($modx->services) && is_object($modx->services) ? $modx->services : null;
    }


    /**
     * @param modX $modx
     * @param array $config
     */
    function __construct(modX &$modx, array $config = array())
    {
        $this->modx =& $modx;

        $corePath = $this->modx->getOption('fetchit.core_path', $config,
            $this->modx->getOption('core_path') . 'components/fetchit/');
        $assetsPath = $this->modx->getOption('fetchit.assets_path', $config,
            $this->modx->getOption('assets_path') . 'components/fetchit/');
        $assetsUrl = $this->modx->getOption('fetchit.assets_url', $config,
            $this->modx->getOption('assets_url') . 'components/fetchit/');
        $frontend_js = $this->modx->getOption('fetchit.frontend.js', null,
            '[[+assetsUrl]]js/fetchit.js');
        $default_notifier = (bool)$this->modx->getOption('fetchit.frontend.default.notifier', null,
            true, false);

        $this->modx->lexicon->load('fetchit:default');

        $this->config = array_merge(array(
            'assetsUrl' => $assetsUrl,
            'actionUrl' => $assetsUrl . 'action.php',

            'json_response' => true,

            'corePath' => $corePath,
            'assetsPath' => $assetsPath,

            'frontend_js' => $frontend_js,

            'default_notifier' => $default_notifier,
        ), $config);
    }


    /**
     * Give every form in the chunk the POST method, the action key and the
     * config of the script.
     *
     * The form tag loses any method, data-fetchit and data-fetchit-config of
     * its own, and gets method="post", data-fetchit="$action" and
     * data-fetchit-config (see clientConfig()) as its last attributes. The
     * rest of the tag and everything inside the form stay as they are, apart
     * from the service fields of the protection right after the tag.
     *
     * @param string $html
     * @param string $action
     *
     * @return string
     */
    public function prepareForm($html, $action)
    {
        // A quoted value may hold ">" (Alpine, Vue, inline handlers).
        $tag = '#<form(?=[\s>/])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i';
        // One attribute with an optional value; values are taken whole, so
        // "method=" inside another attribute's value is never matched.
        $attribute = '#(\s+)([^\s"\'>/=]+)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?#';

        // Brackets and braces as entities: the page may go through the MODX
        // parser and Fenom again, which would take them for tags.
        $config = strtr(htmlspecialchars($this->encode($this->clientConfig($action), "the config of the form {$action}"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), [
            '[' => '&#91;',
            ']' => '&#93;',
            '{' => '&#123;',
            '}' => '&#125;',
        ]);

        $result = preg_replace_callback($tag, function ($match) use ($action, $attribute, $config) {
            /** @var array $match */
            $attributes = preg_replace_callback($attribute, function ($pair) {
                return in_array(strtolower($pair[2]), ['method', 'data-fetchit', 'data-fetchit-config'], true) ? '' : $pair[0];
            }, $match[1]);
            if ($attributes === null) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not read the attributes of a form: ' . $this->pcreError());
                $attributes = $match[1];
            }

            return substr($match[0], 0, 5) . rtrim($attributes)
                . ' method="post" data-fetchit="' . htmlspecialchars($action, ENT_QUOTES) . '"'
                . ' data-fetchit-config="' . $config . '">'
                . $this->guard()->fields($action);
        }, $html);

        if ($result === null) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not prepare the form: ' . $this->pcreError());

            return $html;
        }

        return $result;
    }


    /**
     * The protection against spam.
     *
     * @return FetchItGuard
     */
    public function guard()
    {
        if ($this->guard === null) {
            $this->guard = new FetchItGuard($this);
        }

        return $this->guard;
    }


    /**
     * Check a submission of the form with this action (see FetchItGuard):
     * null to process it, or the response to send instead. The service
     * fields are removed from $post, $_POST and $_REQUEST.
     *
     * @param string $action
     * @param array $post
     *
     * @return array|string|null
     */
    public function protect($action, array &$post)
    {
        $refused = $this->guard()->check($action, $post);
        if ($refused === null) {
            return null;
        }

        $method = $refused['status'] === 'success' ? 'success' : 'error';

        return $this->$method($refused['message']);
    }


    /**
     * Answer a submission sent to action.php: the protection, then the
     * processing. With the X-FetchIt-Submission UUID of the script
     * (config.retries) the answer is kept for an hour: a retry of the same
     * submission gets it again, and one sent while the first attempt is still
     * processed is answered pending, so the form is never processed twice. A
     * redirect sent by the processing becomes an answer with redirect
     * (finishRedirect()).
     *
     * @param string $action
     * @param array $post The POST of the form; its service fields are removed
     * @param mixed $submission The X-FetchIt-Submission header, '' when none
     * @param callable|null $header Sends a header line; header() by default.
     *     Headers go out before the processing, which may end the request.
     *
     * @return array status (HTTP) and body (JSON)
     */
    public function submit($action, array $post, $submission = '', $header = null)
    {
        $header = $header ?: 'header';
        $guard = $this->guard();
        $key = $guard->submissionKey($action, $submission);

        if ($key !== null) {
            $kept = $guard->loadAnswer($key);
            if (is_string($kept)) {
                if ($next = $guard->renew($action, $post)) {
                    call_user_func($header, 'X-FetchIt-Token: ' . $next);
                }

                return $kept !== '' ? ['status' => 200, 'body' => $kept] : $this->pending($header);
            }
        }

        $refused = $this->protect($action, $post);
        // Every token is single-use: the script sends this one next time. Only a
        // request with a well-signed token of the form gets one.
        if ($next = $guard->nextToken()) {
            call_user_func($header, 'X-FetchIt-Token: ' . $next);
        }
        // The script retries once by itself when the page's token was stale, or
        // when the page asked for no proof of work or a smaller one (a page from
        // a cache made before it was turned on or raised).
        if ($reason = $guard->reason()) {
            call_user_func($header, 'X-FetchIt-Refused: ' . $reason);
            if ($reason === 'pow') {
                call_user_func($header, 'X-FetchIt-Pow: ' . $guard->pow());
            }
            // With fetchit.page_cache the next token of a used or expired one is
            // timed now: the script waits this long before it sends it.
            if ($reason === 'token' && $guard->freshToken()) {
                call_user_func($header, 'X-FetchIt-Min-Time: ' . $guard->minTime());
            }
        }
        if ($refused !== null) {
            return ['status' => 200, 'body' => $this->json($refused)];
        }

        if ($key !== null) {
            $claim = $guard->claim($key);
            if ($claim === FetchItStore::USED) {
                return $this->pending($header);
            }
            if ($claim === FetchItStore::FAILED) {
                // Logged by the store: better a possible second run than a lost form.
                $key = null;
            }
        }

        $fields = array_merge($_FILES, $post);
        $this->processing = ['action' => $action, 'key' => $key, 'fields' => $fields];
        $this->catchRedirect();
        $response = $this->process($action, $fields);
        $this->processing = null;

        $body = $this->withRedirect($action, $this->json($response));
        if ($key !== null) {
            $guard->saveAnswer($key, $body);
        }

        return ['status' => 200, 'body' => $body];
    }


    /**
     * The answer to a retry that came while the first attempt of the
     * submission is still processed: the script waits and sends it again.
     *
     * @param callable $header
     *
     * @return array
     */
    protected function pending($header)
    {
        call_user_func($header, 'X-FetchIt-Refused: pending');

        return ['status' => 409, 'body' => $this->json($this->error('fetchit_err_pending'))];
    }


    /**
     * Register finishRedirect() once per request.
     */
    protected function catchRedirect()
    {
        if (!self::$catching) {
            self::$catching = true;
            register_shutdown_function([$this, 'finishRedirect']);
        }
    }


    /**
     * $modx->sendRedirect() during the processing (the redirect hook of
     * FormIt, or a snippet of the site) sends a Location header and ends the
     * request: fetch() would follow it to a page of HTML, and the visitor
     * would read that the form failed, though it was processed. At the end
     * of such a request this turns the redirect into a FetchIt answer with
     * redirect, kept for the retries as any other.
     */
    public function finishRedirect()
    {
        if ($this->processing === null) {
            return;
        }
        $processing = $this->processing;
        $this->processing = null;
        $location = null;
        foreach (headers_list() as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
        }
        if ($location === null || $location === '') {
            return;
        }
        if (headers_sent()) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] The processing of form ' . substr($processing['action'], 0, 8)
                . ' sent a redirect after its output began; the script gets HTML instead of an answer');

            return;
        }

        header_remove('Location');
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $body = $this->redirectAnswer($processing['action'], $location, $processing['fields']);
        if ($processing['key'] !== null) {
            $this->guard()->saveAnswer($processing['key'], $body);
        }
        echo $body;
    }


    /**
     * The snippet's run of FormIt or the processing snippet when the page
     * renders:
     * - without a POST it runs as before (FormIt preHooks, e.g. prefilled
     *   values);
     * - with the protection off, a POST is checked by the plugins only and
     *   processed;
     * - with the protection on, a POST without a token of this form (another
     *   form's, or a bot's) is hidden from FormIt, which only runs its
     *   preHooks; a POST with a token of this form (a form sent without
     *   JavaScript) is processed when it passes the protection, and
     *   otherwise the refusal and the values sent are shown through the
     *   FormIt placeholders of the form.
     * A form is handled once per request, even when the snippet is called
     * twice with the same properties.
     *
     * @param string $action
     * @param array $properties The snippet properties
     */
    public function processPage($action, array $properties)
    {
        if (empty($_POST)) {
            $this->process($action, []);

            return;
        }

        $guarded = $this->guard()->enabled();
        if (isset(self::$handled[$action]) || ($guarded && !$this->guard()->isFor($action, $_POST))) {
            $this->processWithoutPost($action);

            return;
        }
        self::$handled[$action] = true;

        $post = $_POST;
        $refused = $this->guard()->check($action, $post);
        if ($refused === null) {
            $this->process($action, $post);

            return;
        }

        // FormIt preHooks run first, so they do not overwrite the refusal.
        $this->processWithoutPost($action);

        $prefix = isset($properties['placeholderPrefix']) ? $properties['placeholderPrefix'] : 'fi.';
        $message = $this->modx->lexicon($refused['message']);
        if ($refused['status'] === 'success') {
            $this->modx->setPlaceholder($prefix . 'success', 1);
            $this->modx->setPlaceholder($prefix . 'successMessage', $message);

            return;
        }
        $this->modx->setPlaceholder($prefix . 'validation_error', 1);
        $this->modx->setPlaceholder($prefix . 'validation_error_message', htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        foreach ($post as $field => $value) {
            if (is_string($value)) {
                $this->modx->setPlaceholder($prefix . $field, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            }
        }
    }


    /**
     * Run FormIt or the processing snippet with no fields and the POST
     * hidden: FormIt then only runs its preHooks.
     *
     * @param string $action
     */
    protected function processWithoutPost($action)
    {
        $post = $_POST;
        $request = $_REQUEST;
        $_POST = [];
        $_REQUEST = array_diff_key($_REQUEST, $post);
        try {
            $this->process($action, []);
        } finally {
            $_POST = $post;
            $_REQUEST = $request;
        }
    }


    /**
     * Add ?v=<version> to a URL, keeping its query string and fragment.
     *
     * @param string $url
     *
     * @return string
     */
    protected function withVersion($url)
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $this->version . $fragment;
    }


    /**
     * @return string
     */
    protected function pcreError()
    {
        return function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'PCRE error ' . preg_last_error();
    }


    /**
     * The config of the script for the forms of an action. prepareForm()
     * writes it into data-fetchit-config of every form, so a form inserted
     * after the page loaded brings it along. pageId is the resource that
     * outputs the form: for a fragment loaded into another page, the
     * fragment; action.php processes the form in its context.
     *
     * @param string $action
     *
     * @return array
     */
    public function clientConfig($action)
    {
        $captcha = $this->guard()->activeCaptcha();

        $config = [
            'action' => $action,
            'assetsUrl' => $this->config['assetsUrl'],
            'actionUrl' => str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $this->config['actionUrl']),
            'inputInvalidClass' => trim(preg_replace('/\s+/', ' ', (string)$this->modx->getOption('fetchit.frontend.input.invalid.class'))),
            'customInvalidClass' => trim(preg_replace('/\s+/', ' ', (string)$this->modx->getOption('fetchit.frontend.custom.invalid.class'))),
            'clearFieldsOnSuccess' => (bool)$this->modx->getOption('clearFieldsOnSuccess', $this->config, 1, false),
            'formState' => (bool)$this->modx->getOption('formState', $this->config, 1, false),
            'ariaBusy' => (bool)$this->modx->getOption('ariaBusy', $this->config, 1, false),
            'defaultNotifier' => $this->config['default_notifier'],
            'notifierCloseLabel' => $this->config['default_notifier'] ? $this->lexiconOrEmpty('fetchit_notifier_close') : '',
            'requestErrorMessage' => $this->modx->lexicon('fetchit_err_request'),
            'pow' => $this->guard()->pow(),
            'captcha' => $captcha !== null ? ['provider' => $captcha['provider'], 'siteKey' => $captcha['siteKey']] : null,
            'captchaErrorMessage' => $captcha !== null ? $this->modx->lexicon('fetchit_err_captcha_client') : '',
            'pageId' => !empty($this->modx->resource)
                ? $this->modx->resource->get('id')
                : 0,
        ];

        if ($this->guard()->freshToken()) {
            $config['freshToken'] = true;
        }

        return $config;
    }


    /**
     * Ask for the script of the forms: the plugin adds it at
     * OnWebPagePrerender of this request. The forms carry their config
     * (prepareForm()), so nothing else is needed per form.
     *
     * @param string $action Kept for compatibility; the config is in the form
     */
    public function loadScript($action = '')
    {
        self::$scriptRequested = true;
    }


    /**
     * JSON for the script. Text that is not UTF-8 (a lexicon entry or a
     * setting saved in another encoding) gets replacement characters
     * instead of breaking all of it; what cannot be encoded at all is
     * logged, and '' is returned.
     *
     * @param mixed $data
     * @param string $what
     *
     * @return string
     */
    protected function encode($data, $what)
    {
        // "<" and ">" as \u003C and \u003E: text such as "<!--<script" would
        // keep the HTML parser from ending the inline script.
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
        if (!is_string($json)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] Could not encode {$what}: " . json_last_error_msg() . '; the forms are sent without FetchIt');

            return '';
        }

        return $json;
    }


    /**
     * The inline code that starts the forms of the page once the deferred
     * scripts have run: tests/fixtures/bootstrap.html is its output for the
     * class name Forms. The class may be a window property, a top-level
     * "class" declaration (no window property) or a dotted name. Without
     * the script (blocked, failed, a misspelt class name) the forms fall
     * back to a normal submit and the console says what to check. A script
     * of the site without observe(), or a class of the site with a create()
     * of its own (written for FetchIt 4.0), gets one create() per form key
     * first, as before.
     *
     * @return string
     */
    protected function bootstrapScript()
    {
        $classname = trim($this->modx->getOption('fetchit.frontend.js.classname', null, 'FetchIt', true));
        $options = $this->encode([
            'defaultNotifier' => (bool)$this->config['default_notifier'],
            'notifierCloseLabel' => $this->config['default_notifier'] ? $this->lexiconOrEmpty('fetchit_notifier_close') : '',
        ], 'the notifier options') ?: '{}';

        return <<<HTML
<script>
window.addEventListener('DOMContentLoaded', () => {
  let FetchItClass;
  let missing;
  try { FetchItClass = {$classname}; } catch (e) { missing = e; }
  const options = {$options};
  if (!FetchItClass || (typeof FetchItClass.observe !== 'function' && typeof FetchItClass.create !== 'function')) {
    console.error('FetchIt: {$classname} is not loaded; check that its script loads and the setting fetchit.frontend.js.classname', missing || '');
    return;
  }
  // A script of the site without observe(), or a class of the site with a
  // create() of its own (FetchIt 4.0): one create() per form key, as before.
  if (typeof FetchItClass.create === 'function' && (typeof FetchItClass.observe !== 'function'
    || (FetchItClass !== window.FetchIt && Object.prototype.hasOwnProperty.call(FetchItClass, 'create')))) {
    const actions = {};
    document.querySelectorAll('form[data-fetchit-config]').forEach(form => {
      try {
        const config = JSON.parse(form.getAttribute('data-fetchit-config'));
        if (!actions[config.action]) {
          actions[config.action] = true;
          FetchItClass.create(config);
        }
      } catch (e) {
        console.error('FetchIt: could not start the form; it is sent without FetchIt', form, e);
      }
    });
  }
  if (typeof FetchItClass.observe === 'function') {
    FetchItClass.observe(options);
  }
});
</script>
HTML;
    }


    /**
     * A lexicon entry, or '' when the lexicon does not have it (MODX gives
     * the key back), so the script uses its own default.
     *
     * @param string $key
     *
     * @return string
     */
    protected function lexiconOrEmpty($key)
    {
        $value = $this->modx->lexicon($key);

        return $value === $key ? '' : $value;
    }


    /**
     * Called by the plugin at OnWebPagePrerender: add the frontend script,
     * the script of the captcha provider and the bootstrap that starts the
     * forms to <head> once, if loadScript() ran in this request or the page
     * has the SCRIPT_MARKER of FetchItScript, which is taken out. A
     * fragment without <head> and <body> gets nothing. The built-in
     * notifier is part of the frontend script.
     */
    public function registerScript()
    {
        // The event fired with no page, or a page whose output is not text.
        if (empty($this->modx->resource) || !is_string($this->modx->resource->_output)) {
            if (self::$scriptRequested) {
                self::$scriptRequested = false;
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not add the script: OnWebPagePrerender has no page output');
            }

            return;
        }

        $output = &$this->modx->resource->_output;
        $marked = strpos($output, self::SCRIPT_MARKER) !== false;
        if ($marked) {
            $output = str_replace(self::SCRIPT_MARKER, '', $output);
        }
        if (!self::$scriptRequested && !$marked) {
            return;
        }
        self::$scriptRequested = false;

        $id = $this->modx->resource->get('id');
        // A fragment (a resource with an empty template, loaded into
        // another page): its forms carry their config, and the script comes
        // with the page they go into, through its own FetchIt call or
        // [[!FetchItScript]].
        $page = preg_match('#<(?:head|body)\b#i', $output);
        if ($page === false) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] Could not add the script to resource {$id}: " . $this->pcreError());

            return;
        }
        if (!$page) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "[FetchIt] Resource {$id} has no <head> or <body>: no script added; the page it is loaded into needs its own FetchIt call or [[!FetchItScript]]");

            return;
        }

        $js = trim($this->config['frontend_js']);
        if (!preg_match('/\.js/i', $js)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] fetchit.frontend.js is not a JavaScript file: \"{$js}\"; no script added");

            return;
        }

        $assets = [
            '<script src="' . $this->withVersion(str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $js)) . '" defer></script>',
            $this->bootstrapScript(),
        ];

        // The script of the captcha provider, before FetchIt's (both defer).
        if ($captcha = $this->guard()->activeCaptcha()) {
            array_unshift($assets, '<script src="' . htmlspecialchars($captcha['script'], ENT_QUOTES) . '" defer></script>');
        }

        $found = preg_match('#<head\b[^>]*>(.*?)</head>#is', $output, $head, PREG_OFFSET_CAPTURE);
        if (!$found) {
            $reason = $found === false ? $this->pcreError() : 'the page has no <head>';
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] Could not add the script to resource {$this->modx->resource->get('id')}: {$reason}");

            return;
        }

        // Before the first script of <head>, so later deferred scripts run
        // after FetchIt; otherwise at the end of <head>.
        $position = $head[1][1] + strlen($head[1][0]);
        if (preg_match('#<script\b#i', $head[1][0], $script, PREG_OFFSET_CAPTURE)) {
            $position = $head[1][1] + $script[0][1];
        }

        $output = substr_replace($output, join(PHP_EOL, $assets) . PHP_EOL, $position, 0);
    }


    /**
     * fetchit.page_cache: the pages with forms come from a full-page cache
     * (nginx, a CDN), so the properties of a form must outlive the session
     * of the visitor who rendered it, and the script exchanges the token of
     * the page for a fresh one.
     *
     * @return bool
     */
    public function pageCache()
    {
        return (bool)$this->modx->getOption('fetchit.page_cache', null, false);
    }


    /**
     * Whether $action can be a key of a form: X-FetchIt-Action comes from the
     * client, and the key names a file of the store.
     *
     * @param mixed $action
     *
     * @return bool
     */
    public static function isAction($action)
    {
        return is_string($action) && preg_match('/^[0-9a-f]{32}$/', $action) === 1;
    }


    /**
     * @param string $action
     *
     * @return string
     */
    protected function getActionPropertiesCacheKey($action)
    {
        return 'fetchit/props_' . $action;
    }


    /**
     * Persist snippet properties for AJAX process(); strip non-serializable values (#17).
     *
     * @param string $action
     * @param array $scriptProperties
     */
    public function storeActionProperties($action, array $scriptProperties)
    {
        foreach ($scriptProperties as $key => $value) {
            if (is_object($value) || is_resource($value)) {
                unset($scriptProperties[$key]);
            }
        }

        if ($this->pageCache() && self::isAction($action) && ($store = $this->guard()->store()) !== null) {
            $json = json_encode($scriptProperties);
            if ($json === false) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] The properties of form ' . substr($action, 0, 8)
                    . ' cannot be stored as JSON (' . json_last_error_msg() . '); it does not work from a full-page cache');
            } elseif (!$store->saveForm($action, $json, time())) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] The properties of form ' . substr($action, 0, 8)
                    . ' could not be stored; it does not work from a full-page cache');
            }
        }

        if (!empty(session_id())) {
            if (!isset($_SESSION['FetchIt'])) {
                $_SESSION['FetchIt'] = array();
            }
            $_SESSION['FetchIt'][$action] = $scriptProperties;
            return;
        }

        $this->modx->cacheManager->set(
            $this->getActionPropertiesCacheKey($action),
            $scriptProperties,
            3600
        );
    }


    /**
     * Stored action properties, or null if missing / invalid. With
     * fetchit.page_cache a failure of the store is remembered for process(),
     * and properties that are not valid JSON are logged once.
     *
     * @param string $action
     *
     * @return array|null
     */
    public function loadActionProperties($action)
    {
        $this->storeFailed = false;
        if (!empty(session_id()) && isset($_SESSION['FetchIt'][$action])) {
            $stored = $_SESSION['FetchIt'][$action];
        } else {
            $stored = $this->modx->cacheManager->get($this->getActionPropertiesCacheKey($action));
            if ((empty($stored) || !is_array($stored)) && $this->pageCache() && self::isAction($action)
                && ($store = $this->guard()->store()) !== null
            ) {
                $stored = $this->decodeStored($action, $store->loadForm($action));
            }
        }

        if (empty($stored) || !is_array($stored)) {
            return null;
        }

        return $stored;
    }


    /**
     * The properties of a form as FetchItStore::loadForm() gave them.
     *
     * @param string $action
     * @param string|null|false $json
     *
     * @return array|null
     */
    protected function decodeStored($action, $json)
    {
        if ($json === false) {
            $this->storeFailed = true;

            return null;
        }
        if ($json === null) {
            return null;
        }

        $stored = json_decode($json, true);
        if (!is_array($stored)) {
            if (!isset($this->invalidJson[$action])) {
                $this->invalidJson[$action] = true;
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] The stored properties of form ' . substr($action, 0, 8)
                    . ' are not valid JSON (' . json_last_error_msg() . '); the form is answered with "form not found"');
            }

            return null;
        }

        return $stored;
    }


    /**
     * The FetchIt 3.x name of storeActionProperties().
     *
     * @param string $action
     * @param array $scriptProperties
     */
    public function saveActionProperties($action, array $scriptProperties)
    {
        $this->storeActionProperties($action, $scriptProperties);
    }


    /**
     * The FetchIt 3.x name of loadActionProperties().
     *
     * @param string $action
     *
     * @return array|null
     */
    public function getActionProperties($action)
    {
        return $this->loadActionProperties($action);
    }


    /**
     * Loads snippet for form processing
     *
     * @param $action
     * @param array $fields
     *
     * @return array|string
     */
    public function process($action, array $fields = array())
    {
        $stored = $this->loadActionProperties($action);
        if ($stored === null) {
            return $this->error($this->storeFailed ? 'fetchit_err_store' : 'fetchit_err_action_nf');
        }

        // Do not set FetchIt=>$this here (PDO in session, #17).
        // Custom snippets: FetchIt::service($modx).
        $scriptProperties = array_merge($stored, array(
            'fields' => $fields,
        ));

        $name = $scriptProperties['snippet'];
        $set = '';
        if (strpos($name, '@') !== false) {
            list($name, $set) = explode('@', $name);
        }

        /** @var modSnippet $snippet */
        if ($snippet = $this->modx->getObject('modSnippet', array('name' => $name))) {
            $properties = $snippet->getProperties();
            $property_set = array();
            if (!empty($set)) {
                // null for a set that does not exist, e.g. a typo in the call.
                $property_set = $snippet->getPropertySet($set);
                if (!is_array($property_set)) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] Snippet {$name} has no property set \"{$set}\"; running it without one");
                    $property_set = array();
                }
            }

            $scriptProperties = array_merge($properties, $property_set, $scriptProperties);
            $snippet->_cacheable = false;
            $snippet->_processed = false;

            $formit = strtolower($snippet->name) == 'formit';
            $before = $formit ? $this->pageBeforeFormIt($scriptProperties) : null;
            $response = $snippet->process($scriptProperties);
            if ($formit) {
                $this->disableFormItAjax($scriptProperties, $before);
                $response = $this->handleFormIt($scriptProperties);
            }

            return $response;
        } else {
            return $this->error('fetchit_err_snippet_nf', array(), array('name' => $name));
        }
    }


    /**
     * @param string $key
     *
     * @return string
     */
    protected function getSanitizedPlaceholder($key)
    {
        if (!isset($this->modx->placeholders[$key])) {
            return '';
        }

        $value = strip_tags(html_entity_decode((string)$this->modx->placeholders[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // &nbsp; decodes to U+00A0, which trim() keeps. preg_replace()
        // returns null on invalid UTF-8.
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);

        return $trimmed === null ? trim($value) : $trimmed;
    }


    /**
     * @param string $plPrefix
     * @param string $field
     *
     * @return string
     */
    protected function getFieldError($plPrefix, $field)
    {
        return $this->getSanitizedPlaceholder($plPrefix . 'error.' . $field);
    }


    /**
     * What disableFormItAjax() compares with: the scripts registered on the
     * page so far and the AJAX token of another FormIt form with the same
     * placeholder prefix, if any.
     *
     * @param array $properties The FormIt properties
     *
     * @return array
     */
    protected function pageBeforeFormIt(array $properties)
    {
        $placeholder = $this->formItTokenPlaceholder($properties);

        return array(
            'jscripts' => (array)$this->modx->jscripts,
            'sjscripts' => (array)$this->modx->sjscripts,
            'loadedjscripts' => (array)$this->modx->loadedjscripts,
            'hasToken' => array_key_exists($placeholder, $this->modx->placeholders),
            'token' => isset($this->modx->placeholders[$placeholder]) ? $this->modx->placeholders[$placeholder] : null,
        );
    }


    /**
     * @param array $properties The FormIt properties
     *
     * @return string
     */
    protected function formItTokenPlaceholder(array $properties)
    {
        $prefix = isset($properties['placeholderPrefix']) ? $properties['placeholderPrefix'] : 'fi.';

        return $prefix . 'ajaxToken';
    }


    /**
     * Turn off the AJAX mode of FormIt 5.2+ for a form of FetchIt. Without a
     * submission, FormIt keeps the snippet properties (hooks, emails) in the
     * session and the cache under a token for its own action.php, which
     * processes the form without the protection of FetchIt, and links
     * formit.js, which takes over the submission of a form with that token.
     * FetchIt sends the form itself, so what this run of FormIt added is
     * undone: the stored properties, the ajaxToken placeholder (back to the
     * token of another FormIt form on the page, if there was one) and the
     * scripts. Forms of FormIt itself on the same page keep their AJAX mode.
     * Older FormIt versions do none of it.
     *
     * @param array $properties The FormIt properties
     * @param array $before From pageBeforeFormIt()
     */
    protected function disableFormItAjax(array $properties, array $before)
    {
        $placeholder = $this->formItTokenPlaceholder($properties);
        $token = isset($this->modx->placeholders[$placeholder]) ? (string)$this->modx->placeholders[$placeholder] : '';
        if ($token !== (string)$before['token'] && preg_match('/^[a-f0-9]{32}$/', $token)) {
            if (isset($_SESSION['formit'][$token])) {
                unset($_SESSION['formit'][$token]);
            }
            $this->modx->cacheManager->delete('formit/props_' . $token);
            if ($before['hasToken']) {
                $this->modx->setPlaceholder($placeholder, $before['token']);
            } else {
                $this->modx->unsetPlaceholder($placeholder);
            }
        }

        $script = trim((string)$this->modx->getOption('formit.frontend_js', null, ''));
        $isFormIt = function ($entry) use ($script) {
            return ($script !== '' && strpos($entry, $script) !== false)
                || strpos($entry, 'Object.assign(FormIt,') !== false;
        };
        foreach (array('jscripts', 'sjscripts') as $list) {
            $entries = (array)$this->modx->$list;
            $added = array_slice($entries, count($before[$list]));
            $kept = array_filter($added, function ($entry) use ($isFormIt) {
                return !$isFormIt($entry);
            });
            if (count($kept) !== count($added)) {
                $this->modx->$list = array_merge(array_slice($entries, 0, count($before[$list])), array_values($kept));
            }
        }
        foreach (array_keys((array)$this->modx->loadedjscripts) as $src) {
            if (!isset($before['loadedjscripts'][$src]) && $isFormIt((string)$src)) {
                unset($this->modx->loadedjscripts[$src]);
            }
        }
    }


    /**
     * Method for obtaining data from FormIt
     *
     * @param array $scriptProperties
     *
     * @return array|string
     */
    public function handleFormIt(array $scriptProperties = array())
    {
        $plPrefix = isset($scriptProperties['placeholderPrefix'])
            ? $scriptProperties['placeholderPrefix']
            : 'fi.';

        $errors = array();
        foreach ($scriptProperties['fields'] as $k => $v) {
            $error = $this->getFieldError($plPrefix, $k);
            if ($error !== '') {
                $errors[$k] = $error;
            }
        }

        foreach (array('recaptcha', 'recaptchav2_error', 'recaptchav3_error') as $recaptchaField) {
            $error = $this->getFieldError($plPrefix, $recaptchaField);
            if ($error !== '') {
                $errors['recaptcha'] = $error;
                break;
            }
        }

        if (!empty($errors)) {
            $message = $this->getSanitizedPlaceholder($plPrefix . 'validation_error_message');
            if ($message === '') {
                $message = 'fetchit_err_has_errors';
            }
            $status = 'error';
        } else {
            $message = !empty($scriptProperties['successMessage'])
                ? $scriptProperties['successMessage']
                : (isset($this->modx->placeholders[$plPrefix . 'successMessage'])
                    ? $this->modx->placeholders[$plPrefix . 'successMessage']
                    : 'fetchit_success_submit');
            $status = 'success';
        }

        return $this->$status($message, $errors);
    }


    /**
     * This method returns an error of the order
     *
     * @param string $message A lexicon key for error message
     * @param array $data .Additional data, for example cart status
     * @param array $placeholders Array with placeholders for lexicon entry
     *
     * @return array|string $response
     */
    public function error($message = '', $data = array(), $placeholders = array())
    {
        $response = array(
            'success' => false,
            'message' => $this->modx->lexicon($message, $placeholders),
            'data' => $data,
        );

        return $this->config['json_response']
            ? $this->modx->toJSON($response)
            : $response;
    }


    /**
     * Add the page of &redirectTo to a successful answer of a processing
     * snippet other than FormIt: FormIt has &redirectTo of its own, used
     * only with its redirect hook (see redirectAnswer()). Any other answer
     * is left as it is.
     *
     * @param string $action
     * @param string $body The answer as JSON
     *
     * @return string
     */
    public function withRedirect($action, $body)
    {
        $properties = $this->loadActionProperties($action);
        if (!is_array($properties) || $this->isFormIt($properties)) {
            return $body;
        }
        $url = $this->redirectUrl($properties);

        return $url !== '' ? $this->addRedirect($body, $url) : $body;
    }


    /**
     * The address of &redirectTo with &redirectParams: a resource id (its
     * full URL in the context of the page) or an address, a path of the
     * site made absolute. '' when there is none or the resource is missing
     * (logged).
     *
     * @param array $properties
     *
     * @return string
     */
    public function redirectUrl(array $properties)
    {
        $to = isset($properties['redirectTo']) ? trim((string)$properties['redirectTo']) : '';
        if ($to === '') {
            return '';
        }
        $params = isset($properties['redirectParams']) ? $properties['redirectParams'] : [];
        if (is_string($params)) {
            $params = trim($params) !== '' ? json_decode($params, true) : [];
        }
        if (!is_array($params)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] &redirectParams is not a JSON object; the redirect goes without them');
            $params = [];
        }

        if (ctype_digit($to)) {
            $url = (string)$this->modx->makeUrl((int)$to, '', $params, 'full');
            if ($url === '') {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] &redirectTo is resource {$to}, which has no URL; the visitor stays on the page");
            }

            return $url;
        }

        $url = $to;
        if ($params) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = rtrim((string)$this->modx->getOption('site_url', null, '/'), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }


    /**
     * The answer for a redirect the processing sent ($modx->sendRedirect(),
     * as the redirect hook of FormIt does): a success with the message of
     * FormIt, or of FetchIt, and the address.
     *
     * @param string $action
     * @param string $location The address of the Location header
     * @param array $fields The fields that were processed
     *
     * @return string JSON
     */
    public function redirectAnswer($action, $location, array $fields)
    {
        $properties = $this->loadActionProperties($action);
        if (is_array($properties) && $this->isFormIt($properties)) {
            $answer = $this->handleFormIt(array_merge($properties, ['fields' => $fields]));
        } else {
            $answer = $this->success('fetchit_success_submit');
        }

        return $this->addRedirect($this->json($answer), $location);
    }


    /**
     * @param string $body A successful answer as JSON
     * @param string $url
     *
     * @return string The answer with redirect; any other answer as it is
     */
    protected function addRedirect($body, $url)
    {
        $answer = json_decode($body, true);
        if (!is_array($answer) || !isset($answer['success']) || $answer['success'] !== true) {
            return $body;
        }
        $answer['redirect'] = $url;

        return $this->json($answer);
    }


    /**
     * @param array|string $response
     *
     * @return string
     */
    protected function json($response)
    {
        return is_array($response) ? (string)$this->modx->toJSON($response) : (string)$response;
    }


    /**
     * Whether the form is processed by FormIt (the snippet may carry a
     * property set: FormIt@contact).
     *
     * @param array $properties
     *
     * @return bool
     */
    protected function isFormIt(array $properties)
    {
        $name = isset($properties['snippet']) ? explode('@', (string)$properties['snippet'])[0] : 'FormIt';

        return strtolower($name) === 'formit';
    }


    /**
     * This method returns an success of the order
     *
     * @param string $message A lexicon key for success message
     * @param array $data .Additional data, for example cart status
     * @param array $placeholders Array with placeholders for lexicon entry
     *
     * @return array|string $response
     */
    public function success($message = '', $data = array(), $placeholders = array())
    {
        $response = array(
            'success' => true,
            'message' => $this->modx->lexicon($message, $placeholders),
            'data' => $data,
        );

        return $this->config['json_response']
            ? $this->modx->toJSON($response)
            : $response;
    }
}

// FetchIt 3.x called this class FetchIt\FetchIt. The alias lives with the
// class because instanceof does not autoload, and MODX 2 has no autoloader
// for FetchIt\ at all (src/FetchIt.php only loads this file).
if (!class_exists('FetchIt\FetchIt', false)) {
    class_alias('FetchIt', 'FetchIt\FetchIt');
}
