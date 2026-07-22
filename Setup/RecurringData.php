<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
 * Reports the shop environment to Comfino on every `setup:upgrade` (module install and upgrade).
 *
 * Magento runs Setup\RecurringData after each `setup:upgrade` regardless of version, so this covers both the
 * initial install and every subsequent upgrade. The report is only sent when an API key is already configured —
 * on a clean install there is no API key yet (it can only be entered afterward via the admin config form), so the
 * call is a no-op there and only takes effect on later `setup:upgrade` runs once the merchant has configured the
 * module. Fire-and-forget: any failure is swallowed inside the reporter and here, so it can never block the setup
 * process.
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