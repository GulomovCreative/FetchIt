<?php
/**
 * Give fetchit.protection.secret a random key on install and upgrade when it
 * is empty. Without it FetchIt derives the key from the $site_id of the MODX
 * config, which manager users can see.
 *
 * @var xPDOTransport $transport
 * @var array $options
 */

if (!$transport->xpdo || !($transport instanceof xPDOTransport)) {
    return false;
}

$modx = $transport->xpdo;
switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        $setting = $modx->getObject('modSystemSetting', ['key' => 'fetchit.protection.secret']);
        if (!$setting) {
            $modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] No fetchit.protection.secret setting to fill in; the key comes from $site_id');
            break;
        }
        if ((string)$setting->get('value') === '') {
            $setting->set('value', bin2hex(random_bytes(32)));
            if ($setting->save()) {
                $modx->cacheManager->refresh(['system_settings' => []]);
                $modx->log(modX::LOG_LEVEL_INFO, 'FetchIt: generated the key that signs the protection tokens');
            } else {
                $modx->log(modX::LOG_LEVEL_ERROR, '[FetchIt] Could not save fetchit.protection.secret; the key comes from $site_id');
            }
        }
        break;
}

return true;
