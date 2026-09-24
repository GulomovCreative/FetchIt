<?php
/**
 * Set a system setting and clear the cache.
 *
 * Usage: MODX_CORE_PATH=/path/to/core/ php _build/ci/setting.php <key> <value>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php setting.php <key> <value>\n");
    exit(1);
}

/** @var modX $modx */
$modx = require __DIR__ . '/bootstrap.inc.php';

$setting = $modx->getObject(modx_class('modSystemSetting'), ['key' => $argv[1]]);
if (!$setting) {
    fwrite(STDERR, "No system setting {$argv[1]}.\n");
    exit(1);
}
$setting->set('value', $argv[2]);
if (!$setting->save()) {
    fwrite(STDERR, "Could not save {$argv[1]}.\n");
    exit(1);
}

$modx->cacheManager->refresh();
echo "{$argv[1]} = {$argv[2]}\n";
