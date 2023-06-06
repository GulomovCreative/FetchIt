<?php

use MODX\Revolution\modX;
use MODX\Revolution\Error\modError;
use MODX\Revolution\modCategory;
use MODX\Revolution\Processors\System\ClearCache;
use MODX\Revolution\Transport\modPackageBuilder;
use MODX\Revolution\Transport\modTransportPackage;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modChunk;
use xPDO\Transport\xPDOTransport;
use xPDO\xPDO;

/** @var array $config */
if (!file_exists(__DIR__ . '/config.inc.php')) {
    exit('Could not load MODX config. Please specify correct MODX_CORE_PATH constant in config file!');
}
$config = require(__DIR__ . '/config.inc.php');
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

if (!defined('LOG_LEVEL_INFO')) {
    define('LOG_LEVEL_INFO', xPDO::LOG_LEVEL_INFO);
}

if (!defined('LOG_LEVEL_ERROR')) {
    define('LOG_LEVEL_ERROR', xPDO::LOG_LEVEL_ERROR);
}

if (!defined('XPDO_PHP_VERSION')) {
    define('XPDO_PHP_VERSION', PHP_VERSION);
}

class FetchItPackage
{
    /** @var modX $modx */
    public $modx;
    /** @var array $config */
    public $config = [];

    /** @var modPackageBuilder $builder */
    public $builder;
    /** @var modCategory $vehicle */
    public $category;
    public $category_attributes = [];

    protected $_idx = 1;


    /**
     * FetchItPackage constructor.
     *
     * @param $core_path
     * @param array $config
     */
    public function __construct($modX, array $config = [])
    {
        $this->modx = $modX;
        $this->modx->initialize('mgr');

        if (!$this->modx->services->has('error')) {
            $this->modx->services->add('error', new modError($this->modx));
        }
        $this->modx->error = $this->modx->services->get('error');

        $root = dirname(__FILE__, 2) . '/';
        $assets = $root . 'assets/components/' . $config['name_lower'] . '/';
        $core = $root . 'core/components/' . $config['name_lower'] . '/';

        $this->config = array_merge([
            'log_level' => LOG_LEVEL_INFO,
            'log_target' => XPDO_CLI_MODE ? 'ECHO' : 'HTML',

            'root' => $root,
            'build' => $root . '_build/',
            'elements' => $root . '_build/elements/',
            'resolvers' => $root . '_build/resolvers/',

            'assets' => $assets,
            'core' => $core,
        ], $config);
        $this->modx->setLogLevel($this->config['log_level']);
        $this->modx->setLogTarget($this->config['log_target']);

        $this->initialize();
    }


    /**
     * Initialize package builder
     */
    protected function initialize()
    {
        $this->builder = new modPackageBuilder($this->modx);
        $this->builder->createPackage($this->config['name_lower'], $this->config['version'], $this->config['release']);

        $this->builder->registerNamespace($this->config['name_lower'], false, true, '{core_path}components/' . $this->config['name_lower'] . '/');
        $this->modx->log(LOG_LEVEL_INFO, 'Created Transport Package and Namespace.');

        $this->category = $this->modx->newObject(modCategory::class);
        $this->category->set('category', $this->config['name']);
        $this->category_attributes = [
            xPDOTransport::UNIQUE_KEY => 'category',
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::RELATED_OBJECTS => true,
            xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [],
        ];
        $this->modx->log(LOG_LEVEL_INFO, 'Created main Category.');
    }


