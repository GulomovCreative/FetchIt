<?php

use PHPUnit\Framework\TestCase;

/**
 * _build/resolvers/store.php creates the table of fetchit.protection.store db
 * on install and upgrade and drops it on uninstall, never stopping the
 * installation.
 */
class StoreResolverTest extends TestCase
{
    /** @var modX */
    private $modx;

    protected function setUp(): void
    {
        $this->modx = fetchit_test_modx();
        $this->store()->uninstall();
    }

    protected function tearDown(): void
    {
        $this->store()->uninstall();
    }

    private function store()
    {
        return new FetchItDbStore($this->modx, function () {});
    }

    private function resolve($action)
    {
        $transport = new xPDOTransport();
        $transport->xpdo = $this->modx;
        $options = [xPDOTransport::PACKAGE_ACTION => $action];
        $modx = null;

        return require dirname(__DIR__, 2) . '/_build/resolvers/store.php';
    }

    public function testInstallCreatesTheTable()
    {
        $this->assertTrue($this->resolve(xPDOTransport::ACTION_INSTALL));

        $this->assertTrue($this->store()->exists());
        $this->assertSame('marked', $this->store()->mark('ab12cd34ef56ab78', time() + 3600));
    }

    public function testUpgradeKeepsWhatIsThere()
    {
        $this->resolve(xPDOTransport::ACTION_INSTALL);
        $this->store()->mark('ab12cd34ef56ab78', time() + 3600);

        $this->assertTrue($this->resolve(xPDOTransport::ACTION_UPGRADE));

        $this->assertSame('used', $this->store()->mark('ab12cd34ef56ab78', time() + 3600));
    }

    public function testUninstallDropsTheTable()
    {
        $this->resolve(xPDOTransport::ACTION_INSTALL);

        $this->assertTrue($this->resolve(xPDOTransport::ACTION_UNINSTALL));

        $this->assertFalse($this->store()->exists());
    }

    public function testUninstallWithoutTheTableIsFine()
    {
        $this->assertTrue($this->resolve(xPDOTransport::ACTION_UNINSTALL));

        $this->assertSame([], $this->modx->logged);
    }

    /**
     * @dataProvider errorModes
     */
    public function testUninstallDropsTheTableInEitherErrorMode($errorMode)
    {
        $this->modx = fetchit_test_modx($errorMode);
        $this->store()->install();

        $this->assertTrue($this->resolve(xPDOTransport::ACTION_UNINSTALL));

        $this->assertFalse($this->store()->exists());
        $this->assertSame([], $this->modx->logged);
    }

    public function errorModes()
    {
        return [
            'silent' => [PDO::ERRMODE_SILENT],
            'exceptions' => [PDO::ERRMODE_EXCEPTION],
        ];
    }

    public function testAFailureDoesNotStopTheInstallation()
    {
        // A database user without CREATE: a warning, and the installation goes on.
        // A quote of every driver ends the name early: CREATE TABLE fails everywhere.
        $this->modx->options['table_prefix'] = '`"]';

        $this->assertTrue($this->resolve(xPDOTransport::ACTION_INSTALL));

        $this->assertStringContainsString('Could not create the table', implode("\n", array_column($this->modx->logged, 1)));
    }
}
