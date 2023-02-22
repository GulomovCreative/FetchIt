<?php
/** @var xPDOTransport $transport */
/** @var array $options */
/** @var modX $modx */
if ($transport->xpdo) {
    $modx =& $transport->xpdo;

    $dev = MODX_BASE_PATH . 'Extras/FetchIt/';
    /** @var xPDOCacheManager $cache */
    $cache = $modx->getCacheManager();
    if (file_exists($dev) && $cache) {
        if (!is_link($dev . 'assets/components/fetchit')) {
            $cache->deleteTree(
                $dev . 'assets/components/fetchit/',
                ['deleteTop' => true, 'skipDirs' => false, 'extensions' => []]
            );
            symlink(MODX_ASSETS_PATH . 'components/fetchit/', $dev . 'assets/components/fetchit');
        }
        if (!is_link($dev . 'core/components/fetchit')) {
            $cache->deleteTree(
                $dev . 'core/components/fetchit/',
                ['deleteTop' => true, 'skipDirs' => false, 'extensions' => []]
            );
            symlink(MODX_CORE_PATH . 'components/fetchit/', $dev . 'core/components/fetchit');
        }
    }
}

return true;