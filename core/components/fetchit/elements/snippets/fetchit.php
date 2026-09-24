<?php
/** @var modX $modx */
/** @var FetchIt $FetchIt */
/** @var array $scriptProperties */
if (!$modx->loadClass('fetchit', MODX_CORE_PATH . 'components/fetchit/model/', false, true)) {
    return false;
}
$FetchIt = new FetchIt($modx, $scriptProperties);

$snippet = $modx->getOption('snippet', $scriptProperties, 'FormIt', true);
$tpl = $modx->getOption('form', $scriptProperties, 'tpl.FetchIt.example', true);

/** @var pdoTools $pdo */
if (class_exists('pdoTools') && $pdo = $modx->getService('pdoTools')) {
    $content = $pdo->getChunk($tpl, $scriptProperties);
} else {
    $content = $modx->getChunk($tpl, $scriptProperties);
}
if (empty($content)) {
    return $modx->lexicon('fetchit_err_chunk_nf', array('name' => $tpl));
}

// Every form gets method="post" and the action key the script looks for
$action = md5(http_build_query($scriptProperties));
$content = $FetchIt->prepareForm($content, $action);

$FetchIt->loadScript($action);

// Save snippet properties
$FetchIt->storeActionProperties($action, $scriptProperties);

// Call snippet for preparation of form
$action = !empty($_SERVER['HTTP_X_FETCHIT_ACTION'])
    ? $_SERVER['HTTP_X_FETCHIT_ACTION']
    : $action;

$FetchIt->process($action, $_POST);

return $content;
