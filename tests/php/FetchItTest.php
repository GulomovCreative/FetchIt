<?php

use PHPUnit\Framework\TestCase;

class FetchItTest extends TestCase
{
    /** @var modX */
    private $modx;

    protected function setUp(): void
    {
        $this->modx = new modX();
        $_SESSION = [];
        // The "scripts requested" flag lives for one request, i.e. one test.
        $flag = new ReflectionProperty(FetchIt::class, 'scriptRequested');
        $flag->setAccessible(true);
        $flag->setValue(null, false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_id() !== '') {
            session_id('');
        }
    }

    private function fetchit(array $config = [])
    {
        return new FetchIt($this->modx, $config);
    }

    private function withSession()
    {
        session_id('phpunit');
    }

    private function decode($json)
    {
        return json_decode($json, true);
    }

    private function addSnippet($name, callable $handler)
    {
        return $this->modx->snippets[$name] = new FakeSnippet($name, $handler);
    }

    private function formit()
    {
        return $this->addSnippet('FormIt', function () {
            return '';
        });
    }

    // ------------------------------------------------------------ configuration

    public function testConstructorLoadsTheLexiconAndDerivesUrls()
    {
        $fetchit = $this->fetchit();

        $this->assertContains('fetchit:default', $this->modx->lexicon->loaded);
        $this->assertSame('/assets/components/fetchit/', $fetchit->config['assetsUrl']);
        $this->assertSame('/assets/components/fetchit/action.php', $fetchit->config['actionUrl']);
        $this->assertSame('/srv/core/components/fetchit/', $fetchit->config['corePath']);
    }

    public function testFrontendScriptDefaultsToTheShippedFile()
    {
        $fetchit = $this->fetchit();

        $this->assertSame('[[+assetsUrl]]js/fetchit.js', $fetchit->config['frontend_js']);
    }

    public function testSnippetPropertiesOverrideTheDefaults()
    {
        $fetchit = $this->fetchit(['actionUrl' => '/custom.php']);

        $this->assertSame('/custom.php', $fetchit->config['actionUrl']);
    }

    // ------------------------------------------------------------ form markup

    public function testFormGetsPostMethodAndActionKey()
    {
        $html = $this->fetchit()->prepareForm('<form class="f"><input name="a"></form>', 'abc');

        $this->assertSame('<form class="f" method="post" data-fetchit="abc"><input name="a"></form>', $html);
    }

    public function testExistingMethodIsReplaced()
    {
        $html = $this->fetchit()->prepareForm("<form method='get' class=\"f\">", 'abc');

        $this->assertSame('<form class="f" method="post" data-fetchit="abc">', $html);
    }

    public function testExistingActionKeyIsReplacedKeepingOtherAttributes()
    {
        $html = $this->fetchit()->prepareForm('<form id="x" data-fetchit="old" class="f">', 'abc');

        $this->assertSame('<form id="x" class="f" method="post" data-fetchit="abc">', $html);
    }

    public function testAttributesOfNestedElementsAreLeftAlone()
    {
        $html = $this->fetchit()->prepareForm(
            '<form class="f"><button formmethod="get" data-fetchit="keep">Go</button></form>',
            'abc'
        );

        $this->assertSame(
            '<form class="f" method="post" data-fetchit="abc"><button formmethod="get" data-fetchit="keep">Go</button></form>',
            $html
        );
    }

    public function testFormTagIsFoundInAnyCaseButNotInLookalikes()
    {
        $html = $this->fetchit()->prepareForm("<FORM\n  action=\"/x\"><form-field></form-field></FORM>", 'abc');

        $this->assertSame("<FORM\n  action=\"/x\" method=\"post\" data-fetchit=\"abc\"><form-field></form-field></FORM>", $html);
    }

    public function testGreaterThanInsideAValueDoesNotEndTheTag()
    {
        $html = $this->fetchit()->prepareForm(
            '<form x-data="{ step: 1 }" @submit="step > 1 && send()" onsubmit=\'return a>b\' class="f">',
            'abc'
        );

        $this->assertSame(
            '<form x-data="{ step: 1 }" @submit="step > 1 && send()" onsubmit=\'return a>b\' class="f" method="post" data-fetchit="abc">',
            $html
        );
    }

    public function testAttributeNamesInsideValuesAreLeftAlone()
    {
        $html = $this->fetchit()->prepareForm('<form class="a method=get" title=\'data-fetchit="x"\'>', 'abc');

        $this->assertSame('<form class="a method=get" title=\'data-fetchit="x"\' method="post" data-fetchit="abc">', $html);
    }

