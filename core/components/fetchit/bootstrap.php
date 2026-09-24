<?php
/**
 * MODX 3 runs this for the fetchit namespace on every request; MODX 2 does
 * not. It keeps the FetchIt 3.x API: $modx->services->get('FetchIt') and
 * the FetchIt\FetchIt class. Nothing here may throw: MODX runs namespace
 * bootstraps without a try/catch, for the manager too.
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

$modx::getLoader()->addPsr4('FetchIt\\', $namespace['path'] . 'src/');

// The class may already come from fetchit.core_path, a copy elsewhere.
if (!class_exists('FetchIt', false)) {
    require_once __DIR__ . '/model/fetchit.class.php';
}
FetchIt::register($modx);
