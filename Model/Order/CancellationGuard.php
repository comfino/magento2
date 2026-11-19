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

namespace Comfino\ComfinoGateway\Model\Order;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusApplicationContext;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Throwable;

/**
 * Decides whether a shop-side cancellation must not be sent to the ComfinoPay API.
 *
 * Suppressed when the change comes from a ComfinoPay notification (StatusApplicationContext active) or the order already
 * reached a terminal ComfinoPay status (recorded on the payment, or found in the status history for older orders).
 */
class CancellationGuard
{
    /** Payment additional_information key holding the terminal ComfinoPay status applied to the order. */
    public const TERMINAL_STATUS_KEY = 'comfino_terminal_status';

    public const GUARD_CONTEXT = 'context';
    public const GUARD_TERMINAL = 'terminal';
    public const GUARD_HISTORY = 'history';

    /** ComfinoPay statuses after which the application is closed on the ComfinoPay side ({@see OrderStatus} values). */
    public const TERMINAL_COMFINO_STATUSES = ['CANCELLED', 'CANCELLED_BY_SHOP', 'REJECTED', 'RESIGN'];

    /* Custom statuses only a ComfinoPay notification sets, used for the history fallback. comfino_cancelled_by_shop is
       left out: an employee may set it by hand, and that is a cancellation ComfinoPay has not seen yet. */
    private const HISTORY_TERMINAL_STATUSES = ['comfino_rejected', 'comfino_cancelled'];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public static function isTerminalComfinoStatus(string $comfinoStatus): bool
    {
        return in_array(strtoupper($comfinoStatus), self::TERMINAL_COMFINO_STATUSES, true);
    }

    /**
     * Returns why a cancellation triggered by the current save of $order must not be sent, or null to send it.
     *
     * @return string|null One of the GUARD_* constants
     */
    public function getSuppressionReason(Order $order): ?string
    {
        if (StatusApplicationContext::isActive()) {
            return self::GUARD_CONTEXT;
        }

        return $this->getClosedReason($order, true);
    }

    /**
     * Returns why a queued cancellation must not be delivered, or null to deliver it. Runs in a later request (the queue
     * drain), so only the order's recorded state is checked. An order that cannot be loaded is not suppressed.
     *
     * @param string $orderId Entity ID, or increment ID when the shop uses order references
     *
     * @return string|null One of the GUARD_* constants
     */
    public function getQueuedCancelSuppressionReason(string $orderId): ?string
    {
        try {
            if (ConfigManager::isUseOrderReference()) {
                $items = $this->orderRepository->getList(
                    $this->searchCriteriaBuilder->addFilter('increment_id', $orderId)->create()
                )->getItems();
                $order = reset($items);
            } else {
                $order = $this->orderRepository->get((int) $orderId);
            }
        } catch (Throwable) {
            return null;
        }

        return $order instanceof Order ? $this->getClosedReason($order, false) : null;
    }

    /**
     * @param bool $excludeCurrentChange Ignore the history entry the save in progress adds for the new status, so an
     *                                   employee setting a cancellation status is not suppressed by their own change
     */
    private function getClosedReason(Order $order, bool $excludeCurrentChange): ?string
    {
        $payment = $order->getPayment();

        if ($payment instanceof Payment && !empty($payment->getAdditionalInformation(self::TERMINAL_STATUS_KEY))) {
            return self::GUARD_TERMINAL;
        }

        $historyStatuses = [];

        foreach ($order->getStatusHistoryCollection() as $historyItem) {
            $historyStatuses[] = (string) $historyItem->getStatus();
        }

        $currentStatus = (string) $order->getStatus();

        if ($excludeCurrentChange && $currentStatus !== (string) $order->getOrigData('status')) {
            $index = array_search($currentStatus, $historyStatuses, true);

            if ($index !== false) {
                unset($historyStatuses[$index]);
            }
        }

        return array_intersect($historyStatuses, self::HISTORY_TERMINAL_STATUSES) !== [] ? self::GUARD_HISTORY : null;
    }
}