    /**
     * Install nodejs and update assets
     */
    protected function assets()
    {
        $output = [];
        if (!file_exists($this->config['build'] . 'node_modules')) {
            putenv('PATH=' . trim(shell_exec('echo $PATH')) . ':' . dirname(MODX_BASE_PATH) . '/');
            if (file_exists($this->config['build'] . 'package.json')) {
                $this->modx->log(LOG_LEVEL_INFO, 'Trying to install or update nodejs dependencies');
                $output = [
                    shell_exec('cd ' . $this->config['build'] . ' && npm config set scripts-prepend-node-path true && npm install'),
                ];
            }
            if (file_exists($this->config['build'] . 'gulpfile.js')) {
                $output = array_merge($output, [
                    shell_exec('cd ' . $this->config['build'] . ' && npm link gulp'),
                    shell_exec('cd ' . $this->config['build'] . ' && gulp copy'),
                ]);
            }
            if ($output) {
                $this->modx->log(LOG_LEVEL_INFO, implode("\n", array_map('trim', $output)));
            }
        }
        if (file_exists($this->config['build'] . 'gulpfile.js')) {
            $output = shell_exec('cd ' . $this->config['build'] . ' && gulp default 2>&1');
            $this->modx->log(LOG_LEVEL_INFO, 'Compile scripts and styles ' . trim($output));
        }
    }


    /**
     * Add settings
     */
    protected function settings()
    {
        $settings = include($this->config['elements'] . 'settings.php');
        if (!is_array($settings)) {
            $this->modx->log(LOG_LEVEL_ERROR, 'Could not package in System Settings');

            return;
        }
        $attributes = [
            xPDOTransport::UNIQUE_KEY => 'key',
            xPDOTransport::PRESERVE_KEYS => true,
            xPDOTransport::UPDATE_OBJECT => !empty($this->config['update']['settings']),
            xPDOTransport::RELATED_OBJECTS => false,
        ];
        foreach ($settings as $name => $data) {
            /** @var modSystemSetting $setting */
            $setting = $this->modx->newObject(modSystemSetting::class);
            $setting->fromArray(array_merge([
                'key' => $this->config['name_lower'] . '.' . $name,
                'namespace' => $this->config['name_lower'],
            ], $data), '', true, true);
            $vehicle = $this->builder->createVehicle($setting, $attributes);
            $this->builder->putVehicle($vehicle);
        }
        $this->modx->log(LOG_LEVEL_INFO, 'Packaged in ' . count($settings) . ' System Settings');
    }


