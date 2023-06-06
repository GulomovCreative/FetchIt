<?php

use FetchIt\FetchIt;

/** @var modX $modx */
/** @var FetchIt $FetchIt */
/** @var array $scriptProperties */

require_once MODX_CORE_PATH . 'components/fetchit/src/FetchIt.php';
$FetchIt = new FetchIt($modx, $scriptProperties);

$snippet = $modx->getOption('snippet', $scriptProperties, 'FormIt', true);
$tpl = $modx->getOption('form', $scriptProperties, 'tpl.FetchIt.example', true);

/** @var pdoTools $pdo */
if ($pdo =
    $modx->services->has('pdoTools') ?
    $modx->services->get('pdoTools') :
    false
) {
    $content = $pdo->getChunk($tpl, $scriptProperties);
} else {
    $content = $modx->getChunk($tpl, $scriptProperties);
}
if (empty($content)) {
    return $modx->lexicon('fetchit_err_chunk_nf', array('name' => $tpl));
}

// Add method = post
if (preg_match('#<form.*?method=(?:"|\')(.*?)(?:"|\')#i', $content)) {
    $content = preg_replace('#<form(.*?)method=(?:"|\')(.*?)(?:"|\')#i', '<form\\1method="post"', $content);
} else {
    $content = str_ireplace('<form', '<form method="post"', $content);
}

// Add action for form processing
$action = md5(http_build_query($scriptProperties));
// Add selector to tag form
if (preg_match('#<form.*?data-fetchit=(?:"|\')(.*?)(?:"|\')#i', $content, $matches)) {
    $content = preg_replace('#<form(.*?)data-fetchit=(?:"|\')(.*?)(?:"|\')#i', '<form\\data-fetchit="$action"', $content);
} else {
    $content = str_ireplace('<form', '<form data-fetchit="' . $action . '"', $content);
}

$FetchIt->loadScript($action);

// Save snippet properties
if (!empty(session_id())) {
    // ... to user`s session
    $_SESSION['FetchIt'][$action] = $scriptProperties;
} else {
    // ... to cache file
    $modx->cacheManager->set('fetchit/props_' . $action, $scriptProperties, 3600);
}

// Call snippet for preparation of form
$action = !empty($_SERVER['HTTP_X_FETCHIT_ACTION'])
    ? $_SERVER['HTTP_X_FETCHIT_ACTION']
    : $action;

$FetchIt->process($action, $_REQUEST);

// Return chunk
return $content;
