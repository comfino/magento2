<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Cron
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Cron;

use Comfino\ComfinoGateway\Cron\RefreshShopEnvironment;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\TestCase;

final class RefreshShopEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* execute() refreshes the CETS token before reporting; an empty API key makes that short-circuit at its first
           guard, so it never resolves an ApiClient through the (uninitialized) ObjectManager. */
        ConfigManagerHarness::install(['COMFINO_API_KEY' => '', 'COMFINO_IS_SANDBOX' => false]);
    }

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testExecuteBootstrapsThenDelegatesToShopEnvironmentReporter(): void
    {
        $bootstrap = $this->createMock(Bootstrap::class);
        $bootstrap->expects($this->once())->method('init');

        $reporter = $this->createMock(ShopEnvironmentReporter::class);
        $reporter->expects($this->once())->method('report');

        (new RefreshShopEnvironment($reporter, $bootstrap))->execute();
    }
}