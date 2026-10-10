<?php
/** @var modX $modx */
/** @var array $scriptProperties */
// For a page whose FetchIt forms come later (a modal loaded over AJAX,
// pdoPage, mFilter2): the plugin adds the script, the script of the captcha
// and the bootstrap, as on a page with a form. Call it uncached:
// [[!FetchItScript]].
if (!$modx->loadClass('fetchit', MODX_CORE_PATH . 'components/fetchit/model/', false, true)) {
    return '';
}
FetchIt::service($modx)->loadScript();

return '';
