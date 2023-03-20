<?php

/** @var modX $modx */
/** @var array $scriptProperties */
/** @var FetchIt $FetchIt */

switch ($modx->event->name) {
    case 'OnWebPagePrerender':
        if ($FetchIt = $modx->getService('FetchIt', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/')) {
            $FetchIt->registerScript();
        }
        break;
}
