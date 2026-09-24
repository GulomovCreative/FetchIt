<?php

/*
 * Unit tests run FetchIt against this in-memory stand-in for MODX: it covers
 * only what the class touches. Behaviour that needs a real MODX (the parser,
 * FormIt, transport packages) is checked by the integration tests in CI.
 */

define('MODX_CORE_PATH', dirname(__DIR__, 2) . '/core/');

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class modX
{
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

    public function __construct()
    {
        $this->lexicon = new FakeLexicon();
        $this->cacheManager = new FakeCacheManager();
    }

    public function getOption($key, $options = null, $default = null, $skipEmpty = false)
    {
        if (is_array($options) && array_key_exists($key, $options)) {
            $value = $options[$key];
        } elseif (array_key_exists($key, $this->options)) {
            $value = $this->options[$key];
        } else {
            return $default;
        }

        return $skipEmpty && $value === '' ? $default : $value;
    }

    public function lexicon($key, $params = [])
    {
        $value = isset($this->lexicon->entries[$key]) ? $this->lexicon->entries[$key] : $key;
        foreach ($params as $name => $param) {
            $value = str_replace('[[+' . $name . ']]', $param, $value);
        }

        return $value;
    }

    public function toJSON($data)
    {
        return json_encode($data);
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

    public function getPropertySet($set)
    {
        return isset($this->propertySets[$set]) ? $this->propertySets[$set] : [];
    }

    public function process($properties)
    {
        $this->received = $properties;

        return call_user_func($this->handler, $properties);
    }
}

require_once MODX_CORE_PATH . 'components/fetchit/model/fetchit.class.php';
