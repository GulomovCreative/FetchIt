<?php
/** @var modX $modx */
/** @var FetchIt $FetchIt */
/** @var array $scriptProperties */
if (!$modx->loadClass('fetchit', MODX_CORE_PATH . 'components/fetchit/model/', false, true)) {
    return false;
}
$FetchIt = new FetchIt($modx, $scriptProperties);

$tpl = $modx->getOption('form', $scriptProperties, 'tpl.FetchIt.example', true);
$action = md5(http_build_query($scriptProperties));

// Save snippet properties
$FetchIt->storeActionProperties($action, $scriptProperties);

// Run FormIt or the processing snippet before the form renders, so that its
// placeholders (values, errors, the success message) are set by then:
// output filters such as [[+fi.success:is=`1`...]] are evaluated when the
// chunk renders. See FetchIt::processPage().
$FetchIt->processPage(!empty($_SERVER['HTTP_X_FETCHIT_ACTION']) ? $_SERVER['HTTP_X_FETCHIT_ACTION'] : $action, $scriptProperties);

// pdoTools for Fenom and @FILE chunks, on MODX 2 and MODX 3
if ($pdo = FetchIt::pdoTools($modx)) {
    $content = $pdo->getChunk($tpl, $scriptProperties);
} else {
    if (strpos($tpl, '@') === 0) {
        $modx->log(modX::LOG_LEVEL_ERROR, "[FetchIt] The form \"{$tpl}\" needs pdoTools, which is not installed or not loaded");
    }
    $content = $modx->getChunk($tpl, $scriptProperties);
}
if (empty($content)) {
    return $modx->lexicon('fetchit_err_chunk_nf', array('name' => $tpl));
}

// Every form gets method="post", the action key the script looks for, and
// the service fields of the protection
$content = $FetchIt->prepareForm($content, $action);
$FetchIt->loadScript($action);

return $content;
