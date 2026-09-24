<?php
/**
 * Install a transport package into MODX 2 or MODX 3.
 *
 * Usage: MODX_CORE_PATH=/path/to/core/ php _build/ci/install.php <package.transport.zip>
 */

$file = isset($argv[1]) ? $argv[1] : '';
if (!is_file($file)) {
    fwrite(STDERR, "Usage: php install.php <package.transport.zip>\n");
    exit(1);
}

/** @var modX $modx */
$modx = require __DIR__ . '/bootstrap.inc.php';

$source = basename($file);
$signature = preg_replace('/\.transport\.zip$/', '', $source);
if (!copy($file, MODX_CORE_PATH . 'packages/' . $source)) {
    fwrite(STDERR, "Could not copy {$source} into core/packages/.\n");
    exit(1);
}

// fetchit-1.1.3-pl: name, version, release.
list($name, $version, $release) = array_pad(explode('-', $signature, 3), 3, '');
$parts = array_pad(explode('.', $version), 3, 0);
preg_match('/^([a-z]+)(\d*)$/i', $release, $match);

$class = modx_class('modTransportPackage');
$package = $modx->getObject($class, ['signature' => $signature]);
if (!$package) {
    $package = $modx->newObject($class);
    $package->set('signature', $signature);
}
$package->fromArray([
    'created' => date('Y-m-d H:i:s'),
    'state' => 1,
    'workspace' => 1,
    'provider' => 0,
    'source' => $source,
    'package_name' => $name,
    'version_major' => (int)$parts[0],
    'version_minor' => (int)$parts[1],
    'version_patch' => (int)$parts[2],
    'release' => isset($match[1]) ? $match[1] : $release,
    'release_index' => !empty($match[2]) ? (int)$match[2] : 0,
]);

if (!$package->save() || !$package->install()) {
    fwrite(STDERR, "Could not install {$signature}.\n");
    exit(1);
}

$modx->cacheManager->refresh();
echo "Installed {$signature}\n";
