<?php

require_once dirname(__FILE__) . '/fetchitguard.class.php';
require_once dirname(__FILE__) . '/fetchitcaptcha.class.php';

class FetchIt
{
    public $version = '4.0.0';
    /** @var modX $modx */
    public $modx;
    /** @var array $config */
    public $config;

    /** @var FetchItGuard|null */
    protected $guard;

    /**
     * Set by loadScript() when the snippet puts a form on the page, so the
     * plugin adds the scripts at OnWebPagePrerender of the same request.
     * Kept per request, not in the session, which may not exist. Only an
     * uncached call ([[!FetchIt]]) sets it on every page view.
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
     * Give every form in the chunk the POST method and the action key.
     *
     * The form tag loses any method and data-fetchit of its own, and gets
     * method="post" and data-fetchit="$action" as its last attributes. The
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

        $result = preg_replace_callback($tag, function ($match) use ($action, $attribute) {
            /** @var array $match */
            $attributes = preg_replace_callback($attribute, function ($pair) {
                return in_array(strtolower($pair[2]), ['method', 'data-fetchit'], true) ? '' : $pair[0];
            }, $match[1]);
            if ($attributes === null) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not read the attributes of a form: ' . $this->pcreError());
                $attributes = $match[1];
            }

            return substr($match[0], 0, 5) . rtrim($attributes)
                . ' method="post" data-fetchit="' . htmlspecialchars($action, ENT_QUOTES) . '">'
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
     * Flag this request for registerScript() and add the inline call that
     * initialises the forms with this action.
     *
     * @param string $action
     */
    public function loadScript($action)
    {
        self::$scriptRequested = true;

        $captcha = $this->guard()->activeCaptcha();
        $config = $this->modx->toJSON([
            'action' => $action,
            'assetsUrl' => $this->config['assetsUrl'],
            'actionUrl' => str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $this->config['actionUrl']),
            'inputInvalidClass' => trim(preg_replace('/\s+/', ' ', (string)$this->modx->getOption('fetchit.frontend.input.invalid.class'))),
            'customInvalidClass' => trim(preg_replace('/\s+/', ' ', (string)$this->modx->getOption('fetchit.frontend.custom.invalid.class'))),
            'clearFieldsOnSuccess' => (bool)$this->modx->getOption('clearFieldsOnSuccess', $this->config, 1, false),
            'defaultNotifier' => $this->config['default_notifier'],
            'requestErrorMessage' => $this->modx->lexicon('fetchit_err_request'),
            'pow' => $this->guard()->pow(),
            'captcha' => $captcha !== null ? ['provider' => $captcha['provider'], 'siteKey' => $captcha['siteKey']] : null,
            'captchaErrorMessage' => $captcha !== null ? $this->modx->lexicon('fetchit_err_captcha_client') : '',
            'pageId' => !empty($this->modx->resource)
                ? $this->modx->resource->get('id')
                : 0,
        ]);
        $js_classname = trim($this->modx->getOption('fetchit.frontend.js.classname', null, 'FetchIt', true));
        // The class may be a window property, a top-level "class" declaration
        // (no window property) or a dotted name. Without the script (a cached
        // snippet call, a page without <head>) the form falls back to a
        // normal submit instead of throwing.
        $this->modx->regClientHTMLBlock("<script>window.addEventListener('DOMContentLoaded', () => { let FetchItClass; try { FetchItClass = {$js_classname}; } catch (e) {} if (FetchItClass && typeof FetchItClass.create === 'function') { FetchItClass.create($config); } else { console.error('FetchIt: {$js_classname} is not loaded'); } });</script>");
    }


    /**
     * Called by the plugin at OnWebPagePrerender: add the frontend script
     * (and the notifier) to <head> once, if loadScript() ran in this request.
     */
    public function registerScript()
    {
        if (!self::$scriptRequested) {
            return;
        }
        self::$scriptRequested = false;

        $js = trim($this->config['frontend_js']);
        if (!preg_match('/\.js/i', $js)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] fetchit.frontend.js is not a JavaScript file: \"{$js}\"; no script added");

            return;
        }

        $assets = ['<script src="' . $this->withVersion(str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $js)) . '" defer></script>'];

        if ($this->config['default_notifier']) {
            array_unshift($assets,
                '<link rel="stylesheet" href="' . $this->config['assetsUrl'] . 'lib/notyf.min.css?v=' . $this->version . '" />',
                '<script src="' . $this->config['assetsUrl'] . 'lib/notyf.min.js?v=' . $this->version . '" defer></script>'
            );
        }

        // The script of the captcha provider, before FetchIt's (both defer).
        if ($captcha = $this->guard()->activeCaptcha()) {
            array_unshift($assets, '<script src="' . htmlspecialchars($captcha['script'], ENT_QUOTES) . '" defer></script>');
        }

        $output = &$this->modx->resource->_output;
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
     * Stored action properties, or null if missing / invalid.
     *
     * @param string $action
     *
     * @return array|null
     */
    public function loadActionProperties($action)
    {
        if (!empty(session_id()) && isset($_SESSION['FetchIt'][$action])) {
            $stored = $_SESSION['FetchIt'][$action];
        } else {
            $stored = $this->modx->cacheManager->get($this->getActionPropertiesCacheKey($action));
        }

        if (empty($stored) || !is_array($stored)) {
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
            return $this->error('fetchit_err_action_nf');
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
