<?php
/**
 * Start MODX 2 or MODX 3 from the command line for the CI scripts.
 * MODX_CORE_PATH in the environment points at the site's core directory.
 */

$core = getenv('MODX_CORE_PATH');
if (!$core || !is_file(rtrim($core, '/') . '/config/config.inc.php')) {
    fwrite(STDERR, "MODX_CORE_PATH must point at an installed MODX core.\n");
    exit(1);
}

define('MODX_API_MODE', true);
require rtrim($core, '/') . '/config/config.inc.php';
require MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

/**
 * The class key of a core class: namespaced on MODX 3, bare on MODX 2.
 */
function modx_class($name)
{
    $modern = [
        'modChunk' => 'MODX\Revolution\modChunk',
        'modResource' => 'MODX\Revolution\modResource',
        'modSnippet' => 'MODX\Revolution\modSnippet',
        'modTransportPackage' => 'MODX\Revolution\Transport\modTransportPackage',
    ];
    $legacy = [
        'modTransportPackage' => 'transport.modTransportPackage',
    ];

    if (class_exists('MODX\Revolution\modX')) {
        return $modern[$name];
    }

    return isset($legacy[$name]) ? $legacy[$name] : $name;
}

return $modx;
