<?php

/*
 * Unit tests run FetchIt and the setup resolver against this in-memory
 * stand-in for MODX: it covers only what they touch, and follows real MODX
 * where that matters (getOption(), getPropertySet()). Behaviour that needs a
 * real MODX (the parser, FormIt, transport packages) is checked by the
 * integration tests in CI.
 */

define('MODX_CORE_PATH', dirname(__DIR__, 2) . '/core/');

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class modX
{
    const LOG_LEVEL_FATAL = 0;
    const LOG_LEVEL_ERROR = 1;
    const LOG_LEVEL_WARN = 2;
    const LOG_LEVEL_INFO = 3;

    /** @var array[] [level, message] of every log() call */
    public $logged = [];

    /** @var array */
    public $options = [
        'core_path' => '/srv/core/',
        'assets_path' => '/srv/assets/',
        'assets_url' => '/assets/',
    ];

    /** @var array */
    public $placeholders = [];

    /** @var FakeLexicon */
    public $lexicon;

    /** @var FakeCacheManager */
    public $cacheManager;

    /** @var FakeResource|null */
    public $resource;

    /** @var string[] */
    public $htmlBlocks = [];

    /** @var FakeSnippet[] */
    public $snippets = [];

    /** @var array|FakeContainer An array on MODX 2, a container on MODX 3 */
    public $services = [];

    /** @var array Objects getService() returns, by name */
    public $legacyServices = [];

    /** @var FakeLoader */
    public static $loader;

    public function __construct()
    {
        $this->lexicon = new FakeLexicon();
        $this->cacheManager = new FakeCacheManager();
    }

    /**
     * As xPDO::getOption(): with $skipEmpty, an empty value in $options
     * falls through to the settings before the default.
     */
    public function getOption($key, $options = null, $default = null, $skipEmpty = false)
    {
        if (is_array($options) && array_key_exists($key, $options) && (!$skipEmpty || $options[$key] !== '')) {
            return $options[$key];
        }
        if (array_key_exists($key, $this->options) && (!$skipEmpty || $this->options[$key] !== '')) {
            return $this->options[$key];
        }

        return $default;
    }

    public function lexicon($key, $params = [])
    {
        $value = isset($this->lexicon->entries[$key]) ? $this->lexicon->entries[$key] : $key;
        foreach ($params as $name => $param) {
            $value = str_replace('[[+' . $name . ']]', $param, $value);
        }

        return $value;
    }

    /**
     * As xPDO 2 getService(): one shared instance per name.
     */
    public function getService($name, $class = '', $path = '', $params = [])
    {
        $key = strtolower($name);
        if (!isset($this->legacyServices[$key])) {
            if (!class_exists($class ?: $name)) {
                return null;
            }
            $class = $class ?: $name;
            $this->legacyServices[$key] = new $class($this, $params);
        }

        return $this->legacyServices[$key];
    }

    public static function getLoader()
    {
        return self::$loader = self::$loader ?: new FakeLoader();
    }

    public function toJSON($data)
    {
        return json_encode($data);
    }

    public function log($level, $message)
    {
        $this->logged[] = [$level, $message];
    }

    public function regClientHTMLBlock($html)
    {
        $this->htmlBlocks[] = $html;
    }

    public function getObject($class, $criteria)
    {
        if ($class === 'modSnippet' && isset($this->snippets[$criteria['name']])) {
            return $this->snippets[$criteria['name']];
        }

        return null;
    }
}

/**
 * The MODX 3 service container: add() takes an object or a factory closure,
 * and get() always returns the same instance.
 */
class FakeContainer
{
    /** @var array */
    private $entries = [];

    public function has($id)
    {
        return array_key_exists($id, $this->entries);
    }

    public function add($id, $value)
    {
        $this->entries[$id] = $value;
    }

    public function get($id)
    {
        if ($this->entries[$id] instanceof Closure) {
            $this->entries[$id] = call_user_func($this->entries[$id]);
        }

        return $this->entries[$id];
    }
}

class FakeLoader
{
    /** @var array prefix => path */
    public $psr4 = [];

    public function addPsr4($prefix, $path)
    {
        $this->psr4[$prefix] = $path;
    }
}

class FakeLexicon
{
    /** @var array */
    public $entries = [
        'fetchit_err_snippet_nf' => 'Snippet "[[+name]]" not found',
    ];

    /** @var string[] */
    public $loaded = [];

    public function load($topic)
    {
        $this->loaded[] = $topic;
    }
}

class FakeCacheManager
{
    /** @var array */
    public $items = [];

    public function set($key, $value, $lifetime = 0)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function get($key)
    {
        return isset($this->items[$key]) ? $this->items[$key] : null;
    }
}

class FakeResource
{
    /** @var int */
    public $id;

    /** @var string */
    public $_output = '';

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function get($field)
    {
        return $field === 'id' ? $this->id : null;
    }
}

class FakeSnippet
{
    /** @var string */
    public $name;

    /** @var bool */
    public $_cacheable = true;

    /** @var bool */
    public $_processed = true;

    /** @var array */
    public $properties = [];

    /** @var array */
    public $propertySets = [];

    /** @var array|null The properties the last process() call received */
    public $received;

    /** @var callable */
    private $handler;

    public function __construct($name, callable $handler)
    {
        $this->name = $name;
        $this->handler = $handler;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * As modElement::getPropertySet(): null for a set that does not exist.
     */
    public function getPropertySet($set)
    {
        return isset($this->propertySets[$set]) ? $this->propertySets[$set] : null;
    }

    public function process($properties)
    {
        $this->received = $properties;

        return call_user_func($this->handler, $properties);
    }
}

class xPDOTransport
{
    const PACKAGE_ACTION = 'package_action';
    const ACTION_INSTALL = 0;
    const ACTION_UPGRADE = 1;
    const ACTION_UNINSTALL = 2;

    /** @var modX */
    public $xpdo;
}

require_once MODX_CORE_PATH . 'components/fetchit/model/fetchit.class.php';
