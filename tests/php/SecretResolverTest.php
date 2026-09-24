<?php

use PHPUnit\Framework\TestCase;

/**
 * _build/resolvers/secret.php gives the protection a random signing key on
 * install and upgrade.
 */
class SecretResolverTest extends TestCase
{
    private function resolve($action, modX $modx)
    {
        $transport = new xPDOTransport();
        $transport->xpdo = $modx;
        $options = [xPDOTransport::PACKAGE_ACTION => $action];

        return require dirname(__DIR__, 2) . '/_build/resolvers/secret.php';
    }

    public function testAnEmptyKeyIsFilledWithARandomOne()
    {
        $modx = new modX();
        $setting = $modx->settings['fetchit.protection.secret'] = new FakeSetting('fetchit.protection.secret', '');

        $this->assertTrue($this->resolve(xPDOTransport::ACTION_INSTALL, $modx));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $setting->get('value'));
        $this->assertTrue($setting->saved);
        $this->assertSame([['system_settings' => []]], $modx->cacheManager->refreshed);
    }

    public function testAKeyOfTheSiteIsKeptOnUpgrade()
    {
        $modx = new modX();
        $setting = $modx->settings['fetchit.protection.secret'] = new FakeSetting('fetchit.protection.secret', 'chosen by the site');

        $this->resolve(xPDOTransport::ACTION_UPGRADE, $modx);

        $this->assertSame('chosen by the site', $setting->get('value'));
        $this->assertFalse($setting->saved);
    }

    public function testEachInstallationGetsItsOwnKey()
    {
        $keys = [];
        foreach ([1, 2] as $site) {
            $modx = new modX();
            $modx->settings['fetchit.protection.secret'] = new FakeSetting('fetchit.protection.secret', '');
            $this->resolve(xPDOTransport::ACTION_INSTALL, $modx);
            $keys[] = $modx->settings['fetchit.protection.secret']->get('value');
        }

        $this->assertNotSame($keys[0], $keys[1]);
    }
}
