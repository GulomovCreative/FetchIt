<?php

use PHPUnit\Framework\TestCase;

/**
 * _build/resolvers/setup.php installs FormIt together with FetchIt. These
 * tests load it for uninstall, which does nothing, and call the helpers it
 * defines. The install against modx.com runs in the integration tests.
 */
class SetupResolverTest extends TestCase
{
    /** @var Closure */
    private $readAnswer;

    /** @var Closure */
    private $downloadPackage;

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $transport = new xPDOTransport();
        $transport->xpdo = new modX();
        $options = [xPDOTransport::PACKAGE_ACTION => xPDOTransport::ACTION_UNINSTALL];

        $this->assertTrue(require dirname(__DIR__, 2) . '/_build/resolvers/setup.php');
        $this->readAnswer = $readAnswer;
        $this->downloadPackage = $downloadPackage;

        $this->dir = sys_get_temp_dir() . '/fetchit-setup-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    private function psr7($status, $body)
    {
        return new class($status, $body) {
            private $status;
            private $body;

            public function __construct($status, $body)
            {
                $this->status = $status;
                $this->body = $body;
            }

            public function getStatusCode()
            {
                return $this->status;
            }

            public function getBody()
            {
                return $this->body;
            }
        };
    }

    private const LIST = '<packages><package><name>FormIt</name></package></packages>';

    public function testReadsAModx3Answer()
    {
        list($xml, $error) = call_user_func($this->readAnswer, $this->psr7(200, self::LIST));

        $this->assertNull($error);
        $this->assertSame('FormIt', (string)$xml->package[0]->name);
    }

    public function testReadsAModx2Answer()
    {
        $response = new stdClass();
        $response->response = self::LIST;

        list($xml, $error) = call_user_func($this->readAnswer, $response);

        $this->assertNull($error);
        $this->assertSame('FormIt', (string)$xml->package[0]->name);
    }

    public function testUnreachableProvider()
    {
        // MODX 3 returns false when the request itself fails.
        list($xml, $error) = call_user_func($this->readAnswer, false);

        $this->assertNull($xml);
        $this->assertStringContainsString('could not reach', $error);
    }

    public function testHttpErrorFromTheProvider()
    {
        list($xml, $error) = call_user_func($this->readAnswer, $this->psr7(503, 'Down'));

        $this->assertNull($xml);
        $this->assertStringContainsString('HTTP 503', $error);
    }

    public function testAnswerThatIsNotXml()
    {
        list($xml, $error) = call_user_func($this->readAnswer, $this->psr7(200, '<html><body>Oops'));

        $this->assertNull($xml);
        $this->assertStringContainsString('not XML', $error);
    }

    public function testDownloadsAFile()
    {
        file_put_contents($this->dir . '/source.zip', 'zip');

        $error = call_user_func($this->downloadPackage, $this->dir . '/source.zip', $this->dir . '/target.zip');

        $this->assertNull($error);
        $this->assertSame('zip', file_get_contents($this->dir . '/target.zip'));
    }

    public function testMissingOrEmptyDownloadIsAnError()
    {
        file_put_contents($this->dir . '/empty.zip', '');

        $this->assertNotNull(call_user_func($this->downloadPackage, $this->dir . '/missing.zip', $this->dir . '/a.zip'));
        $this->assertNotNull(call_user_func($this->downloadPackage, $this->dir . '/empty.zip', $this->dir . '/b.zip'));
        $this->assertFileDoesNotExist($this->dir . '/a.zip');
        $this->assertFileDoesNotExist($this->dir . '/b.zip');
    }
}
