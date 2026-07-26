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

use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;

/**
 * Daily resubmission of the shop environment report to the Comfino API, plus the CETS access token refresh.
 *
 * Keeps the API-side knowledge base fresh against platform/plugin version drift between admin saves. Fire-and-forget:
 * the reporter no-ops without an API key and swallows all failures internally, so cron stays healthy.
 *
 * The token refresh rides along here because it writes config values and flushes the `config` cache type, which must
 * not happen on a shopper-facing request - see Bootstrap::tokenRefreshAllowedHere().
 */
class RefreshShopEnvironment
{
    public function __construct(
        private readonly ShopEnvironmentReporter $shopEnvironmentReporter,
        private readonly Bootstrap $bootstrap
    ) {
    }

    public function execute(): void
    {
        /* controller_action_predispatch never fires in the crontab area, so BootstrapObserver does not run here, and
           the SDK singletons this job depends on would otherwise be uninitialized. */
        $this->bootstrap->init();

        ConfigManager::refreshErrorLoggingTokenIfNeeded();

        $this->shopEnvironmentReporter->report();
    }
}