    public function testBareAndUnquotedAttributesAreReplaced()
    {
        $html = $this->fetchit()->prepareForm('<form data-fetchit METHOD=GET class=f>', 'abc');

        $this->assertSame('<form class=f method="post" data-fetchit="abc">', $html);
    }

    public function testEveryFormInTheChunkIsPrepared()
    {
        $html = $this->fetchit()->prepareForm('<form></form><form></form>', 'abc');

        $this->assertSame(2, substr_count($html, 'data-fetchit="abc"'));
    }

    // ------------------------------------------------------ action properties

    /**
     * session_id() cannot change once PHPUnit has printed anything.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPropertiesGoToTheSessionWhenThereIsOne()
    {
        $this->withSession();
        $fetchit = $this->fetchit();

        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $this->assertSame(['snippet' => 'FormIt'], $_SESSION['FetchIt']['abc']);
        $this->assertSame([], $this->modx->cacheManager->items);
        $this->assertSame(['snippet' => 'FormIt'], $fetchit->loadActionProperties('abc'));
    }

    public function testPropertiesGoToTheCacheWithoutASession()
    {
        $fetchit = $this->fetchit();

        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $this->assertSame(['snippet' => 'FormIt'], $this->modx->cacheManager->items['fetchit/props_abc']);
        $this->assertSame(['snippet' => 'FormIt'], $fetchit->loadActionProperties('abc'));
    }

    /**
     * session_id() cannot change once PHPUnit has printed anything.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testObjectsAreStrippedBeforeStoring()
    {
        // #17: a PDO handle in the properties broke session serialization.
        $this->withSession();
        $fetchit = $this->fetchit();

        $fetchit->storeActionProperties('abc', [
            'snippet' => 'FormIt',
            'pdo' => new stdClass(),
            'stream' => fopen('php://memory', 'r'),
        ]);

        $this->assertSame(['snippet' => 'FormIt'], $_SESSION['FetchIt']['abc']);
    }

    public function testUnknownActionHasNoProperties()
    {
        $this->assertNull($this->fetchit()->loadActionProperties('missing'));
    }

    // ---------------------------------------------------------------- process

    public function testUnknownActionIsAnError()
    {
        $response = $this->decode($this->fetchit()->process('missing'));

        $this->assertFalse($response['success']);
        $this->assertSame('fetchit_err_action_nf', $response['message']);
    }

    public function testMissingSnippetIsAnError()
    {
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'Nope']);

        $response = $this->decode($fetchit->process('abc'));

        $this->assertFalse($response['success']);
        $this->assertSame('Snippet "Nope" not found', $response['message']);
    }

    public function testCustomSnippetGetsMergedPropertiesAndItsOutputIsReturned()
    {
        $snippet = $this->addSnippet('Handler', function () {
            return '{"success":true}';
        });
        $snippet->properties = ['a' => 'default', 'b' => 'default', 'c' => 'default'];
        $snippet->propertySets = ['set' => ['b' => 'set', 'c' => 'set']];
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'Handler@set', 'c' => 'call']);

        $output = $fetchit->process('abc', ['email' => 'a@b.c']);

        $this->assertSame('{"success":true}', $output);
        $this->assertSame('default', $snippet->received['a']);
        $this->assertSame('set', $snippet->received['b']);
        $this->assertSame('call', $snippet->received['c']);
        $this->assertSame(['email' => 'a@b.c'], $snippet->received['fields']);
        $this->assertFalse($snippet->_cacheable);
        $this->assertFalse($snippet->_processed);
    }

    // ----------------------------------------------------------------- FormIt

    private function processFormIt(array $properties, array $fields)
    {
        $this->formit();
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', array_merge(['snippet' => 'FormIt'], $properties));

        return $this->decode($fetchit->process('abc', $fields));
    }

    public function testFormItFieldErrorsComeFromPlaceholders()
    {
        $this->modx->placeholders['fi.error.email'] = '<span class="error">Required</span>';
        $this->modx->placeholders['fi.validation_error_message'] = 'Fix the form';

        $response = $this->processFormIt([], ['name' => 'Ann', 'email' => '']);

        $this->assertFalse($response['success']);
        $this->assertSame('Fix the form', $response['message']);
        $this->assertSame(['email' => 'Required'], $response['data']);
    }

    public function testBlankFormItErrorsAreIgnored()
    {
        // #14: FormIt leaves whitespace in error placeholders of valid fields.
        $this->modx->placeholders['fi.error.email'] = " \n ";
        $this->modx->placeholders['fi.error.name'] = '<span class="error"> </span>';

        $response = $this->processFormIt([], ['name' => 'Ann', 'email' => 'a@b.c']);

        $this->assertTrue($response['success']);
    }

    public function testNonBreakingSpaceIsBlankToo()
    {
        $this->modx->placeholders['fi.error.email'] = '<span class="error">&nbsp;</span>';

        $response = $this->processFormIt([], ['email' => 'a@b.c']);

        $this->assertTrue($response['success']);
    }

    public function testBlankValidationMessageFallsBackToTheLexicon()
    {
        $this->modx->placeholders['fi.error.email'] = 'Required';
        $this->modx->placeholders['fi.validation_error_message'] = ' ';

        $response = $this->processFormIt([], ['email' => '']);

        $this->assertSame('fetchit_err_has_errors', $response['message']);
    }

    public function testRecaptchaErrorsAreReportedUnderOneKey()
    {
        $this->modx->placeholders['fi.error.recaptchav3_error'] = 'Robot';

        $response = $this->processFormIt([], ['email' => 'a@b.c']);

        $this->assertSame(['recaptcha' => 'Robot'], $response['data']);
    }

    public function testCustomPlaceholderPrefix()
    {
        $this->modx->placeholders['form.error.email'] = 'Required';
        $this->modx->placeholders['fi.error.email'] = 'Ignored';

        $response = $this->processFormIt(['placeholderPrefix' => 'form.'], ['email' => '']);

        $this->assertSame(['email' => 'Required'], $response['data']);
    }

    public function testSuccessMessageFromTheSnippetCallWins()
    {
        // #5, #11
        $this->modx->placeholders['fi.successMessage'] = 'From FormIt';

        $response = $this->processFormIt(['successMessage' => 'From the call'], ['email' => 'a@b.c']);

        $this->assertTrue($response['success']);
        $this->assertSame('From the call', $response['message']);
    }

    public function testSuccessMessageFallsBackToThePlaceholderThenTheLexicon()
    {
        $this->modx->placeholders['fi.successMessage'] = 'From FormIt';
        $this->assertSame('From FormIt', $this->processFormIt([], [])['message']);

        $this->modx->placeholders = [];
        $this->assertSame('fetchit_success_submit', $this->processFormIt([], [])['message']);
    }

    // --------------------------------------------------------------- responses

    public function testResponsesCanBeArrays()
    {
        $fetchit = $this->fetchit(['json_response' => false]);

        $this->assertSame(
            ['success' => true, 'message' => 'Done', 'data' => ['id' => 1]],
            $fetchit->success('Done', ['id' => 1])
        );
        $this->assertSame(
            ['success' => false, 'message' => 'Snippet "X" not found', 'data' => []],
            $fetchit->error('fetchit_err_snippet_nf', [], ['name' => 'X'])
        );
    }

    // ------------------------------------------------------------------ scripts

    public function testLoadScriptRegistersTheInitialisation()
    {
        $this->modx->resource = new FakeResource(7);
        $this->modx->options['fetchit.frontend.input.invalid.class'] = "  is-invalid \n  error ";
        $this->modx->options['fetchit.frontend.custom.invalid.class'] = '';
        $fetchit = $this->fetchit(['actionUrl' => '[[+assetsUrl]]action.php']);

        $fetchit->loadScript('abc');

        $this->assertCount(1, $this->modx->htmlBlocks);
        $this->assertMatchesRegularExpression('/FetchIt\.create\((\{.*\})\)/', $this->modx->htmlBlocks[0]);
        preg_match('/FetchIt\.create\((\{.*\})\)/', $this->modx->htmlBlocks[0], $match);
        $config = $this->decode($match[1]);
        $this->assertSame('abc', $config['action']);
        $this->assertSame('/assets/components/fetchit/action.php', $config['actionUrl']);
        $this->assertSame('is-invalid error', $config['inputInvalidClass']);
        $this->assertSame(7, $config['pageId']);
        $this->assertTrue($config['clearFieldsOnSuccess']);
        $this->assertSame('fetchit_err_request', $config['requestErrorMessage']);
    }

    public function testLoadScriptUsesTheConfiguredClassName()
    {
        $this->modx->options['fetchit.frontend.js.classname'] = 'MyForms';

        $this->fetchit()->loadScript('abc');

        $this->assertStringContainsString('MyForms.create(', $this->modx->htmlBlocks[0]);
    }

    private function render($html, array $options = [], $requested = true)
    {
        if ($requested) {
            $this->fetchit()->loadScript('abc');
        }
        $this->modx->options = array_merge($this->modx->options, [
            'fetchit.frontend.js' => '[[+assetsUrl]]js/fetchit.js',
            'fetchit.frontend.default.notifier' => false,
        ], $options);
        $this->modx->resource = new FakeResource(1);
        $this->modx->resource->_output = $html;
        $this->fetchit()->registerScript();

        return $this->modx->resource->_output;
    }

    public function testScriptGoesBeforeTheFirstScriptInHead()
    {
        $html = $this->render("<html><head>\n<title>T</title>\n<script src=\"/app.js\"></script>\n</head><body></body></html>");

        $this->assertMatchesRegularExpression(
            '#<script src="/assets/components/fetchit/js/fetchit\.js\?v=[^"]+" defer></script>\s*<script src="/app\.js">#',
            $html
        );
    }

    public function testScriptGoesBeforeTheEndOfHeadWithoutOtherScripts()
    {
        $html = $this->render('<html><head><title>T</title></head><body></body></html>');

        $this->assertMatchesRegularExpression('#fetchit\.js\?v=[^"]+" defer></script>\n</head>#', $html);
    }

    public function testNotifierAssetsAreAddedWhenEnabled()
    {
        $html = $this->render('<html><head></head><body></body></html>', [
            'fetchit.frontend.default.notifier' => true,
        ]);

        $this->assertStringContainsString('lib/notyf.min.css', $html);
        $this->assertStringContainsString('lib/notyf.min.js', $html);
    }

    public function testNothingIsInjectedWhenTheSnippetDidNotRun()
    {
        $page = '<html><head></head><body></body></html>';

        $this->assertSame($page, $this->render($page, [], false));
    }

    public function testScriptsAreAddedWithoutASession()
    {
        // Sites with anonymous sessions off got no script at all.
        $this->assertSame('', session_id());

        $html = $this->render('<html><head></head><body></body></html>');

        $this->assertStringContainsString('js/fetchit.js', $html);
    }

    public function testScriptsAreAddedOncePerRequest()
    {
        $this->render('<html><head></head><body></body></html>');

        $page = '<html><head></head><body></body></html>';
        $this->assertSame($page, $this->render($page, [], false));
    }

    public function testHeadWithAttributesOrInUpperCase()
    {
        $html = $this->render('<HTML><HEAD prefix="og: x"><SCRIPT src="/a.js"></SCRIPT></HEAD></HTML>');

        $this->assertMatchesRegularExpression('#<HEAD prefix="og: x"><script src="[^"]+fetchit\.js[^"]*" defer></script>\s*<SCRIPT src="/a\.js">#', $html);
    }

    public function testScriptsInTheBodyDoNotCount()
    {
        $html = $this->render('<html><head><title>T</title></head><body><script src="/b.js"></script></body></html>');

        $this->assertMatchesRegularExpression('#fetchit\.js[^"]*" defer></script>\s*</head>#', $html);
    }

    public function testPageWithoutHeadIsLeftAloneAndLogged()
    {
        $page = '<div>no head</div>';

        $this->assertSame($page, $this->render($page));
        $this->assertLogged('no <head>');
    }

    public function testScriptThatIsNotJavaScriptIsLogged()
    {
        $page = '<html><head></head><body></body></html>';

        $this->assertSame($page, $this->render($page, ['fetchit.frontend.js' => '/assets/app.css']));
        $this->assertLogged('fetchit.frontend.js');
    }

    public function testModuleScriptIsAccepted()
    {
        $html = $this->render('<html><head></head></html>', ['fetchit.frontend.js' => '/assets/forms.mjs?x=1']);

        $this->assertStringContainsString('src="/assets/forms.mjs?x=1?v=', $html);
    }

    public function testInitialisationWaitsForTheScript()
    {
        // Without the script (a cached call, a missing <head>) the page must
        // not throw a ReferenceError.
        $this->fetchit()->loadScript('abc');

        $this->assertStringContainsString('window.FetchIt ? FetchIt.create(', $this->modx->htmlBlocks[0]);
    }

    private function assertLogged($needle)
    {
        $messages = array_column($this->modx->logged, 1);
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("Nothing logged about \"{$needle}\": " . json_encode($messages));
    }
}
