<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Queue;

/**
 * Tenant key of a store view in the outbound request queue and error logger:
 * "store:<id>", as in ConfigManager. Rows enqueued by 4.0.x have none (null).
 */
final class TenantKey
{
    private const PREFIX = 'store:';

    public static function forStore(int $storeId): string
    {
        return self::PREFIX . $storeId;
    }

    /**
     * Store ID encoded in a tenant key, or null for a key that names no store (null, empty or another format).
     */
    public static function toStoreId(?string $tenantKey): ?int
    {
        if ($tenantKey === null || preg_match('/^store:(\d+)$/', $tenantKey, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] > 0 ? (int) $matches[1] : null;
    }
}
