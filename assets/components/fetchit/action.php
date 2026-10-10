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
} elseif (!empty($_SERVER['HTTP_X_FETCHIT_EXCHANGE'])) {
    // A page from a full-page cache asks for a fresh token (fetchit.page_cache).
    // POST only (a GET has no fields and is redirected above): proxies and
    // CDNs may cache a GET.
    header('Cache-Control: no-store, private');
    $guard = $FetchIt->guard();
    if ($next = $guard->exchange($_SERVER['HTTP_X_FETCHIT_ACTION'], $_POST)) {
        header('X-FetchIt-Token: ' . $next);
        header('X-FetchIt-Pow: ' . $guard->pow());
        header('X-FetchIt-Min-Time: ' . $guard->minTime());
    } elseif ($reason = $guard->reason()) {
        header('X-FetchIt-Refused: ' . $reason);
    }
    http_response_code(204);
} else {
    // Only the posted form: $_REQUEST may also hold GET values and, with
    // request_order allowing it, cookies.
    $answer = $FetchIt->submit(
        $_SERVER['HTTP_X_FETCHIT_ACTION'],
        $_POST,
        isset($_SERVER['HTTP_X_FETCHIT_SUBMISSION']) ? (string)$_SERVER['HTTP_X_FETCHIT_SUBMISSION'] : ''
    );
    if ($answer['status'] !== 200) {
        http_response_code($answer['status']);
    }
    echo $answer['body'];
}

@session_write_close();
