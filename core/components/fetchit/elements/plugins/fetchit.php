<?php

use FetchIt\FetchIt;

/** @var modX $modx */
/** @var array $scriptProperties */
/** @var FetchIt $FetchIt */

switch ($modx->event->name) {
    case 'OnWebPagePrerender':
        $isMODX3 = class_exists('MODX\Revolution\modX');

        if ($FetchIt =
            $isMODX3 && $modx->services->has(FetchIt::class) ?
            $modx->services->get(FetchIt::class) :
            $modx->getService('FetchIt', FetchIt::class, MODX_CORE_PATH . 'components/fetchit/model/')
        ) {
            $FetchIt->registerScript();
        }

        break;
}
