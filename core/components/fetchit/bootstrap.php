<?php
/**
 * MODX 3 runs this for the fetchit namespace on every request; MODX 2 does
 * not. It keeps the FetchIt 3.x API: $modx->services->get('FetchIt') and
 * the FetchIt\FetchIt class.
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

$modx::getLoader()->addPsr4('FetchIt\\', $namespace['path'] . 'src/');

if (!$modx->services->has('FetchIt')) {
    $modx->services->add('FetchIt', function () use ($modx) {
        require_once __DIR__ . '/model/fetchit.class.php';

        return new FetchIt($modx);
    });
}
