<?php
/**
 * Create the pages the integration tests submit, and write their ids as JSON
 * to <output> (or print them): {"custom": <id>, "formit": <id or null>}
 *
 * "custom" is processed by a snippet of its own, "formit" by FormIt, when
 * FormIt is installed. Exits non-zero when anything cannot be saved.
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
  <div data-success style="display: none"></div>
  <div data-validation-error style="display: none"></div>
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

return json_encode([
    'success' => true,
    'message' => 'Thanks, ' . $email,
    'data' => ['cookies' => $leaked, 'topics' => $topics, 'file' => $file],
]);
PHP
);

$pages = [
    'custom' => fixture_page(
        $modx,
        'fetchit-custom',
        '[[!FetchIt? &snippet=`FetchItTestHandler` &form=`tpl.FetchIt.test`]]'
    ),
    'formit' => null,
];

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
