<?php

use PHPUnit\Framework\TestCase;

/**
 * FetchIt 4 is one package for MODX 2 and MODX 3, and keeps the API of both
 * FetchIt 1.x (getService(), store/loadActionProperties()) and 3.x
 * ($modx->services, FetchIt\FetchIt, save/getActionProperties()).
 */
class ModxApiTest extends TestCase
{
    /** @var modX */
    private $modx;

    protected function setUp(): void
    {
        $this->modx = new modX();
    }

    private function onModx3()
    {
        $this->modx->services = new FakeContainer();
    }

    public function testServiceOnModx2ComesFromGetService()
    {
        $service = FetchIt::service($this->modx);

        $this->assertInstanceOf(FetchIt::class, $service);
        $this->assertSame($service, FetchIt::service($this->modx));
        $this->assertSame($service, $this->modx->getService('fetchit', 'FetchIt'));
    }

    public function testServiceOnModx3ComesFromTheContainer()
    {
        $this->onModx3();

        $service = FetchIt::service($this->modx);

        $this->assertInstanceOf(FetchIt::class, $service);
        $this->assertSame($service, $this->modx->services->get('FetchIt'));
        $this->assertSame($service, FetchIt::service($this->modx));
    }

    public function testBootstrapRegistersTheServiceAndTheNamespace()
    {
        $this->onModx3();
        $modx = $this->modx;
        $namespace = ['path' => MODX_CORE_PATH . 'components/fetchit/'];

        require MODX_CORE_PATH . 'components/fetchit/bootstrap.php';

        $this->assertInstanceOf(FetchIt::class, $modx->services->get('FetchIt'));
        $this->assertSame(MODX_CORE_PATH . 'components/fetchit/src/', modX::getLoader()->psr4['FetchIt\\']);
    }

    /**
     * instanceof does not autoload: code written for 3.x checks
     * "instanceof \FetchIt\FetchIt" before anything loaded src/FetchIt.php.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheModelAloneDefinesThe3xName()
    {
        $this->assertTrue(class_exists('FetchIt\FetchIt', false));
        $this->assertInstanceOf('FetchIt\FetchIt', new FetchIt($this->modx));
    }

    public function testNamespacedClassOf3xIsTheSameClass()
    {
        require_once MODX_CORE_PATH . 'components/fetchit/src/FetchIt.php';

        $fetchit = new \FetchIt\FetchIt($this->modx);

        $this->assertInstanceOf(FetchIt::class, $fetchit);
        $this->assertInstanceOf(\FetchIt\FetchIt::class, FetchIt::service($this->modx));
    }

    public function testActionPropertyMethodsOf3x()
    {
        $fetchit = new FetchIt($this->modx);

        $fetchit->saveActionProperties('abc', ['snippet' => 'FormIt']);

        $this->assertSame(['snippet' => 'FormIt'], $fetchit->getActionProperties('abc'));
        $this->assertSame(['snippet' => 'FormIt'], $fetchit->loadActionProperties('abc'));
    }

    public function testPdoToolsOnModx3ComesFromTheContainer()
    {
        // pdoTools 3 has no "pdoTools" class, only the service.
        $this->onModx3();
        $pdo = new stdClass();
        $this->modx->services->add('pdoTools', $pdo);

        $this->assertSame($pdo, FetchIt::pdoTools($this->modx));
    }

    public function testNoPdoTools()
    {
        $this->assertNull(FetchIt::pdoTools($this->modx));

        $this->onModx3();
        $this->assertNull(FetchIt::pdoTools($this->modx));
    }
}
