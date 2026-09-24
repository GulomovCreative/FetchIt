<?php

class FetchIt
{
    public $version = '1.1.4';
    /** @var modX $modx */
    public $modx;
    /** @var array $config */
    public $config;

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
     * rest of the tag and everything inside the form stay as they are.
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
            $attributes = preg_replace_callback($attribute, function ($pair) {
                return in_array(strtolower($pair[2]), ['method', 'data-fetchit'], true) ? '' : $pair[0];
            }, $match[1]);
            if ($attributes === null) {
                $attributes = $match[1];
            }

            return substr($match[0], 0, 5) . rtrim($attributes)
                . ' method="post" data-fetchit="' . htmlspecialchars($action, ENT_QUOTES) . '">';
        }, $html);

        if ($result === null) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not prepare the form: ' . $this->pcreError());

            return $html;
        }

        return $result;
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

        $config = $this->modx->toJSON([
            'action' => $action,
            'assetsUrl' => $this->config['assetsUrl'],
            'actionUrl' => str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $this->config['actionUrl']),
            'inputInvalidClass' => trim(preg_replace('/\s+/', ' ', $this->modx->getOption('fetchit.frontend.input.invalid.class'))),
            'customInvalidClass' => trim(preg_replace('/\s+/', ' ', $this->modx->getOption('fetchit.frontend.custom.invalid.class'))),
            'clearFieldsOnSuccess' => (bool)$this->modx->getOption('clearFieldsOnSuccess', $this->config, 1, false),
            'defaultNotifier' => $this->config['default_notifier'],
            'requestErrorMessage' => $this->modx->lexicon('fetchit_err_request'),
            'pageId' => !empty($this->modx->resource)
                ? $this->modx->resource->get('id')
                : 0,
        ]);
        $js_classname = trim($this->modx->getOption('fetchit.frontend.js.classname', null, 'FetchIt', true));
        // Without the script (a cached snippet call, a page without <head>)
        // the form falls back to a normal submit instead of a ReferenceError.
        $this->modx->regClientHTMLBlock("<script>window.addEventListener('DOMContentLoaded', () => window.{$js_classname} ? {$js_classname}.create($config) : console.error('FetchIt: {$js_classname} is not loaded'));</script>");
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
        if (!preg_match('/\.m?js(?:[?#]|$)/i', $js)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] fetchit.frontend.js is not a JavaScript file: \"{$js}\"; no script added");

            return;
        }

        $assets = ['<script src="' . str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $js) . '?v=' . $this->version . '" defer></script>'];

        if ($this->config['default_notifier']) {
            array_unshift($assets,
                '<link rel="stylesheet" href="' . $this->config['assetsUrl'] . 'lib/notyf.min.css?v=' . $this->version . '" />',
                '<script src="' . $this->config['assetsUrl'] . 'lib/notyf.min.js?v=' . $this->version . '" defer></script>'
            );
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
        // Custom snippets: $modx->getService('fetchit', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/', []).
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
            $property_set = !empty($set)
                ? $snippet->getPropertySet($set)
                : array();

            $scriptProperties = array_merge($properties, $property_set, $scriptProperties);
            $snippet->_cacheable = false;
            $snippet->_processed = false;

            $response = $snippet->process($scriptProperties);
            if (strtolower($snippet->name) == 'formit') {
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
