<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Order;

use Comfino\Enum\OrderStatus;
use Magento\Sales\Model\Order;

/**
 * Magento-specific order status definitions for ComfinoPay: maps API statuses to custom Magento statuses.
 *
 * Each custom status is bound to a standard Magento state in CUSTOM_STATUS_LABELS, so state and status are set together
 * in one step and one history entry records the ComfinoPay status.
 *
 * Intermediate statuses are filtered upstream by StatusNotification via DEFAULT_IGNORED_STATUSES; RESIGN via DEFAULT_FORBIDDEN_STATUSES.
 */
class ShopStatusManager
{
    /**
     * Default mapping between ComfinoPay statuses and Magento custom order status codes.
     *
     * @return array<string, string>
     */
    public static function defaultStatusMap(): array
    {
        return [
            OrderStatus::ACCEPTED->value => 'comfino_accepted',
            OrderStatus::CANCELLED->value => 'comfino_cancelled',
            OrderStatus::REJECTED->value => 'comfino_rejected',
            OrderStatus::CANCELLED_BY_SHOP->value => 'comfino_cancelled_by_shop',
        ];
    }

    /**
     * Full ComfinoPay API status -> custom Magento status code map (including CREATED).
     *
     * @return array<string, string>
     */
    public static function customStatusMap(): array
    {
        return [
            OrderStatus::CREATED->value => 'comfino_created',
            OrderStatus::ACCEPTED->value => 'comfino_accepted',
            OrderStatus::CANCELLED->value => 'comfino_cancelled',
            OrderStatus::REJECTED->value => 'comfino_rejected',
            OrderStatus::CANCELLED_BY_SHOP->value => 'comfino_cancelled_by_shop',
        ];
    }

    /**
     * Custom status code -> display labels and Magento state. Used by Setup\Patch\Data\AddComfinoOrderStatuses and
     * Setup\Uninstall; the state keeps cancellation, invoicing and routing working.
     *
     * @var array<string, array<string, string>>
     */
    public const CUSTOM_STATUS_LABELS = [
        'comfino_created' => [
            'label' => 'Order created - waiting for payment (ComfinoPay)',
            'label_pl' => 'Zamówienie utworzone - oczekiwanie na płatność (ComfinoPay)',
            'state' => Order::STATE_PENDING_PAYMENT,
        ],
        'comfino_accepted' => [
            'label' => 'Credit granted (ComfinoPay)',
            'label_pl' => 'Kredyt udzielony (ComfinoPay)',
            'state' => Order::STATE_PROCESSING,
        ],
        'comfino_rejected' => [
            'label' => 'Credit rejected (ComfinoPay)',
            'label_pl' => 'Wniosek kredytowy odrzucony (ComfinoPay)',
            'state' => Order::STATE_CANCELED,
        ],
        'comfino_cancelled' => [
            'label' => 'Canceled (ComfinoPay)',
            'label_pl' => 'Anulowano (ComfinoPay)',
            'state' => Order::STATE_CANCELED,
        ],
        'comfino_cancelled_by_shop' => [
            'label' => 'Canceled by shop (ComfinoPay)',
            'label_pl' => 'Anulowano przez sklep (ComfinoPay)',
            'state' => Order::STATE_CANCELED,
        ],
    ];
}