    /**
     * Add plugins
     */
    protected function plugins()
    {
        $plugins = include($this->config['elements'] . 'plugins.php');
        if (!is_array($plugins)) {
            $this->modx->log(LOG_LEVEL_ERROR, 'Could not package in Plugins');

            return;
        }
        $this->category_attributes[xPDOTransport::RELATED_OBJECT_ATTRIBUTES]['Plugins'] = [
            xPDOTransport::UNIQUE_KEY => 'name',
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => !empty($this->config['update']['plugins']),
            xPDOTransport::RELATED_OBJECTS => true,
            xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
                'PluginEvents' => [
                    xPDOTransport::PRESERVE_KEYS => true,
                    xPDOTransport::UPDATE_OBJECT => true,
                    xPDOTransport::UNIQUE_KEY => ['pluginid', 'event'],
                ],
            ],
        ];
        $objects = [];
        foreach ($plugins as $name => $data) {
            /** @var modPlugin $plugin */
            $plugin = $this->modx->newObject(modPlugin::class);
            $plugin->fromArray(array_merge([
                'name' => $name,
                'category' => 0,
                'description' => @$data['description'],
                'plugincode' => $this::_getContent($this->config['core'] . 'elements/plugins/' . $data['file'] . '.php'),
                'static' => !empty($this->config['static']['plugins']),
                'source' => 1,
                'static_file' => 'core/components/' . $this->config['name_lower'] . '/elements/plugins/' . $data['file'] . '.php',
            ], $data), '', true, true);

            $events = [];
            if (!empty($data['events'])) {
                foreach ($data['events'] as $event_name => $event_data) {
                    /** @var modPluginEvent $event */
                    $event = $this->modx->newObject(modPluginEvent::class);
                    $event->fromArray(array_merge([
                        'event' => $event_name,
                        'priority' => 0,
                        'propertyset' => 0,
                    ], $event_data), '', true, true);
                    $events[] = $event;
                }
            }
            if (!empty($events)) {
                $plugin->addMany($events);
            }
            $objects[] = $plugin;
        }
        $this->category->addMany($objects);
        $this->modx->log(LOG_LEVEL_INFO, 'Packaged in ' . count($objects) . ' Plugins');
    }


    /**
     * Add snippets
     */
    protected function snippets()
    {
        $snippets = include($this->config['elements'] . 'snippets.php');
        if (!is_array($snippets)) {
            $this->modx->log(LOG_LEVEL_ERROR, 'Could not package in Snippets');

            return;
        }
        $this->category_attributes[xPDOTransport::RELATED_OBJECT_ATTRIBUTES]['Snippets'] = [
            xPDOTransport::UNIQUE_KEY => 'name',
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => !empty($this->config['update']['snippets']),
        ];
        $objects = [];
        foreach ($snippets as $name => $data) {
            /** @var modSnippet $objects */
            $objects[$name] = $this->modx->newObject(modSnippet::class);
            $objects[$name]->fromArray(array_merge([
                'id' => 0,
                'name' => $name,
                'description' => @$data['description'],
                'snippet' => $this::_getContent($this->config['core'] . 'elements/snippets/' . $data['file'] . '.php'),
                'static' => !empty($this->config['static']['snippets']),
                'source' => 1,
                'static_file' => 'core/components/' . $this->config['name_lower'] . '/elements/snippets/' . $data['file'] . '.php',
            ], $data), '', true, true);
            $properties = [];
            foreach (@$data['properties'] as $k => $v) {
                $properties[] = array_merge([
                    'name' => $k,
                    'desc' => $this->config['name_lower'] . '_prop_' . $k,
                    'lexicon' => $this->config['name_lower'] . ':properties',
                ], $v);
            }
            $objects[$name]->setProperties($properties);
        }
        $this->category->addMany($objects);
        $this->modx->log(LOG_LEVEL_INFO, 'Packaged in ' . count($objects) . ' Snippets');
    }


    /**
     * Add chunks
     */
    protected function chunks()
    {
        $chunks = include($this->config['elements'] . 'chunks.php');
        if (!is_array($chunks)) {
            $this->modx->log(LOG_LEVEL_ERROR, 'Could not package in Chunks');

            return;
        }
        $this->category_attributes[xPDOTransport::RELATED_OBJECT_ATTRIBUTES]['Chunks'] = [
            xPDOTransport::UNIQUE_KEY => 'name',
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => !empty($this->config['update']['chunks']),
        ];
        $objects = [];
        foreach ($chunks as $name => $data) {
            /** @var modChunk $objects */
            $objects[$name] = $this->modx->newObject(modChunk::class);
            $objects[$name]->fromArray(array_merge([
                'id' => 0,
                'name' => $name,
                'description' => @$data['description'],
                'snippet' => $this::_getContent($this->config['core'] . 'elements/chunks/' . $data['file'] . '.tpl'),
                'static' => !empty($this->config['static']['chunks']),
                'source' => 1,
                'static_file' => 'core/components/' . $this->config['name_lower'] . '/elements/chunks/' . $data['file'] . '.tpl',
            ], $data), '', true, true);
            $objects[$name]->setProperties(@$data['properties']);
        }
        $this->category->addMany($objects);
        $this->modx->log(LOG_LEVEL_INFO, 'Packaged in ' . count($objects) . ' Chunks');
    }


    /**
     * @param $filename
     *
     * @return string
     */
    static public function _getContent($filename)
    {
        if (file_exists($filename)) {
            $file = trim(file_get_contents($filename));

            return preg_match('#<\?php(.*)#is', $file, $data)
                ? rtrim(rtrim(trim(@$data[1]), '?>'))
                : $file;
        }

        return '';
    }


    /**
     *  Install package
     */
    protected function install()
    {
        $signature = $this->builder->getSignature();
        $sig = explode('-', $signature);
        $versionSignature = explode('.', $sig[1]);

        /** @var modTransportPackage $package */
        if (!$package = $this->modx->getObject(modTransportPackage::class,
            ['signature' => $signature])) {
            $package = $this->modx->newObject(modTransportPackage::class);
            $package->set('signature', $signature);
            $package->fromArray([
                'created' => date('Y-m-d h:i:s'),
                'updated' => null,
                'state' => 1,
                'workspace' => 1,
                'provider' => 0,
                'source' => $signature . '.transport.zip',
                'package_name' => $this->config['name'],
                'version_major' => $versionSignature[0],
                'version_minor' => !empty($versionSignature[1]) ? $versionSignature[1] : 0,
                'version_patch' => !empty($versionSignature[2]) ? $versionSignature[2] : 0,
            ]);
            if (!empty($sig[2])) {
                $r = preg_split('#([0-9]+)#', $sig[2], -1, PREG_SPLIT_DELIM_CAPTURE);
                if (is_array($r) && !empty($r)) {
                    $package->set('release', $r[0]);
                    $package->set('release_index', (isset($r[1]) ? $r[1] : '0'));
                } else {
                    $package->set('release', $sig[2]);
                }
            }
            $package->save();
        }
        if ($package->install()) {
            $this->modx->runProcessor(ClearCache::class);
        }
    }


    /**
     * @return modPackageBuilder
     */
    public function process()
    {
        $this->assets();

        // Add elements
        $elements = scandir($this->config['elements']);
        foreach ($elements as $element) {
            if (in_array($element[0], ['_', '.'])) {
                continue;
            }
            $name = preg_replace('#\.php$#', '', $element);
            if (method_exists($this, $name)) {
                $this->{$name}();
            }
        }

        // Create main vehicle
        $vehicle = $this->builder->createVehicle($this->category, $this->category_attributes);

        // Files resolvers
        $vehicle->resolve('file', [
            'source' => $this->config['core'],
            'target' => "return MODX_CORE_PATH . 'components/';",
        ]);
        $vehicle->resolve('file', [
            'source' => $this->config['assets'],
            'target' => "return MODX_ASSETS_PATH . 'components/';",
        ]);

        // Add resolvers into vehicle
        $resolvers = scandir($this->config['resolvers']);
        foreach ($resolvers as $resolver) {
            if (mb_strpos($resolver, '_') === 0 || in_array($resolver, ['.', '..'], true)) {
                continue;
            }
            if ($vehicle->resolve('php', ['source' => $this->config['resolvers'].$resolver])) {
                $this->modx->log(LOG_LEVEL_INFO, 'Added resolver '.preg_replace('#\.php$#', '', $resolver));
            } else {
                $this->modx->log(LOG_LEVEL_INFO, 'Could not add resolver "'.$resolver.'" to category.');
            }
        }

        $this->builder->putVehicle($vehicle);

        $this->builder->setPackageAttributes([
            'changelog' => file_get_contents($this->config['core'] . 'docs/changelog.txt'),
            'license' => file_get_contents($this->config['core'] . 'docs/license.txt'),
            'readme' => file_get_contents($this->config['core'] . 'docs/readme.txt'),
        ]);
        $this->modx->log(LOG_LEVEL_INFO, 'Added package attributes and setup options.');

        $this->modx->log(LOG_LEVEL_INFO, 'Packing up transport package zip...');
        $this->builder->pack();

        if (!empty($this->config['install'])) {
            $this->install();
        }

        return $this->builder;
    }

}

$modx = new modX();
$install = new FetchItPackage($modx, $config);
$builder = $install->process();

if (!empty($config['download'])) {
    $name = $builder->getSignature() . '.transport.zip';
    if ($content = file_get_contents(MODX_CORE_PATH . '/packages/' . $name)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $name);
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($content));
        exit($content);
    }
}
