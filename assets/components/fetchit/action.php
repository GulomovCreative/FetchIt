<?php

/** @var modX $modx */
define('MODX_API_MODE', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/index.php';
if (!class_exists('FetchIt', false)) {
    require_once $modx->getOption('fetchit.core_path', null, $modx->getOption('core_path') . 'components/fetchit/')
        . 'model/fetchit.class.php';
}

if (is_object($modx->services)) {
    // MODX 3: getService() is deprecated there.
    if (!$modx->services->has('error')) {
        $modx->services->add('error', new \MODX\Revolution\Error\modError($modx));
    }
    $modx->error = $modx->services->get('error');
} else {
    $modx->getService('error', 'error.modError');
}
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');

// Switch context if need
if (!empty($_REQUEST['pageId'])) {
    if ($resource = $modx->getObject('modResource', (int)$_REQUEST['pageId'])) {
        if ($resource->get('context_key') != 'web') {
            $modx->switchContext($resource->get('context_key'));
        }
        $modx->resource = $resource;
    }
}

$FetchIt = FetchIt::service($modx);

if (empty($_POST)) {
    $modx->sendRedirect($modx->makeUrl($modx->getOption('site_start'), '', '', 'full'));
} elseif (empty($_SERVER['HTTP_X_FETCHIT_ACTION'])) {
    echo $FetchIt->error('fetchit_err_action_ns');
} else {
    // Only the posted form: $_REQUEST may also hold GET values and, with
    // request_order allowing it, cookies.
    echo $FetchIt->process($_SERVER['HTTP_X_FETCHIT_ACTION'], array_merge($_FILES, $_POST));
}

@session_write_close();
