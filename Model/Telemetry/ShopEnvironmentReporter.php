<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Telemetry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
 * Fire-and-forget service that reports the full shop environment to the Comfino API.
 *
 * The report carries platform/plugin versions, edition, theme metadata, and capability hints that belong
 * server-to-server only (never exposed to the browser). The API uses the data to build a per-theme selector
 * knowledge base and return auto-detection recommendations.
 *
 * Failures are logged via the debug logger and never propagated to the caller — this service must not impact paywall
 * or widget functionality.
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
     * Sends the current shop environment report to the Comfino API.
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
            $report = $this->shopEnvironmentBuilder->buildForBackendReport(
                $testProductUrl,
                $this->hyvaCheckoutMetaResolver->resolve()
            );

            $result = ApiClient::getInstance()->reportShopEnvironment($report);

            DebugLogger::logEvent(
                'ShopEnvironmentReporter::report: ' . ($result ? 'accepted' : 'rejected by API'),
                null,
                'SHOP_ENVIRONMENT'
            );

            return $result;
        } catch (Throwable $e) {
            DebugLogger::logEvent(
                'ShopEnvironmentReporter::report: failed',
                ['exceptionMessage' => $e->getMessage()],
                'SHOP_ENVIRONMENT'
            );

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

            return $this->shopEnvironmentBuilder->buildReportArray(
                $testProductUrl,
                $this->hyvaCheckoutMetaResolver->resolve()
            );
        } catch (Throwable $e) {
            DebugLogger::logEvent(
                'ShopEnvironmentReporter::getReportArray: failed',
                ['exceptionMessage' => $e->getMessage()],
                'SHOP_ENVIRONMENT'
            );

            return null;
        }
    }
}
