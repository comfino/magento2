<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Csp;

use Comfino\ComfinoGateway\Model\Csp\DevHostsPolicyCollector;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Csp\Model\Policy\FetchPolicy;
use PHPUnit\Framework\TestCase;

final class DevHostsPolicyCollectorTest extends TestCase
{
    private const ENV_VARS = ['COMFINO_DEV_ENV', 'COMFINO_DEV_API_HOST', 'COMFINO_DEV_SDK_CDN_BASE_URL'];

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $envVar) {
            putenv($envVar);
        }

        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testReturnsDefaultPoliciesUnchangedOutsideTheDevEnvironment(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        $existing = [new FetchPolicy('script-src', false, ['sdk.comfino.pl'])];

        $this->assertSame($existing, (new DevHostsPolicyCollector())->collect($existing));
    }

    public function testReturnsDefaultPoliciesUnchangedWhenTheDevOptionIsOff(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => '0']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        $this->assertSame([], (new DevHostsPolicyCollector())->collect([]));
    }

    public function testAddsTheDevApiAndSdkHostsToTheirPolicies(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');

        $existing = new FetchPolicy('script-src', false, ['sdk.comfino.pl']);
        $policies = (new DevHostsPolicyCollector())->collect([$existing]);

        $this->assertSame($existing, $policies[0]);

        $hosts = [];

        foreach (array_slice($policies, 1) as $policy) {
            $this->assertInstanceOf(FetchPolicy::class, $policy);
            $this->assertFalse($policy->isNoneAllowed());
            $hosts[$policy->getId()] = $policy->getHostSources();
        }

        $this->assertSame(['https://api-ecommerce.craty.pl'], $hosts['frame-src']);
        $this->assertSame(['https://widget.craty.pl'], $hosts['script-src']);
        $this->assertSame(['https://widget.craty.pl'], $hosts['style-src']);
        $this->assertSame(['https://api-ecommerce.craty.pl', 'https://widget.craty.pl'], $hosts['img-src']);
        $this->assertSame(['https://api-ecommerce.craty.pl', 'https://widget.craty.pl'], $hosts['connect-src']);
    }

    public function testIgnoresANonAllowListedApiOverride(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => '1', 'COMFINO_IS_SANDBOX' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://attacker.example.com');

        foreach ((new DevHostsPolicyCollector())->collect([]) as $policy) {
            $this->assertInstanceOf(FetchPolicy::class, $policy);
            /** @var FetchPolicy $policy */
            $this->assertNotContains('https://attacker.example.com', $policy->getHostSources());
            $this->assertNotSame('frame-src', $policy->getId());
        }
    }
}
