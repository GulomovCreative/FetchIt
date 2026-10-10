<?php
/**
 * Create the table fetchit_store (used by fetchit.protection.store = db) on
 * install and upgrade, and drop it on uninstall. It never stops the
 * installation: with the default store (files) the table is not used, and a
 * database user that may not create tables gets a warning in the install log.
 *
 * @var xPDOTransport $transport
 * @var array $options
 */

if (!$transport->xpdo || !($transport instanceof xPDOTransport)) {
    return false;
}

$modx = $transport->xpdo;

if ((int)$options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) {
    // MODX removes the files of the component before this resolver runs on
    // uninstall, so FetchItDbStore cannot be loaded: plain SQL here.
    $table = $modx->escape($modx->getOption('table_prefix', null, '') . 'fetchit_store');
    $run = function ($sql) use ($modx) {
        try {
            $statement = $modx->prepare($sql);

            return $statement !== false && $statement->execute();
        } catch (PDOException $exception) {
            return false;
        }
    };
    if ($run("SELECT 1 FROM {$table} WHERE 1 = 0") && !$run("DROP TABLE {$table}")) {
        $modx->log(modX::LOG_LEVEL_WARN, '[FetchIt] Could not drop the table fetchit_store');
    }

    return true;
}

if (!class_exists('FetchItDbStore')) {
    $model = $modx->getOption('fetchit.core_path', null, $modx->getOption('core_path') . 'components/fetchit/') . 'model/';
    if (!is_readable($model . 'fetchitdbstore.class.php')) {
        $modx->log(modX::LOG_LEVEL_WARN, '[FetchIt] The files of FetchIt are missing; the table fetchit_store was not created');

        return true;
    }
    require_once $model . 'fetchitstore.class.php';
    require_once $model . 'fetchitdbstore.class.php';
}

$store = new FetchItDbStore($modx, function ($what) use ($modx) {
    $modx->log(modX::LOG_LEVEL_WARN, '[FetchIt] ' . $what);
});
switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        if (!$store->install()) {
            $modx->log(modX::LOG_LEVEL_WARN, '[FetchIt] Could not create the table fetchit_store;'
                . ' with fetchit.protection.store db the forms are refused until it exists');
        }
        break;
}

return true;
