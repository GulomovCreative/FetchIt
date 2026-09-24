<?php
/**
 * Create the pages the integration tests submit, and print their ids as JSON:
 * {"custom": <id>, "formit": <id or null>}
 *
 * "custom" is processed by a snippet of its own, "formit" by FormIt, when
 * FormIt is installed.
 *
 * Usage: MODX_CORE_PATH=/path/to/core/ php _build/ci/fixtures.php
 */

/** @var modX $modx */
$modx = require __DIR__ . '/bootstrap.inc.php';
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);

function fixture_element($modx, $class, $name, $field, $content)
{
    $object = $modx->getObject(modx_class($class), ['name' => $name]);
    if (!$object) {
        $object = $modx->newObject(modx_class($class));
        $object->set('name', $name);
    }
    $object->set($field, $content);
    $object->save();
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
    $resource->save();

    return (int)$resource->get('id');
}

fixture_element($modx, 'modChunk', 'tpl.FetchIt.test', 'snippet', <<<'HTML'
<form action="[[~[[*id]]]]" method="post">
  <input type="text" name="name" value="[[+fi.name]]">
  <span data-error="name">[[+fi.error.name]]</span>
  <input type="text" name="email" value="[[+fi.email]]">
  <span data-error="email">[[+fi.error.email]]</span>
  <button type="submit">Send</button>
  <div data-success style="display: none"></div>
  <div data-validation-error style="display: none"></div>
</form>
HTML
);

fixture_element($modx, 'modSnippet', 'FetchItTestHandler', 'snippet', <<<'PHP'
$fields = $modx->getOption('fields', $scriptProperties, []);
$email = isset($fields['email']) ? trim($fields['email']) : '';
if ($email === '') {
    return json_encode([
        'success' => false,
        'message' => 'Check the form',
        'data' => ['email' => 'Email is required'],
    ]);
}

// Cookies must not reach the fields of the form.
$leaked = array_values(array_intersect(array_keys($fields), array_keys($_COOKIE)));

return json_encode(['success' => true, 'message' => 'Thanks, ' . $email, 'data' => ['cookies' => $leaked]]);
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
echo json_encode($pages), "\n";
