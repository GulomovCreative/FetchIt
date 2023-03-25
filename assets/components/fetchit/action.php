<?php

use MODX\Revolution\Error\modError;

if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/config.core.php')) {
    header("HTTP/1.1 500 Internal Server Error");
    exit('Server initialization error!');
}

define('MODX_API_MODE', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/** @var modX $modx */
$modx = new modX();
$modx->initialize('web');

$versionData = $modx->getVersionData();
$version = (int) $versionData['version'];

if ($version === 2) {
    $modx->getService('error', 'error.modError');
    $modx->setLogLevel(modX::LOG_LEVEL_ERROR);
    $modx->setLogTarget('FILE');
} else {
    if (!$modx->services->has('error')) {
        $modx->services->add('error', new modError($modx));
    }
    $modx->error = $modx->services->get('error');
}

// Switch context if need
if (!empty($_REQUEST['pageId'])) {
    if ($resource = $modx->getObject('modResource', (int)$_REQUEST['pageId'])) {
        if ($resource->get('context_key') !== 'web') {
            $modx->switchContext($resource->get('context_key'));
        }
        $modx->resource = $resource;
    }
}

require_once $modx->getOption('fetchit.core_path', null, $modx->getOption('core_path') . 'components/fetchit/') . 'model/fetchit.class.php';
/** @var FetchIt $FetchIt */
$FetchIt = new FetchIt($modx);

if (!isset($_POST)) {
    $modx->sendRedirect($modx->makeUrl($modx->getOption('site_start'), '', '', 'full'));
} elseif (empty($_SERVER['HTTP_X_FETCHIT_ACTION'])) {
    echo $FetchIt->error('fetchit_err_action_ns');
} else {
    echo $FetchIt->process($_SERVER['HTTP_X_FETCHIT_ACTION'], array_merge($_FILES, $_REQUEST));
}

@session_write_close();
