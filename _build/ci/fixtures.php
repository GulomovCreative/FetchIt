<?php
/**
 * Create the pages the integration tests submit, and write their ids as JSON
 * to <output> (or print them):
 * {"modx": 2|3, "custom": <id>, "api": <id>, "probe": <id>, "formit": <id|null>,
 *  "pdotools": <id|null>, "installed": {"package": <signature>, "elements": <bool>}}
 *
 * "custom" is processed by a snippet of its own; "api" by a snippet that
 * gets FetchIt the way custom snippets did in 1.x (MODX 2) or 3.x (MODX 3);
 * "probe" shows what bootstrap.php set up before anything loaded FetchIt;
 * "formit" by FormIt and "pdotools" with a Fenom @FILE chunk, when FormIt
 * and pdoTools are installed. "installed" is the newest FetchIt package
 * installed and whether the snippet and plugin in the database are the
 * FetchIt 4 ones (an upgrade replaced them). Exits non-zero when anything
 * cannot be saved.
 *
 * Usage: MODX_CORE_PATH=/path/to/core/ php _build/ci/fixtures.php [output]
 */

/** @var modX $modx */
$modx = require __DIR__ . '/bootstrap.inc.php';
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);

function fixture_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function fixture_element($modx, $class, $name, $field, $content)
{
    $object = $modx->getObject(modx_class($class), ['name' => $name]);
    if (!$object) {
        $object = $modx->newObject(modx_class($class));
        $object->set('name', $name);
    }
    $object->set($field, $content);
    if (!$object->save()) {
        fixture_fail("Could not save {$class} {$name}.");
    }
}

function fixture_plugin($modx, $name, $event, $code)
{
    fixture_element($modx, 'modPlugin', $name, 'plugincode', $code);
    $plugin = $modx->getObject(modx_class('modPlugin'), ['name' => $name]);
    $criteria = ['pluginid' => $plugin->get('id'), 'event' => $event];
    if (!$modx->getObject(modx_class('modPluginEvent'), $criteria)) {
        $binding = $modx->newObject(modx_class('modPluginEvent'));
        $binding->fromArray($criteria + ['priority' => 0, 'propertyset' => 0], '', true, true);
        if (!$binding->save()) {
            fixture_fail("Could not bind the plugin {$name} to {$event}.");
        }
    }
}

function fixture_page($modx, $alias, $content)
{
    $class = modx_class('modResource');
    $resource = $modx->getObject($class, ['alias' => $alias]);
    if (!$resource) {
        $resource = $modx->newObject($class);
    }
    $resource->fromArray([
        'pagetitle' => $alias,
        'alias' => $alias,
        'content' => $content,
        'published' => 1,
        'template' => (int)$modx->getOption('default_template'),
        'context_key' => 'web',
    ]);
    if (!$resource->save() || (int)$resource->get('id') < 1) {
        fixture_fail("Could not save the page {$alias}.");
    }

    return (int)$resource->get('id');
}

fixture_element($modx, 'modChunk', 'tpl.FetchIt.test', 'snippet', <<<'HTML'
<form action="[[~[[*id]]]]" method="post">
  <input type="text" name="name" value="[[+fi.name]]">
  <span data-error="name">[[+fi.error.name]]</span>
  <input type="text" name="email" value="[[+fi.email]]">
  <span data-error="email">[[+fi.error.email]]</span>
  <label><input type="checkbox" name="topics[]" value="news"> News</label>
  <label><input type="checkbox" name="topics[]" value="events"> Events</label>
  <span data-error="topics"></span>
  <input type="file" name="attachment">
  <button type="submit">Send</button>
  <div data-success style="display: [[+fi.success:is=`1`:then=``:else=`none`]]">[[+fi.successMessage]]</div>
  <div data-validation-error style="display: [[+fi.validation_error:is=`1`:then=``:else=`none`]]">[[+fi.validation_error_message]]</div>
</form>
HTML
);

fixture_element($modx, 'modSnippet', 'FetchItTestHandler', 'snippet', <<<'PHP'
$fields = $modx->getOption('fields', $scriptProperties, []);
$email = isset($fields['email']) ? trim($fields['email']) : '';
$topics = isset($fields['topics']) && is_array($fields['topics']) ? array_values($fields['topics']) : [];
if ($email === '') {
    $errors = ['email' => 'Email is required'];
    if (!$topics) {
        $errors['topics'] = 'Pick a topic';
    }

    return json_encode(['success' => false, 'message' => 'Check the form', 'data' => $errors]);
}

// Cookies must not reach the fields of the form; smoke.sh sends a
// fetchit_probe cookie with every submission.
$leaked = array_values(array_intersect(array_keys($fields), array_keys($_COOKIE)));

// action.php merges $_FILES into the fields.
$file = isset($fields['attachment']['name']) && $fields['attachment']['name'] !== ''
    ? $fields['attachment']['name'] . ':' . (int)$fields['attachment']['size']
    : null;

// The service fields of the protection must not reach the snippet, nor
// $_POST, which FormIt reads for its e-mails.
// ($_REQUEST may also hold the cookies; those are checked above.)
$service = [];
foreach ([$fields, $_POST, array_diff_key($_REQUEST, $_COOKIE)] as $source) {
    foreach (array_keys($source) as $key) {
        if (strpos($key, 'fetchit_') === 0) {
            $service[] = $key;
        }
    }
}

return json_encode([
    'success' => true,
    'message' => 'Thanks, ' . $email,
    'data' => ['cookies' => $leaked, 'topics' => $topics, 'file' => $file, 'service' => $service],
]);
PHP
);

