<?php

class FetchIt
{
    public $version = '1.0.0';
    /** @var modX $modx */
    public $modx;
    /** @var array $config */
    public $config;


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
            '[[+assetsUrl]]js/default.js');

        $this->modx->lexicon->load('fetchit:default');

        $this->config = array_merge(array(
            'assetsUrl' => $assetsUrl,
            'actionUrl' => $assetsUrl . 'action.php',

            'json_response' => true,

            'corePath' => $corePath,
            'assetsPath' => $assetsPath,

            'frontend_js' => $frontend_js,
        ), $config);
    }


    /**
     * Independent registration of JavaScripts
     */
    public function loadScript($action)
    {
        $_SESSION['fetchit_called'] = true;

        $config = $this->modx->toJSON([
            'action' => $action,
            'assetsUrl' => $this->config['assetsUrl'],
            'actionUrl' => str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $this->config['actionUrl']),
            'inputInvalidClass' => trim(preg_replace('/\s+/', ' ', $this->modx->getOption('fetchit.frontend.input.invalid.class'))),
            'customInvalidClass' => trim(preg_replace('/\s+/', ' ', $this->modx->getOption('fetchit.frontend.custom.invalid.class'))),
            'clearFieldsOnSuccess' => (bool)$this->modx->getOption('clearFieldsOnSuccess', $this->config, 1, false),
            'pageId' => !empty($this->modx->resource)
                ? $this->modx->resource->get('id')
                : 0,
        ]);
        $js_classname = trim($this->modx->getOption('fetchit.frontend.js.classname', null, 'FetchIt', true));
        $this->modx->regClientHTMLBlock("<script>window.addEventListener('DOMContentLoaded', () => {$js_classname}.create($config));</script>");
    }


    /**
     * Registers the main script first
     */

    public function registerScript()
    {
        if (!$_SESSION['fetchit_called']) {
            return;
        }

        $js = trim($this->config['frontend_js']);
        if (!preg_match('/\.js/i', $js)) {
            return;
        }

        $js = '<script src="' . str_replace('[[+assetsUrl]]', $this->config['assetsUrl'], $js) . '?v=' . $this->version . '" defer></script>';
        $output = &$this->modx->resource->_output;

        if (preg_match('#(?:<head>[\s\S]*?)(\s*?<script[\s\S]*?((</script>)|(/>)))(?:[\s\S]*?</head>)#i', $output, $matches)) {
            $script = $matches[1];
            $script = preg_replace('/<script[\s\S]*<\/script>/', $js, $script);
            $output = preg_replace('#(<head>[\s\S]*?)(\s*?<script[\s\S]*?</script>)([\s\S]*?</head>)#', "$1$js$2$3", $output, 1);
        } else {
            $this->modx->regClientStartupScript($js);
        }

        unset($_SESSION['fetchit_called']);
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
        $scriptProperties = !empty(session_id())
            ? @$_SESSION['FetchIt'][$action]
            : $this->modx->cacheManager->get('fetchit/props_' . $action);

        if (empty($scriptProperties)) {
            return $this->error('fetchit_err_action_nf');
        }

        $scriptProperties['fields'] = $fields;
        $scriptProperties['FetchIt'] = $this;

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
            if (isset($this->modx->placeholders[$plPrefix . 'error.' . $k])) {
                $errors[$k] = $this->modx->placeholders[$plPrefix . 'error.' . $k];
            }
        }

        if (!empty($this->modx->placeholders[$plPrefix . 'error.recaptcha'])) {
            $errors['recaptcha'] = $this->modx->placeholders[$plPrefix . 'error.recaptcha'];
        }

        if (!empty($this->modx->placeholders[$plPrefix . 'error.recaptchav2_error'])) {
            $errors['recaptcha'] = $this->modx->placeholders[$plPrefix . 'error.recaptchav2_error'];
        }

        if (!empty($errors)) {
            $message = !empty($this->modx->placeholders[$plPrefix . 'validation_error_message'])
                ? $this->modx->placeholders[$plPrefix . 'validation_error_message']
                : 'fetchit_err_has_errors';
            $status = 'error';
        } else {
            $message = isset($this->modx->placeholders[$plPrefix . 'successMessage'])
                ? $this->modx->placeholders[$plPrefix . 'successMessage']
                : 'fetchit_success_submit';
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
