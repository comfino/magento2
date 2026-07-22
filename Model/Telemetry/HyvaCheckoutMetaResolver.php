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

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Composer\InstalledVersions;
use Hyva\Checkout\Model\Config as HyvaCheckoutConfig;
use Magento\Framework\App\ObjectManager;
use Throwable;

/**
 * Resolves Hyvä Checkout / Hyvä Theme facts for the shop environment report's platform-specific `meta` bag.
 *
 * All lookups degrade to an empty result (rather than throwing) when Hyvä Checkout/Theme are not installed, so this
 * resolver is safe to call unconditionally from ShopEnvironmentReporter regardless of the active theme.
 */
class HyvaCheckoutMetaResolver
{
    private const PACKAGE_VERSIONS = [
        'hyva_theme_version' => 'hyva-themes/magento2-theme-module',
        'hyva_checkout_version' => 'hyva-themes/magento2-hyva-checkout',
    ];

    /**
     * @return array<string, string>
     */
    public function resolve(): array
    {
        return array_merge($this->resolvePackageVersions(), $this->resolveCheckoutMode());
    }

    /**
     * @return array<string, string>
     */
    private function resolvePackageVersions(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        $versions = [];

        foreach (self::PACKAGE_VERSIONS as $metaKey => $packageName) {
            try {
                if (InstalledVersions::isInstalled($packageName)) {
                    $versions[$metaKey] = (string) InstalledVersions::getPrettyVersion($packageName);
                }
            } catch (Throwable) {
                // Package metadata unavailable (e.g., corrupted installed.json) - leave this key unset.
            }
        }

        return $versions;
    }

    /**
     * Reports the active Hyvä Checkout configuration's mode: "onepage" when the active checkout declares a single step,
     * "multistep" otherwise (the stock "default"/"mobile" checkouts and any merchant-defined variant with more than one
     * step).
     *
     * @return array<string, string>
     */
    private function resolveCheckoutMode(): array
    {
        if (!class_exists(HyvaCheckoutConfig::class)) {
            return [];
        }

        try {
            $config = ObjectManager::getInstance()->get(HyvaCheckoutConfig::class);
            $checkoutData = $config->getActiveCheckoutData();
            $stepCount = count($checkoutData['steps'] ?? []);

            return [
                'hyva_checkout_namespace' => (string) ($checkoutData['name'] ?? $config->getActiveCheckoutNamespace()),
                'hyva_checkout_mode' => $stepCount <= 1 ? 'onepage' : 'multistep',
            ];
        } catch (Throwable $e) {
            DebugLogger::logEvent(
                'HyvaCheckoutMetaResolver::resolveCheckoutMode: failed',
                ['exceptionMessage' => $e->getMessage()],
                'SHOP_ENVIRONMENT'
            );

            return [];
        }
    }
}
