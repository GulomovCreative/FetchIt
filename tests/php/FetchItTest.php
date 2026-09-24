<?php

use PHPUnit\Framework\TestCase;

class FetchItTest extends TestCase
{
    /** @var modX */
    private $modx;

    protected function setUp(): void
    {
        $this->modx = new modX();
        // The markup and the submissions of the protection: GuardTest.
        $this->modx->options['fetchit.protection'] = false;
        $_SESSION = [];
        // The "scripts requested" flag lives for one request, i.e. one test.
        $flag = new ReflectionProperty(FetchIt::class, 'scriptRequested');
        if (PHP_VERSION_ID < 80100) {
            $flag->setAccessible(true);
        }
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

    public function testPcreFailureLeavesTheChunkAsItWasAndIsLogged()
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $chunk = '<form class="f" id="x"><input></form>';
            $html = $this->fetchit()->prepareForm($chunk, 'abc');
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }

        $this->assertSame($chunk, $html);
        $this->assertLogged('Could not prepare the form');
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

    public function testUnknownPropertySetIsLoggedAndIgnored()
    {
        // A typo in &snippet=`Handler@set` threw a TypeError on PHP 8.
        $snippet = $this->addSnippet('Handler', function () {
            return '{"success":true}';
        });
        $snippet->properties = ['a' => 'default'];
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'Handler@typo']);

        $this->assertSame('{"success":true}', $fetchit->process('abc', []));
        $this->assertSame('default', $snippet->received['a']);
        $this->assertLogged('typo');
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
        $this->assertMatchesRegularExpression('/\.create\((\{.*?\})\);/', $this->modx->htmlBlocks[0]);
        preg_match('/\.create\((\{.*?\})\);/', $this->modx->htmlBlocks[0], $match);
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

        $this->assertStringContainsString('FetchItClass = MyForms;', $this->modx->htmlBlocks[0]);
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

    public function testVersionKeepsTheQueryStringAndFragment()
    {
        $html = $this->render('<html><head></head></html>', ['fetchit.frontend.js' => '/assets/forms.js?x=1#top']);

        $this->assertMatchesRegularExpression('#src="/assets/forms\.js\?x=1&v=[^"\#]+\#top"#', $html);
    }

    public function testInitialisationWaitsForTheScript()
    {
        $this->modx->options['fetchit.frontend.js.classname'] = 'App.Forms';
        $this->fetchit()->loadScript('abc');

        // The inline code finds the class whether it is a window property,
        // a top-level "class" declaration or a dotted name, and does not
        // throw when the script is missing (a cached call, no <head>).
        $block = $this->modx->htmlBlocks[0];
        $this->assertStringContainsString('try { FetchItClass = App.Forms; } catch (e) {}', $block);
        $this->assertStringContainsString('FetchItClass.create({', $block);
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


    /**
     * What FormIt 5.2+ does on a page view: keeps its properties for its
     * own action.php and links formit.js.
     */
    private function formItWithAjax($token)
    {
        $this->modx->options['formit.frontend_js'] = 'js/web/formit.js';

        return $this->addSnippet('FormIt', function ($properties) use ($token) {
            $prefix = isset($properties['placeholderPrefix']) ? $properties['placeholderPrefix'] : 'fi.';
            $_SESSION['formit'][$token] = $properties;
            $this->modx->cacheManager->set('formit/props_' . $token, $properties);
            $this->modx->setPlaceholder($prefix . 'ajaxToken', $token);
            $this->modx->regClientScript('/assets/components/formit/js/web/formit.js');
            $this->modx->regClientScript('<script>Object.assign(FormIt,{"actionUrl":"/assets/components/formit/action.php"});</script>', true);

            return '';
        });
    }

    public function testTheAjaxModeOfFormItIsTurnedOff()
    {
        $token = str_repeat('ab', 16);
        $this->formItWithAjax($token);
        $this->modx->regClientScript('/assets/site.js');
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt', 'placeholderPrefix' => 'form.']);

        $fetchit->process('abc', []);

        $this->assertArrayNotHasKey($token, $_SESSION['formit'], 'No properties for the action.php of FormIt in the session');
        $this->assertArrayNotHasKey('formit/props_' . $token, $this->modx->cacheManager->items, 'nor in the cache');
        $this->assertArrayNotHasKey('form.ajaxToken', $this->modx->placeholders);
        $this->assertSame(['<script src="/assets/site.js"></script>'], $this->modx->jscripts, 'formit.js is not linked; other scripts stay');
        $this->assertSame(['/assets/site.js' => true], $this->modx->loadedjscripts);
    }

    public function testFormItJsLinkedBeforeByAnotherFormStays()
    {
        // A FormIt form of its own on the same page, called before FetchIt.
        $this->modx->regClientScript('/assets/components/formit/js/web/formit.js');
        $this->formItWithAjax(str_repeat('cd', 16));
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $fetchit->process('abc', []);

        $this->assertSame(['<script src="/assets/components/formit/js/web/formit.js"></script>'], $this->modx->jscripts);
        $this->assertArrayNotHasKey('fi.ajaxToken', $this->modx->placeholders);
    }

    public function testFormItWithoutAjaxIsLeftAlone()
    {
        // FormIt before 5.2.
        $this->addSnippet('FormIt', function () {
            $this->modx->setPlaceholder('fi.name', 'Ann');

            return '';
        });
        $this->modx->regClientScript('/assets/site.js');
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $fetchit->process('abc', []);

        $this->assertSame('Ann', $this->modx->placeholders['fi.name']);
        $this->assertSame(['<script src="/assets/site.js"></script>'], $this->modx->jscripts);
    }

    public function testAFormItFormOnTheSamePageKeepsItsAjaxMode()
    {
        // [[!FormIt]] of its own before the FetchIt call, with the same prefix.
        $theirs = str_repeat('ef', 16);
        $_SESSION['formit'][$theirs] = ['hooks' => 'email'];
        $this->modx->cacheManager->items['formit/props_' . $theirs] = ['hooks' => 'email'];
        $this->modx->setPlaceholder('fi.ajaxToken', $theirs);
        $this->formItWithAjax(str_repeat('01', 16));
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $fetchit->process('abc', []);

        $this->assertSame($theirs, $this->modx->placeholders['fi.ajaxToken'], 'Their form still gets its token');
        $this->assertSame(['hooks' => 'email'], $_SESSION['formit'][$theirs]);
        $this->assertSame(['hooks' => 'email'], $this->modx->cacheManager->items['formit/props_' . $theirs]);
        $this->assertSame([$theirs], array_keys($_SESSION['formit']), 'Only theirs is kept');
    }

    public function testASubmissionLeavesTheTokenOfAnotherFormItFormAlone()
    {
        // On a submission FormIt stores nothing and sets no token: the
        // placeholder still holds the token of the other form.
        $theirs = str_repeat('ef', 16);
        $_SESSION['formit'][$theirs] = ['hooks' => 'email'];
        $this->modx->cacheManager->items['formit/props_' . $theirs] = ['hooks' => 'email'];
        $this->modx->setPlaceholder('fi.ajaxToken', $theirs);
        $this->addSnippet('FormIt', function () {
            return '';
        });
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);

        $fetchit->process('abc', ['email' => 'ann@example.com']);

        $this->assertSame($theirs, $this->modx->placeholders['fi.ajaxToken']);
        $this->assertArrayHasKey($theirs, $_SESSION['formit']);
        $this->assertArrayHasKey('formit/props_' . $theirs, $this->modx->cacheManager->items);
    }

    public function testFormItJsIsLinkedAgainByAFormItFormAfterFetchIt()
    {
        $this->formItWithAjax(str_repeat('ab', 16));
        $fetchit = $this->fetchit();
        $fetchit->storeActionProperties('abc', ['snippet' => 'FormIt']);
        $fetchit->process('abc', []);

        // [[!FormIt]] of its own further down the page.
        $this->modx->regClientScript('/assets/components/formit/js/web/formit.js');

        $this->assertSame(['<script src="/assets/components/formit/js/web/formit.js"></script>'], $this->modx->jscripts);
    }
}
