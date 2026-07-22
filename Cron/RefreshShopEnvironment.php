<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Cron
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Cron;

use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;

/**
 * Daily resubmission of the shop environment report to the Comfino API.
 *
 * Keeps the API-side knowledge base fresh against platform/plugin version drift between admin saves. Fire-and-forget:
 * the reporter no-ops without an API key and swallows all failures internally, so cron stays healthy.
 */
class RefreshShopEnvironment
{
    public function __construct(private readonly ShopEnvironmentReporter $shopEnvironmentReporter)
    {
    }

    public function execute(): void
    {
        $this->shopEnvironmentReporter->report();
    }
}