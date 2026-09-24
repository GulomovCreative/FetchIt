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

// Run the resolver as for an uninstall: it then only defines $installPackage
// and installs nothing.
$transport = new xPDOTransport($modx, 'provider-install', sys_get_temp_dir() . '/');
$options = [xPDOTransport::PACKAGE_ACTION => xPDOTransport::ACTION_UNINSTALL];
require dirname(__DIR__) . '/resolvers/setup.php';

/** @var Closure $installPackage */
$response = $installPackage($argv[1], ['service_url' => 'modx.com']);
$modx->cacheManager->refresh();

fwrite($response['success'] ? STDOUT : STDERR, strip_tags($response['message']) . "\n");
exit($response['success'] ? 0 : 1);
