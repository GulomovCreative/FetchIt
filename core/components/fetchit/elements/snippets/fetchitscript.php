<?php
/** @var modX $modx */
/** @var array $scriptProperties */
// For a page whose FetchIt forms come later (a modal loaded over AJAX,
// pdoPage, mFilter2): the plugin adds the script, the script of the captcha
// and the bootstrap, as on a page with a form: [[!FetchItScript]]. A cached
// call works too: the plugin finds the marker in the cached page and takes
// it out. loadScript() covers an uncached call whose marker is lost (escaped
// by an output filter, stripped by another plugin).
if (!$modx->loadClass('fetchit', MODX_CORE_PATH . 'components/fetchit/model/', false, true)) {
    return '';
}
FetchIt::service($modx)->loadScript();

return FetchIt::SCRIPT_MARKER;
