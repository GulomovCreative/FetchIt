<?php

/** @var modX $modx */
/** @var array $scriptProperties */
/** @var FetchIt $FetchIt */

switch ($modx->event->name) {
    case 'OnWebPagePrerender':
        if ($modx->loadClass('fetchit', MODX_CORE_PATH . 'components/fetchit/model/', false, true)) {
            FetchIt::service($modx)->registerScript();
        }
        break;
}
