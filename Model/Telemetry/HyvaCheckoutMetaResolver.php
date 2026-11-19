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

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Composer\InstalledVersions;
use Hyva\Checkout\Model\Config as HyvaCheckoutConfig;
use Magento\Framework\App\ObjectManager;
use Throwable;

/**
 * Resolves Hyvä Checkout / Theme facts for the shop environment report's `meta` bag; degrades to an empty result when Hyvä is not installed.
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
     * "onepage" when the active Hyvä Checkout declares a single step, "multistep" otherwise.
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
            DebugLogger::logEvent('HyvaCheckoutMetaResolver::resolveCheckoutMode: failed', ['exceptionMessage' => $e->getMessage()], 'SHOP_ENVIRONMENT');

            return [];
        }
    }
}
