<?php

use MODX\Revolution\modX as modX3;
use MODX\Revolution\Transport\modTransportPackage as modTransportPackage3;
use MODX\Revolution\Transport\modTransportProvider as modTransportProvider3;
use xPDO\Transport\xPDOTransport as xPDOTransport3;

/** @var xPDOTransport|xPDOTransport3 $transport */
/** @var array $options */
/** @var modX|modX3 $modx */
if (!$transport->xpdo || !($transport instanceof xPDOTransport)) {
    return false;
}

$modx = $transport->xpdo;

if (!defined('MODX3')) {
    define('MODX3', class_exists('MODX\Revolution\modX'));
}

$packages = [
    'FormIt' => [
        'version' => '4.0.1-pl',
        'service_url' => 'modx.com',
    ],
];

$downloadPackage = function ($src, $dst) {
    if (ini_get('allow_url_fopen')) {
        $file = @file_get_contents($src);
    } else {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $src);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            $safeMode = @ini_get('safe_mode');
            $openBasedir = @ini_get('open_basedir');
            if (empty($safeMode) && empty($openBasedir)) {
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            }

            $file = curl_exec($ch);
            curl_close($ch);
        } else {
            return false;
        }
    }
    file_put_contents($dst, $file);

    return file_exists($dst);
};

$installPackage = function ($packageName, $options = []) use ($modx, $downloadPackage) {
    /** @var modTransportProvider|modTransportProvider3 $provider */
    if (!empty($options['service_url'])) {
        $provider = $modx->getObject(MODX3 ? modTransportProvider3::class : 'transport.modTransportProvider', [
            'service_url:LIKE' => '%' . $options['service_url'] . '%',
        ]);
    }
    if (empty($provider)) {
        $provider = $modx->getObject(MODX3 ? modTransportProvider3::class : 'transport.modTransportProvider', 1);
    }
    $modx->getVersionData();
    $productVersion = $modx->version['code_name'] . '-' . $modx->version['full_version'];

    $response = $provider->request('package', 'GET', [
        'supports' => $productVersion,
        'query' => $packageName,
    ]);

    if (empty($response)) {
        return [
            'success' => 0,
            'message' => "Could not find <b>{$packageName}</b> in MODX repository",
        ];
    }

    $foundPackages = simplexml_load_string(MODX3 ? $response->getBody()->getContents() : $response->response);
    foreach ($foundPackages as $foundPackage) {
        /** @var modTransportPackage $foundPackage */
        /** @noinspection PhpUndefinedFieldInspection */
        if ((string)$foundPackage->name === $packageName) {
            if (MODX3) {
                /** @var modTransportPackage $package */
                $package = $provider->transfer((string)$foundPackage->signature);
                if ($package && $package->install()) {
                    return [
                        'success' => 1,
                        'message' => "<b>{$packageName}</b> was successfully installed",
                    ];
                }
            } else {
                $sig = explode('-', (string)$foundPackage->signature);
                $versionSignature = explode('.', $sig[1]);
                /** @noinspection PhpUndefinedFieldInspection */
                $url = $foundPackage->location;

                if (!$downloadPackage($url, $modx->getOption('core_path') . 'packages/' . $foundPackage->signature . '.transport.zip')) {
                    return [
                        'success' => 0,
                        'message' => "Could not download package <b>{$packageName}</b>.",
                    ];
                }

                // Add in the package as an object so it can be upgraded
                /** @var modTransportPackage $package */
                $package = $modx->newObject('transport.modTransportPackage');
                $package->set('signature', $foundPackage->signature);
                /** @noinspection PhpUndefinedFieldInspection */
                $package->fromArray([
                    'created' => date('Y-m-d h:i:s'),
                    'updated' => null,
                    'state' => 1,
                    'workspace' => 1,
                    'provider' => $provider->get('id'),
                    'source' => $foundPackage->signature . '.transport.zip',
                    'package_name' => $packageName,
                    'version_major' => $versionSignature[0],
                    'version_minor' => !empty($versionSignature[1]) ? $versionSignature[1] : 0,
                    'version_patch' => !empty($versionSignature[2]) ? $versionSignature[2] : 0,
                ]);

                if (!empty($sig[2])) {
                    $r = preg_split('/([0-9]+)/', $sig[2], -1, PREG_SPLIT_DELIM_CAPTURE);
                    if (is_array($r) && !empty($r)) {
                        $package->set('release', $r[0]);
                        $package->set('release_index', (isset($r[1]) ? $r[1] : '0'));
                    } else {
                        $package->set('release', $sig[2]);
                    }
                }

                if ($package->save() && $package->install()) {
                    return [
                        'success' => 1,
                        'message' => "<b>{$packageName}</b> was successfully installed",
                    ];
                }
            }

            return [
                'success' => 0,
                'message' => "Could not save package <b>{$packageName}</b>",
            ];
        }
    }

    return true;
};

$success = false;
switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        foreach ($packages as $name => $data) {
            if (!is_array($data)) {
                $data = ['version' => $data];
            }
            $installed = $modx->getIterator(MODX3 ? modTransportPackage3::class : 'transport.modTransportPackage', ['package_name' => $name]);
            /** @var modTransportPackage|modTransportPackage3 $package */
            foreach ($installed as $package) {
                if ($package->compareVersion($data['version'], '<=')) {
                    continue(2);
                }
            }
            $modx->log(modX::LOG_LEVEL_INFO, "Trying to install <b>{$name}</b>. Please wait...");
            $response = $installPackage($name, $data);
            if (is_array($response)) {
                $level = $response['success']
                    ? modX::LOG_LEVEL_INFO
                    : modX::LOG_LEVEL_ERROR;
                $modx->log($level, $response['message']);
            }
        }
        $success = true;
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        $success = true;
        break;
}

return $success;
