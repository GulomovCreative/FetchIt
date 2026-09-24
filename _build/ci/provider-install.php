<?php
/**
 * Install a package from the modx.com repository with the code the setup
 * resolver uses for FormIt.
 *
 * Usage: MODX_CORE_PATH=/path/to/core/ php _build/ci/provider-install.php <package name>
 */

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php provider-install.php <package name>\n");
    exit(1);
}

/** @var modX $modx */
$modx = require __DIR__ . '/bootstrap.inc.php';

if (!class_exists('xPDOTransport')) {
    $modx->loadClass('transport.xPDOTransport', XPDO_CORE_PATH, true, true);
}

// Loaded for uninstall, the resolver only defines its helpers.
$transport = new xPDOTransport($modx, 'provider-install', sys_get_temp_dir() . '/');
$options = [xPDOTransport::PACKAGE_ACTION => xPDOTransport::ACTION_UNINSTALL];
require dirname(__DIR__) . '/resolvers/setup.php';

/** @var Closure $installPackage */
$response = $installPackage($argv[1], ['service_url' => 'modx.com']);
$modx->cacheManager->refresh();

echo strip_tags($response['message']), "\n";
exit($response['success'] ? 0 : 1);
