<?php

use FetchIt\FetchIt;
use MODX\Revolution\Error\modError;

if (!file_exists(dirname(__DIR__, 3) . '/config.core.php')) {
    header("HTTP/1.1 500 Internal Server Error");
    exit('Server initialization error!');
}

define('MODX_API_MODE', true);
require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/** @var MODX\Revolution\modX|modX $modx */
$modx = new modX();
$modx->initialize('web');

if (!$modx->services->has('error')) {
    $modx->services->add('error', new modError($modx));
}
$modx->error = $modx->services->get('error');

// Switch context if need
if (!empty($_REQUEST['pageId'])) {
    if ($resource = $modx->getObject('modResource', (int)$_REQUEST['pageId'])) {
        if ($resource->get('context_key') !== 'web') {
            $modx->switchContext($resource->get('context_key'));
        }
        $modx->resource = $resource;
    }
}

/** @var FetchIt $FetchIt */
$FetchIt = $modx->services->get('FetchIt');

if (!isset($_POST)) {
    $modx->sendRedirect($modx->makeUrl($modx->getOption('site_start'), '', '', 'full'));
} elseif (empty($_SERVER['HTTP_X_FETCHIT_ACTION'])) {
    echo $FetchIt->error('fetchit_err_action_ns');
} else {
    echo $FetchIt->process($_SERVER['HTTP_X_FETCHIT_ACTION'], array_merge($_FILES, $_REQUEST));
}

@session_write_close();
