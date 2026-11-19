<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Telemetry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Telemetry;

use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Throwable;

/**
 * Fire-and-forget service reporting the full shop environment (versions, theme metadata, capability hints) to the
 * ComfinoPay API, server-to-server only.
 *
 * Failures are logged and never propagated: the report must not impact paywall or widget functionality.
 */
class ShopEnvironmentReporter
{
    public function __construct(
        private readonly AbstractShopEnvironmentBuilder $shopEnvironmentBuilder,
        private readonly TestProductUrlResolver $testProductUrlResolver,
        private readonly HyvaCheckoutMetaResolver $hyvaCheckoutMetaResolver
    ) {
    }

    /**
     * Sends the current shop environment report to the ComfinoPay API.
     *
     * @return bool True if the report was accepted, false on any failure.
     */
    public function report(): bool
    {
        if (empty(ConfigManager::getApiKey())) {
            return false;
        }

        try {
            $testProductUrl = $this->testProductUrlResolver->resolve();
            $report = $this->shopEnvironmentBuilder->buildForBackendReport($testProductUrl, $this->hyvaCheckoutMetaResolver->resolve());

            $result = ApiClient::getInstance()->reportShopEnvironment($report);

            DebugLogger::logEvent('ShopEnvironmentReporter::report: ' . ($result ? 'accepted' : 'rejected by API'), null, 'SHOP_ENVIRONMENT');

            return $result;
        } catch (Throwable $e) {
            DebugLogger::logEvent('ShopEnvironmentReporter::report: failed', ['exceptionMessage' => $e->getMessage()], 'SHOP_ENVIRONMENT');

            return false;
        }
    }

    /**
     * Builds the current shop environment report as an array, for on-demand exposure via the configuration endpoint.
     *
     * @return array<string, mixed>|null The report array, or null on failure.
     */
    public function getReportArray(): ?array
    {
        try {
            $testProductUrl = $this->testProductUrlResolver->resolve();

            return $this->shopEnvironmentBuilder->buildReportArray($testProductUrl, $this->hyvaCheckoutMetaResolver->resolve());
        } catch (Throwable $e) {
            DebugLogger::logEvent('ShopEnvironmentReporter::getReportArray: failed', ['exceptionMessage' => $e->getMessage()], 'SHOP_ENVIRONMENT');

            return null;
        }
    }
}
