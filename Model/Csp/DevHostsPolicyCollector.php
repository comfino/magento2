<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Csp
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Csp;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Throwable;

/**
 * Adds the development API and SDK CDN hosts to the CSP only when the development overrides are active (COMFINO_DEV_ENV=TRUE
 * and the dev env vars option on). csp_whitelist.xml lists only the production and sandbox hosts, so a production shop never
 * allows a development or staging host.
 */
class DevHostsPolicyCollector implements PolicyCollectorInterface
{
    private const API_POLICIES = ['frame-src', 'img-src', 'connect-src'];
    private const SDK_POLICIES = ['script-src', 'style-src', 'img-src', 'connect-src'];

    public function collect(array $defaultPolicies = []): array
    {
        if (getenv('COMFINO_DEV_ENV') !== 'TRUE') {
            return $defaultPolicies;
        }

        try {
            if (!ConfigManager::useDevEnvVars()) {
                return $defaultPolicies;
            }

            $hostsByPolicy = [];

            if (($apiHost = $this->toHostSource(ConfigManager::getApiHost())) !== null) {
                foreach (self::API_POLICIES as $policyId) {
                    $hostsByPolicy[$policyId][] = $apiHost;
                }
            }

            if (($sdkHost = $this->toHostSource(ConfigManager::getSdkScriptUrl())) !== null) {
                foreach (self::SDK_POLICIES as $policyId) {
                    $hostsByPolicy[$policyId][] = $sdkHost;
                }
            }
        } catch (Throwable) {
            return $defaultPolicies;
        }

        foreach ($hostsByPolicy as $policyId => $hosts) {
            $defaultPolicies[] = new FetchPolicy($policyId, false, array_values(array_unique($hosts)));
        }

        return $defaultPolicies;
    }

    /**
     * "scheme://host[:port]" of an absolute URL, or null when the URL has no host.
     */
    private function toHostSource(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $source = isset($parts['scheme']) ? strtolower($parts['scheme']) . '://' . $parts['host'] : $parts['host'];

        return isset($parts['port']) ? $source . ':' . $parts['port'] : $source;
    }
}
