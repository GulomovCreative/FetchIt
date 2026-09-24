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
if (!$modx->initialize('mgr')) {
    fwrite(STDERR, "Could not initialize MODX.\n");
    exit(1);
}
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

/**
 * The class key of a core class: namespaced on MODX 3, the legacy class key
 * on MODX 2.
 */
function modx_class($name)
{
    $modern = [
        'modChunk' => 'MODX\Revolution\modChunk',
        'modPlugin' => 'MODX\Revolution\modPlugin',
        'modResource' => 'MODX\Revolution\modResource',
        'modSnippet' => 'MODX\Revolution\modSnippet',
        'modSystemSetting' => 'MODX\Revolution\modSystemSetting',
        'modTransportPackage' => 'MODX\Revolution\Transport\modTransportPackage',
    ];
    $legacy = [
        'modTransportPackage' => 'transport.modTransportPackage',
    ];

    if (!isset($modern[$name])) {
        throw new InvalidArgumentException("modx_class() does not know {$name}; add it to the map.");
    }
    if (class_exists('MODX\Revolution\modX')) {
        return $modern[$name];
    }

    return isset($legacy[$name]) ? $legacy[$name] : $name;
}

return $modx;
