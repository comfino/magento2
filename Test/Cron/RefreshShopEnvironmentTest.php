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
use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use PHPUnit\Framework\TestCase;

final class RefreshShopEnvironmentTest extends TestCase
{
    public function testExecuteDelegatesToShopEnvironmentReporter(): void
    {
        $reporter = $this->createMock(ShopEnvironmentReporter::class);
        $reporter->expects($this->once())->method('report');

        (new RefreshShopEnvironment($reporter))->execute();
    }
}