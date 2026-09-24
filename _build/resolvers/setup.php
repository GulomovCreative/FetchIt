<?php
/** @var xPDOTransport $transport */
/** @var array $options */
/** @var modX $modx */
if (!$transport->xpdo || !($transport instanceof xPDOTransport)) {
    return false;
}

$modx =& $transport->xpdo;
$packages = [
    'FormIt' => [
        'version' => '4.0.1-pl',
        'service_url' => 'modx.com',
    ],
];

/**
 * Download $src into $dst. Returns null on success, or what went wrong.
 */
$downloadPackage = function ($src, $dst) {
    $file = false;
    if (ini_get('allow_url_fopen')) {
        $file = @file_get_contents($src);
    } elseif (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $src);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        if (!ini_get('open_basedir')) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        }
        $file = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) {
            return "the server answered HTTP {$status}";
        }
    } else {
        return 'neither allow_url_fopen nor cURL is available';
    }

    if ($file === false || $file === '') {
        return 'nothing was downloaded';
    }
    if (file_put_contents($dst, $file) === false) {
        return "could not write {$dst}";
    }

    return null;
};

/**
 * The package list from a provider answer: [SimpleXMLElement, null] or
 * [null, what went wrong]. MODX 2 answers with modRestResponse; MODX 3 with
 * a PSR-7 response, or false when the provider could not be reached.
 */
$readAnswer = function ($response) {
    if (!is_object($response)) {
        return [null, 'could not reach the package provider, see the error log'];
    }
    if (method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 400) {
        return [null, 'the package provider answered HTTP ' . $response->getStatusCode()];
    }

    $body = '';
    if (method_exists($response, 'getBody')) {
        $body = (string)$response->getBody();
    } elseif (isset($response->response)) {
        $body = (string)$response->response;
    }

    $internal = libxml_use_internal_errors(true);
    $xml = $body !== '' ? simplexml_load_string($body) : false;
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($internal);

    if ($xml === false) {
        $reason = $errors ? trim($errors[0]->message) : 'empty answer';

        return [null, "the package provider answered with something that is not XML ({$reason})"];
    }

    return [$xml, null];
};

$installPackage = function ($packageName, $options = []) use ($modx, $downloadPackage, $readAnswer) {
    /** @var modTransportProvider $provider */
    if (!empty($options['service_url'])) {
        $provider = $modx->getObject('transport.modTransportProvider', [
            'service_url:LIKE' => '%' . $options['service_url'] . '%',
        ]);
    }
    if (empty($provider)) {
        $provider = $modx->getObject('transport.modTransportProvider', 1);
    }
    if (empty($provider)) {
        return [
            'success' => 0,
            'message' => "No package provider to download <b>{$packageName}</b> from. Install it from the Package Manager.",
        ];
    }
    $modx->getVersionData();
    $productVersion = $modx->version['code_name'] . '-' . $modx->version['full_version'];

    list($foundPackages, $error) = $readAnswer($provider->request('package', 'GET', [
        'supports' => $productVersion,
        'query' => $packageName,
    ]));
    if ($error !== null) {
        return [
            'success' => 0,
            'message' => "Could not look for <b>{$packageName}</b>: {$error}. Install it from the Package Manager.",
        ];
    }

    foreach ($foundPackages as $foundPackage) {
        /** @noinspection PhpUndefinedFieldInspection */
        if ($foundPackage->name != $packageName) {
            continue;
        }

        $signature = (string)$foundPackage->signature;
        $sig = explode('-', $signature);
        $versionSignature = explode('.', $sig[1]);
        /** @noinspection PhpUndefinedFieldInspection */
        $url = (string)$foundPackage->location;
        $zip = $modx->getOption('core_path') . 'packages/' . $signature . '.transport.zip';

        $error = $downloadPackage($url, $zip);
        if ($error !== null) {
            return [
                'success' => 0,
                'message' => "Could not download <b>{$packageName}</b> from {$url}: {$error}.",
            ];
        }

        // Add in the package as an object so it can be upgraded
        /** @var modTransportPackage $package */
        $package = $modx->newObject('transport.modTransportPackage');
        $package->set('signature', $signature);
        $package->fromArray([
            'created' => date('Y-m-d H:i:s'),
            'updated' => null,
            'state' => 1,
            'workspace' => 1,
            'provider' => $provider->get('id'),
            'source' => $signature . '.transport.zip',
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

        if (!$package->save()) {
            @unlink($zip);

            return [
                'success' => 0,
                'message' => "Could not register the package <b>{$signature}</b>.",
            ];
        }
        if (!$package->install()) {
            // Leave nothing behind that would skip the install next time.
            $package->remove();
            @unlink($zip);

            return [
                'success' => 0,
                'message' => "Could not install <b>{$signature}</b>, see the error log.",
            ];
        }

        return [
            'success' => 1,
            'message' => "<b>{$packageName}</b> was successfully installed",
        ];
    }

    return [
        'success' => 0,
        'message' => "Could not find <b>{$packageName}</b> in MODX repository",
    ];
};

$success = false;
switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        foreach ($packages as $name => $data) {
            if (!is_array($data)) {
                $data = ['version' => $data];
            }
            $installed = $modx->getIterator('transport.modTransportPackage', ['package_name' => $name]);
            /** @var modTransportPackage $package */
            foreach ($installed as $package) {
                if ($package->compareVersion($data['version'], '<=')) {
                    continue(2);
                }
            }
            $modx->log(modX::LOG_LEVEL_INFO, "Trying to install <b>{$name}</b>. Please wait...");
            $response = $installPackage($name, $data);
            $level = $response['success']
                ? modX::LOG_LEVEL_INFO
                : modX::LOG_LEVEL_ERROR;
            $modx->log($level, $response['message']);
        }
        $success = true;
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        $success = true;
        break;
}

return $success;
