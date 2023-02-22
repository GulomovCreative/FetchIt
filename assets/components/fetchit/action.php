<?php

/** @var modX $modx */
define('MODX_API_MODE', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/index.php';
$modx->getService('error', 'error.modError');
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

/** @var FetchIt $FetchIt */
$FetchIt = $modx->getService('fetchit', 'FetchIt', $modx->getOption('fetchit.core_path', null,
        $modx->getOption('core_path') . 'components/fetchit/') . 'model/', []);

if (!isset($_POST)) {
    $modx->sendRedirect($modx->makeUrl($modx->getOption('site_start'), '', '', 'full'));
} elseif (empty($_SERVER['HTTP_X_FETCHIT_ACTION'])) {
    echo $FetchIt->error('fetchit_err_action_ns');
} else {
    echo $FetchIt->process($_SERVER['HTTP_X_FETCHIT_ACTION'], array_merge($_FILES, $_REQUEST));
}

@session_write_close();
