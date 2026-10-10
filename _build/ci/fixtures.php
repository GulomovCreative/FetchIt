<?php
/**
 * Create the pages the integration tests submit, and write their ids as JSON
 * to <output> (or print them):
 * {"modx": 2|3, "custom": <id>, "api": <id>, "probe": <id>, "formit": <id|null>,
 *  "counter": <id>, "redirect": <id>, "redirector": <id>, "noisyredirector": <id>, "retryredirector": <id>,
 *  "formitredirect": <id|null>,
 *  "mixed": <id|null>, "pdotools": <id|null>,
 *  "installed": {"package": <signature>, "elements": <bool>}}
 *
 * "counter" counts its runs, so a test sees whether a retry ran it again, "redirect" leads to
 * the "custom" page after a success, "redirector" redirects itself, "noisyredirector" prints a few bytes first,
 * "retryredirector" counts its runs and redirects with the run number, with retries, and
 * "formitredirect" is FormIt with the redirect hook (null without FormIt).
 *
 * "custom" is processed by a snippet of its own; "api" by a snippet that
 * gets FetchIt the way custom snippets did in 1.x (MODX 2) or 3.x (MODX 3);
 * "probe" shows what bootstrap.php set up before anything loaded FetchIt;
 * "formit" by FormIt and "pdotools" with a Fenom @FILE chunk, when FormIt
 * and pdoTools are installed. "mixed", when FormIt has an AJAX mode of its
 * own (5.2 and later), has a form of FormIt itself in that mode next to a
 * FetchIt form with FormIt, both with the placeholder prefix fi. "installed" is the newest FetchIt package
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

function fixture_page($modx, $alias, $content, $template = null, $parent = 0)
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
        'template' => $template === null ? (int)$modx->getOption('default_template') : (int)$template,
        'parent' => (int)$parent,
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

fixture_element($modx, 'modSnippet', 'FetchItTestCounter', 'snippet', <<<'PHP'
// Counts its runs: a retry of a submission must not run it again. The
// render of the page runs it too, without fields: that is no submission.
if (empty($fields)) {
    return '';
}
$count = (int)$modx->cacheManager->get('fetchit-test-counter') + 1;
$modx->cacheManager->set('fetchit-test-counter', $count);

return json_encode(['success' => true, 'message' => 'Run ' . $count, 'data' => ['run' => $count]]);
PHP
);

fixture_element($modx, 'modSnippet', 'FetchItTestRedirector', 'snippet', <<<'PHP'
// Redirects itself, as a snippet of a site may: FetchIt turns it into an answer.
// Not on the render of the page, which runs it without fields.
if (empty($fields)) {
    return '';
}
$modx->sendRedirect($modx->makeUrl($modx->getOption('target', $scriptProperties), '', '', 'full'));
PHP
);

fixture_element($modx, 'modSnippet', 'FetchItTestNoisyRedirector', 'snippet', <<<'PHP'
// Prints a few bytes before it redirects: they must not bring back the 302.
if (empty($fields)) {
    return '';
}
echo "\xEF\xBB\xBFnoise";
$modx->sendRedirect($modx->makeUrl($modx->getOption('target', $scriptProperties), '', '', 'full'));
PHP
);

fixture_element($modx, 'modSnippet', 'FetchItTestCountingRedirector', 'snippet', <<<'PHP'
// Counts its runs and redirects with the run number: a retry of a submission
// must get the redirect of the first attempt without a second run.
if (empty($fields)) {
    return '';
}
$count = (int)$modx->cacheManager->get('fetchit-test-redirector') + 1;
$modx->cacheManager->set('fetchit-test-redirector', $count);
$modx->sendRedirect($modx->makeUrl($modx->getOption('target', $scriptProperties), '', ['run' => $count], 'full'));
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
    'formitredirect' => null,
    'mixed' => null,
    'pdotools' => null,
    'pdopage' => null,
    'pdopagelinks' => null,
];

$pages['counter'] = fixture_page(
    $modx,
    'fetchit-counter',
    '[[!FetchIt? &snippet=`FetchItTestCounter` &form=`tpl.FetchIt.test` &retries=`2` &timeout=`10`]]'
);
$pages['redirect'] = fixture_page(
    $modx,
    'fetchit-redirect',
    '[[!FetchIt? &snippet=`FetchItTestCounter` &form=`tpl.FetchIt.test` &redirectTo=`' . $pages['custom'] . '` &redirectParams=`{"from":"form"}`]]'
);
$pages['redirector'] = fixture_page(
    $modx,
    'fetchit-redirector',
    '[[!FetchIt? &snippet=`FetchItTestRedirector` &form=`tpl.FetchIt.test` &target=`' . $pages['custom'] . '`]]'
);
$pages['noisyredirector'] = fixture_page(
    $modx,
    'fetchit-noisyredirector',
    '[[!FetchIt? &snippet=`FetchItTestNoisyRedirector` &form=`tpl.FetchIt.test` &target=`' . $pages['custom'] . '`]]'
);
$pages['retryredirector'] = fixture_page(
    $modx,
    'fetchit-retryredirector',
    '[[!FetchIt? &snippet=`FetchItTestCountingRedirector` &form=`tpl.FetchIt.test` &target=`' . $pages['custom'] . '` &retries=`2`]]'
);

// A form loaded into another page: the fragment has no template, the host
// page loads it into a modal and can close it.
$pages['fragment'] = fixture_page(
    $modx,
    'fetchit-fragment',
    '[[!FetchIt? &snippet=`FetchItTestHandler` &form=`tpl.FetchIt.test`]]',
    0
);
$host = function ($call) use ($pages) {
    return <<<HTML
{$call}
<button type="button" id="open">Open the form</button>
<button type="button" id="close">Close the form</button>
<div id="modal"></div>
<script>
document.getElementById('open').addEventListener('click', async () => {
  const answer = await fetch('index.php?id={$pages['fragment']}')
  document.getElementById('modal').innerHTML = await answer.text()
})
document.getElementById('close').addEventListener('click', () => {
  document.getElementById('modal').innerHTML = ''
})
</script>
HTML;
};
$pages['host'] = fixture_page($modx, 'fetchit-host', $host('[[!FetchItScript]]'));
// The same page with a cached call: views after the first come from the
// cache of the resource, with only the marker of FetchItScript.
$pages['cachedhost'] = fixture_page($modx, 'fetchit-host-cached', $host('[[FetchItScript]]'));

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

    // pdoPage loads the next rows over AJAX; every row has a form.
    fixture_element(
        $modx,
        'modChunk',
        'tpl.FetchIt.row',
        'snippet',
        '<div class="row">[[!FetchIt? &snippet=`FetchItTestHandler` &form=`tpl.FetchIt.test`]]</div>'
    );
    $pages['pdopage'] = fixture_page($modx, 'fetchit-pdopage', <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<div id="pdopage">
  <div class="rows">[[!pdoPage? &element=`pdoResources` &parents=`[[*id]]` &limit=`1` &tpl=`tpl.FetchIt.row` &ajaxMode=`button`]]</div>
  [[!+page.nav]]
</div>
HTML
    );
    fixture_page($modx, 'fetchit-pdopage-1', '', null, $pages['pdopage']);
    fixture_page($modx, 'fetchit-pdopage-2', '', null, $pages['pdopage']);

    // pdoPage in its default mode replaces the rows with those of the page
    // whose link is clicked.
    $pages['pdopagelinks'] = fixture_page($modx, 'fetchit-pdopage-links', <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<div id="pdopage">
  <div class="rows">[[!pdoPage? &element=`pdoResources` &parents=`[[*id]]` &limit=`1` &tpl=`tpl.FetchIt.row` &ajaxMode=`default`]]</div>
  [[!+page.nav]]
</div>
HTML
    );
    fixture_page($modx, 'fetchit-pdopage-links-1', '', null, $pages['pdopagelinks']);
    fixture_page($modx, 'fetchit-pdopage-links-2', '', null, $pages['pdopagelinks']);
}

if ($modx->getObject(modx_class('modSnippet'), ['name' => 'FormIt'])) {
    $pages['formit'] = fixture_page(
        $modx,
        'fetchit-formit',
        '[[!FetchIt? &snippet=`FormIt` &form=`tpl.FetchIt.test` &validate=`email:email:required` &successMessage=`Sent`]]'
    );
    $pages['formitredirect'] = fixture_page(
        $modx,
        'fetchit-formit-redirect',
        '[[!FetchIt? &snippet=`FormIt` &form=`tpl.FetchIt.test` &hooks=`redirect` &redirectTo=`' . $pages['custom'] . '` &validate=`email:email:required` &successMessage=`Sent`]]'
    );
}

if ($pages['formit'] && trim((string)$modx->getOption('formit.frontend_js', null, '')) !== '') {
    // The markup of the FormIt form comes after the FetchIt call, so its
    // token placeholder is read after FetchIt ran FormIt too.
    $pages['mixed'] = fixture_page(
        $modx,
        'fetchit-mixed',
        '[[!FormIt? &validate=`own:required` &successMessage=`Own sent`]]'
        . '[[!FetchIt? &snippet=`FormIt` &form=`tpl.FetchIt.test` &validate=`email:email:required` &successMessage=`Sent`]]'
        . '<form id="own" method="post" data-formit-ajax-token="[[!+fi.ajaxToken]]">'
        . '<input type="text" name="own" aria-label="Own"><button type="submit">Own</button>'
        . '<div data-formit-success-message></div><div data-formit-error-message></div>'
        . '</form>'
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
