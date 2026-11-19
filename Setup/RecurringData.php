<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Setup;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Throwable;

/**
 * Reports the shop environment to ComfinoPay on every `setup:upgrade`, only when an API key is already configured.
 * Failures are swallowed so they never block setup.
 */
class RecurringData implements InstallDataInterface
{
    /**
     * @var ShopEnvironmentReporter
     */
    private $shopEnvironmentReporter;

    public function __construct(ShopEnvironmentReporter $shopEnvironmentReporter)
    {
        $this->shopEnvironmentReporter = $shopEnvironmentReporter;
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            if (!empty(ConfigManager::getApiKey())) {
                $this->shopEnvironmentReporter->report();
            }
        } catch (Throwable $e) {
            // Fire-and-forget — environment reporting must never block setup:upgrade.
        }
    }
}