fixture_element($modx, 'modSnippet', 'FetchItTestApi', 'snippet', <<<'PHP'
// Custom snippets got FetchIt this way in 1.x (MODX 2) and 3.x (MODX 3).
if (is_object($modx->services) && $modx->services->has('FetchIt')) {
    $FetchIt = $modx->services->get('FetchIt');
    $api = '3.x';
    $class = $FetchIt instanceof \FetchIt\FetchIt;
} else {
    $FetchIt = $modx->getService('fetchit', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/', []);
    $api = '1.x';
    $class = $FetchIt instanceof FetchIt;
}

// One shared instance, whichever way it is asked for.
$legacy = $modx->getService('fetchit', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/', []);
$FetchIt->saveActionProperties('fetchit-api-probe', ['probe' => 1]);

return $FetchIt->success('API ' . $api, [
    'class' => $class,
    'same' => $FetchIt === FetchIt::service($modx) && $FetchIt === $legacy,
    'props' => $FetchIt->getActionProperties('fetchit-api-probe') === ['probe' => 1],
]);
PHP
);

// Refuses addresses that start with "blocked", as a plugin of a site would.
fixture_plugin($modx, 'FetchItTestGate', 'OnFetchItBeforeProcess', <<<'PHP'
$email = isset($fields['email']) ? (string)$fields['email'] : '';
if (strpos($email, 'blocked') === 0) {
    $modx->event->output('Blocked by a plugin');
}
PHP
);

fixture_element($modx, 'modSnippet', 'FetchItTestProbe', 'snippet', <<<'PHP'
// Runs at page render, before anything on the page loads FetchIt: on
// MODX 3 bootstrap.php has registered the service and the class by now.
$container = is_object($modx->services) && $modx->services->has('FetchIt') ? 1 : 0;
$namespaced = class_exists('FetchIt\FetchIt') ? 1 : 0;

return "<p id=\"probe\">container={$container} namespaced={$namespaced}</p>";
PHP
);

$pages = [
    'modx' => class_exists('MODX\Revolution\modX') ? 3 : 2,
    'custom' => fixture_page(
        $modx,
        'fetchit-custom',
        '[[!FetchIt? &snippet=`FetchItTestHandler` &form=`tpl.FetchIt.test`]]'
    ),
    'api' => fixture_page(
        $modx,
        'fetchit-api',
        '[[!FetchIt? &snippet=`FetchItTestApi` &form=`tpl.FetchIt.test`]]'
    ),
    'probe' => fixture_page($modx, 'fetchit-probe', '[[!FetchItTestProbe]]'),
    'formit' => null,
    'pdotools' => null,
];

$snippet = $modx->getObject(modx_class('modSnippet'), ['name' => 'FetchIt']);
$plugin = $modx->getObject(modx_class('modPlugin'), ['name' => 'FetchIt']);
// The newest installed version: an upgrade may stamp both packages with the
// same "installed" second, so the time does not tell them apart.
$query = $modx->newQuery(modx_class('modTransportPackage'), ['signature:LIKE' => 'fetchit-%', 'installed:!=' => null]);
$query->sortby('version_major', 'DESC');
$query->sortby('version_minor', 'DESC');
$query->sortby('version_patch', 'DESC');
$package = $modx->getObject(modx_class('modTransportPackage'), $query);
$pages['installed'] = [
    'package' => $package ? $package->get('signature') : null,
    'elements' => $snippet && $plugin
        && strpos($snippet->get('snippet'), 'FetchIt::pdoTools') !== false
        && strpos($plugin->get('plugincode'), 'FetchIt::service') !== false,
];

if ($modx->getObject(modx_class('modSnippet'), ['name' => 'pdoResources'])) {
    // pdoTools reads @FILE chunks from pdotools_elements_path.
    $elements = str_replace(
        ['{core_path}', '{base_path}', '{assets_path}'],
        [MODX_CORE_PATH, MODX_BASE_PATH, MODX_ASSETS_PATH],
        $modx->getOption('pdotools_elements_path', null, '{core_path}elements/')
    );
    $file = rtrim($elements, '/') . '/fetchit-test.tpl';
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) {
        fixture_fail('Could not create ' . dirname($file) . '.');
    }
    $chunk = <<<'HTML'
<form method="post">
  <h2 class="fenom">{$title}</h2>
  <input type="text" name="email">
  <span data-error="email"></span>
  <button type="submit">Send</button>
</form>
HTML;
    if (file_put_contents($file, $chunk) === false) {
        fixture_fail("Could not write {$file}.");
    }
    $pages['pdotools'] = fixture_page(
        $modx,
        'fetchit-pdotools',
        '[[!FetchIt? &snippet=`FetchItTestHandler` &form=`@FILE fetchit-test.tpl` &title=`Fenom works`]]'
    );
}

if ($modx->getObject(modx_class('modSnippet'), ['name' => 'FormIt'])) {
    $pages['formit'] = fixture_page(
        $modx,
        'fetchit-formit',
        '[[!FetchIt? &snippet=`FormIt` &form=`tpl.FetchIt.test` &validate=`email:email:required` &successMessage=`Sent`]]'
    );
}

$modx->cacheManager->refresh();

$json = json_encode($pages) . "\n";
if (isset($argv[1])) {
    if (file_put_contents($argv[1], $json) === false) {
        fixture_fail("Could not write {$argv[1]}.");
    }
} else {
    echo $json;
}
