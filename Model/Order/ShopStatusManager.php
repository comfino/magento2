<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Order;

use Comfino\Enum\OrderStatus;
use Magento\Sales\Model\Order;

/**
 * Magento-specific order status definitions for Comfino.
 *
 * Defines the mapping between Comfino API statuses and Magento custom order statuses, using Magento's native
 * state/status two-level system.
 *
 * Each custom Comfino status is assigned to a standard Magento state in CUSTOM_STATUS_LABELS. This means the custom
 * status IS the final, visible status on the order - the merchant sees e.g. "Credit granted (Comfino)" in the order
 * grid, not just the generic "Processing" label.
 *
 * Status flow (single-step, idiomatic Magento):
 *   - Set order state (from CUSTOM_STATUS_LABELS['state']) and custom status code simultaneously.
 *   - Add one history entry with the Comfino status string as a comment for the audit trail.
 *   - No second state transition is needed - state is already encoded in CUSTOM_STATUS_LABELS.
 *
 * Intermediate statuses (WAITING_FOR_FILLING, WAITING_FOR_CONFIRMATION, WAITING_FOR_PAYMENT, PAID) are filtered
 * upstream by StatusNotification via DEFAULT_IGNORED_STATUSES. RESIGN is filtered via DEFAULT_FORBIDDEN_STATUSES.
 */
class ShopStatusManager
{
    /**
     * Default mapping between Comfino statuses and Magento custom order status codes.
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
     * Full Comfino API status -> custom Magento status code map (including CREATED).
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
     * Custom Magento status code -> display labels and Magento state assignment.
     *
     * Used by Setup\Patch\Data\AddComfinoOrderStatuses to register custom statuses in the database, and by
     * Setup\Uninstall to remove them on module uninstallation.
     *
     * The 'state' value assigns each custom status to the correct Magento order workflow state, so Magento routes and
     * permissions work correctly (e.g., cancellation, invoicing).
     *
     * @var array<string, array<string, string>>
     */
    public const CUSTOM_STATUS_LABELS = [
        'comfino_created' => [
            'label' => 'Order created - waiting for payment (Comfino)',
            'label_pl' => 'Zamówienie utworzone - oczekiwanie na płatność (Comfino)',
            'state' => Order::STATE_PENDING_PAYMENT,
        ],
        'comfino_accepted' => [
            'label' => 'Credit granted (Comfino)',
            'label_pl' => 'Kredyt udzielony (Comfino)',
            'state' => Order::STATE_PROCESSING,
        ],
        'comfino_rejected' => [
            'label' => 'Credit rejected (Comfino)',
            'label_pl' => 'Wniosek kredytowy odrzucony (Comfino)',
            'state' => Order::STATE_CANCELED,
        ],
        'comfino_cancelled' => [
            'label' => 'Canceled (Comfino)',
            'label_pl' => 'Anulowano (Comfino)',
            'state' => Order::STATE_CANCELED,
        ],
        'comfino_cancelled_by_shop' => [
            'label' => 'Canceled by shop (Comfino)',
            'label_pl' => 'Anulowano przez sklep (Comfino)',
            'state' => Order::STATE_CANCELED,
        ],
    ];
}
