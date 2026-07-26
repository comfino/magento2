<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Helper
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Helper;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Version;
use Comfino\Platform\PlatformInfoInterface;
use Magento\Framework\App\Helper\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    private PlatformInfoInterface&MockObject $platformInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformInfo = $this->createMock(PlatformInfoInterface::class);
    }

    private function makeHelper(): Data
    {
        $context = $this->createMock(Context::class);

        return new Data($context, $this->platformInfo);
    }

    public function testGetModuleVersionReturnsModuleConstant(): void
    {
        $this->assertSame(Version::VERSION, $this->makeHelper()->getModuleVersion());
    }

    public function testGetShopVersionReturnsPlatformVersion(): void
    {
        $this->platformInfo->method('getVersion')->willReturn('2.4.8-p4');

        $this->assertSame('2.4.8-p4', $this->makeHelper()->getShopVersion());
    }

    public function testGetDatabaseInfoReturnsNaForEmptyVersion(): void
    {
        $this->platformInfo->method('getDatabaseVersion')->willReturn('');

        $this->assertSame('n/a', $this->makeHelper()->getDatabaseInfo());
    }

    public function testGetDatabaseInfoReturnsNaForUnknownVersion(): void
    {
        $this->platformInfo->method('getDatabaseVersion')->willReturn('unknown');

        $this->assertSame('n/a', $this->makeHelper()->getDatabaseInfo());
    }

    public function testGetDatabaseInfoFormatsMariaDb(): void
    {
        $this->platformInfo->method('getDatabaseVersion')->willReturn('10.6.12-MariaDB-1:10.6.12+maria~ubu2004');

        $this->assertSame('MariaDB 10.6.12', $this->makeHelper()->getDatabaseInfo());
    }

    public function testGetDatabaseInfoFormatsMySql(): void
    {
        $this->platformInfo->method('getDatabaseVersion')->willReturn('8.0.32-0ubuntu0.20.04.2');

        $this->assertSame('MySQL 8.0.32', $this->makeHelper()->getDatabaseInfo());
    }

    public function testGetShopDomainReturnsPlatformDomain(): void
    {
        $this->platformInfo->method('getDomain')->willReturn('shop.example.com');

        $this->assertSame('shop.example.com', $this->makeHelper()->getShopDomain());
    }

    public function testGetShopLanguageReturnsPlatformLanguage(): void
    {
        $this->platformInfo->method('getLanguage')->willReturn('pl');

        $this->assertSame('pl', $this->makeHelper()->getShopLanguage());
    }
}