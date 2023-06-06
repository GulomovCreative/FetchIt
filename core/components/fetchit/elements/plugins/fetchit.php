<?php

/** @var modX $modx */
/** @var array $scriptProperties */
/** @var FetchIt $FetchIt */

switch ($modx->event->name) {
    case 'OnWebPagePrerender':
        if ($FetchIt = $modx->services->get('FetchIt')) {
            $FetchIt->registerScript();
        }

        break;
}
