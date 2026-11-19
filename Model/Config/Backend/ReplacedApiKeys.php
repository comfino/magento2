<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Config\Backend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Config\Backend;

/**
 * Request-scoped record of the API keys replaced by the config save in progress, so ConfigObserver can restore the previous
 * key when the API rejects the new one. Keyed by config path, scope and scope ID; values are raw stored strings.
 */
final class ReplacedApiKeys
{
    /** @var array<string, string> */
    private static array $previousValues = [];

    public static function remember(string $path, string $scope, int $scopeId, string $previousValue): void
    {
        self::$previousValues[self::id($path, $scope, $scopeId)] = $previousValue;
    }

    /**
     * The stored value the save replaced, or null when this save did not set a new key for that path and scope.
     */
    public static function previous(string $path, string $scope, int $scopeId): ?string
    {
        return self::$previousValues[self::id($path, $scope, $scopeId)] ?? null;
    }

    public static function reset(): void
    {
        self::$previousValues = [];
    }

    private static function id(string $path, string $scope, int $scopeId): string
    {
        return "$scope:$scopeId:$path";
    }
}
