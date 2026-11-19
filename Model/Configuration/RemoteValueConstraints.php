<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Closure;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\Constraint\AllowedValuesConstraint;
use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\Backend\Configuration\Constraint\IntRangeConstraint;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\BooleanConstraint;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\CssUrlConstraint;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\ListConstraint;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\StatusMapConstraint;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\Enum\OrderStatus;

/**
 * Value rules for options ComfinoPay changes through the signed remote configuration endpoint: a rule-breaking value is
 * rejected and the stored one kept; out-of-range numbers are clamped.
 */
final class RemoteValueConstraints
{
    public const MIN_TIMEOUT = 1;
    public const MAX_CONNECT_TIMEOUT = 5;
    public const MAX_TRANSFER_TIMEOUT = 15;
    public const MIN_ATTEMPTS = 1;
    public const MAX_ATTEMPTS = 3;

    public const CSS_URL_OPTIONS = [
        'COMFINO_PAYWALL_CUSTOM_CSS_URL',
        'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL',
        'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL',
    ];

    public const REMOTE_FLAG_PATTERN = '/^[A-Z0-9_]{1,64}$/';

    private const LOAN_TYPE_PATTERN = '/^[A-Z_]{1,40}$/';

    /** @var array<string, array{0: int, 1: int}> Inclusive [min, max] per integer option */
    private const INT_RANGES = [
        'COMFINO_API_CONNECT_TIMEOUT' => [self::MIN_TIMEOUT, self::MAX_CONNECT_TIMEOUT],
        'COMFINO_API_TIMEOUT' => [self::MIN_TIMEOUT, self::MAX_TRANSFER_TIMEOUT],
        'COMFINO_API_CONNECT_NUM_ATTEMPTS' => [self::MIN_ATTEMPTS, self::MAX_ATTEMPTS],
        'COMFINO_API_QUEUE_CONNECT_TIMEOUT' => [self::MIN_TIMEOUT, self::MAX_CONNECT_TIMEOUT],
        'COMFINO_API_QUEUE_TIMEOUT' => [self::MIN_TIMEOUT, self::MAX_TRANSFER_TIMEOUT],
        'COMFINO_PROD_CAT_CACHE_TTL' => [60, 86400],
        'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL' => [0, 10],
        'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS' => [1, 50],
        'COMFINO_RETRY_QUEUE_BATCH_SIZE' => [1, 100],
        'COMFINO_RETRY_QUEUE_COOLDOWN' => [0, 86400],
    ];

    /**
     * @param Closure(): string[] $shopHosts Hosts of the shop's base URLs, for the stylesheet URL options
     * @param Closure(): bool $allowHttp Whether plain http stylesheet URLs are accepted (development environment)
     *
     * @return array<string, ConfigValueConstraintInterface>
     */
    public static function build(Closure $shopHosts, Closure $allowHttp): array
    {
        $constraints = [];

        foreach (ConfigManager::CONFIG_OPTIONS as $options) {
            foreach ($options as $optionName => $optionType) {
                if ($optionType === ConfigurationManager::OPT_VALUE_TYPE_BOOL) {
                    $constraints[$optionName] = new BooleanConstraint();
                }
            }
        }

        foreach (self::INT_RANGES as $optionName => [$min, $max]) {
            $constraints[$optionName] = new IntRangeConstraint($min, $max);
        }

        $cssUrlConstraint = new CssUrlConstraint($shopHosts, $allowHttp);

        foreach (self::CSS_URL_OPTIONS as $optionName) {
            $constraints[$optionName] = $cssUrlConstraint;
        }

        $comfinoStatuses = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());
        $shopStatuses = array_keys(ShopStatusManager::CUSTOM_STATUS_LABELS);

        $constraints['COMFINO_STATUS_MAP'] = new StatusMapConstraint($comfinoStatuses, $shopStatuses);
        $constraints['COMFINO_IGNORED_STATUSES'] = new ListConstraint($comfinoStatuses);
        $constraints['COMFINO_FORBIDDEN_STATUSES'] = new ListConstraint($comfinoStatuses);
        $constraints['COMFINO_INITIAL_ORDER_STATUS'] = new AllowedValuesConstraint($shopStatuses);
        $constraints['COMFINO_WIDGET_OFFER_TYPES'] = new ListConstraint([], self::LOAN_TYPE_PATTERN);
        $constraints['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES'] = new ListConstraint([], self::LOAN_TYPE_PATTERN);
        $constraints['COMFINO_REMOTE_FLAGS'] = new ListConstraint([], self::REMOTE_FLAG_PATTERN);

        return $constraints;
    }

    /**
     * Bounds a connect/transfer timeout pair (seconds) and an attempt count: connect 1..5, transfer 1..15 and longer
     * than connect, attempts 1..3. Non-numeric values fall back to the lower bound.
     *
     * @return array{0: int, 1: int, 2: int} [connect, transfer, attempts]
     */
    public static function clampTimeouts(mixed $connectTimeout, mixed $transferTimeout, mixed $attempts = self::MIN_ATTEMPTS): array
    {
        $connectTimeout = self::clamp($connectTimeout, self::MIN_TIMEOUT, self::MAX_CONNECT_TIMEOUT);
        $transferTimeout = self::clamp($transferTimeout, self::MIN_TIMEOUT, self::MAX_TRANSFER_TIMEOUT);

        if ($transferTimeout <= $connectTimeout) {
            $transferTimeout = $connectTimeout + 1;
        }

        return [$connectTimeout, $transferTimeout, self::clamp($attempts, self::MIN_ATTEMPTS, self::MAX_ATTEMPTS)];
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $value));
    }
}
