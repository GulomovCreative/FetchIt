<?php

/**
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

$modx::getLoader()->addPsr4('FetchIt\\', $namespace['path'] . 'src/');

$modx->services->add('FetchIt', function() use ($modx) {
    return new \FetchIt\FetchIt($modx);
});
