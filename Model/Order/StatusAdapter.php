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

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Ui\ConfigProvider;
use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\AbstractStatusAdapter;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;

/**
 * Magento hooks for the SDK AbstractStatusAdapter: which orders a notification may change, when it is a redelivery, and
 * how the status is written.
 *
 * @see AbstractStatusAdapter
 */
class StatusAdapter extends AbstractStatusAdapter
{
    /** @var array<string, Order|null> Orders loaded during this notification, keyed by the notified order ID */
    private array $orders = [];

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
        parent::__construct(
            ignoredStatuses: ConfigManager::getIgnoredStatuses(),
            forbiddenStatuses: ConfigManager::getForbiddenStatuses(),
            statusMap: ConfigManager::getStatusMap(),
        );
    }

    /**
     * Accepts a notification only for an order paid with ComfinoPay for which this module opened an application. Any
     * other order, or one that does not exist, is skipped (and acknowledged).
     *
     * @throws RuntimeException When the order lookup returns something that is not an Order
     */
    protected function acceptsOrder(string $orderId): bool
    {
        $order = $this->loadOrder($orderId);
        $payment = $order?->getPayment();
        $accepted = $payment instanceof Payment && $payment->getMethod() === ConfigProvider::CODE &&
            (bool) $payment->getAdditionalInformation('comfino_order_created');

        if (!$accepted) {
            DebugLogger::logEvent(
                'StatusAdapter: Notification skipped, the order has no ComfinoPay application.',
                ['orderId' => $orderId, 'orderFound' => $order !== null],
                'ORDER_STATUS_UPDATE'
            );
        }

        return $accepted;
    }

    /**
     * A redelivered notification for a status the order already has changes nothing and adds no history entry.
     *
     * @throws RuntimeException
     */
    protected function isStatusAlreadyApplied(string $orderId, string $platformStatusCode): bool
    {
        $alreadyApplied = $this->loadOrder($orderId)?->getStatus() === $platformStatusCode;

        if ($alreadyApplied) {
            DebugLogger::logEvent(
                'StatusAdapter: Notification skipped, status already applied.',
                ['orderId' => $orderId, 'status' => $platformStatusCode],
                'ORDER_STATUS_UPDATE'
            );
        }

        return $alreadyApplied;
    }

    /**
     * Applies the mapped Magento status to the order.
     *
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws RuntimeException
     */
    protected function applyStatus(
        string $orderId,
        string $platformStatusCode,
        string $comfinoStatus,
    ): void {
        // Resolve the Magento state that the custom status is assigned to.
        $customState = ShopStatusManager::CUSTOM_STATUS_LABELS[$platformStatusCode]['state'] ??
            Order::STATE_PENDING_PAYMENT;

        DebugLogger::logEvent(
            sprintf(
                'StatusAdapter::applyStatus (order ID: %s, comfino: "%s", custom status: "%s", state: "%s")',
                $orderId,
                $comfinoStatus,
                $platformStatusCode,
                $customState
            ),
            null,
            'ORDER_STATUS_UPDATE'
        );

        $order = $this->loadOrder($orderId);

        if ($order === null) {
            return;
        }

        $order->setState($customState)->setStatus($platformStatusCode);
        $order->addStatusToHistory($platformStatusCode, (string) __('ComfinoPay payment status: %1', $comfinoStatus));

        if (CancellationGuard::isTerminalComfinoStatus($comfinoStatus) && ($payment = $order->getPayment()) instanceof Payment) {
            // Recorded so a later shop-side cancellation (admin, another request) is not sent for an application ComfinoPay already closed.
            $payment->setAdditionalInformation(CancellationGuard::TERMINAL_STATUS_KEY, strtoupper($comfinoStatus));
        }

        if ($comfinoStatus === OrderStatus::ACCEPTED->value && $order->getIsVirtual() && $order->canInvoice()) {
            try {
                /** @var Invoice $invoice */
                $invoice = $order->prepareInvoice();
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE); // @phpstan-ignore method.notFound
                $invoice->register();

                $order->addRelatedObject($invoice);
            } catch (LocalizedException $e) {
                DebugLogger::logEvent(
                    "StatusAdapter::applyStatus: Invoice creation failed for order $orderId.",
                    ['exceptionMessage' => $e->getMessage()],
                    'ORDER_STATUS_UPDATE'
                );
            }
        }

        /* AbstractStatusAdapter::setStatus() wraps this in StatusApplicationContext, so OrderObserver suppresses the
           reflexive cancel request. */
        $this->orderRepository->save($order);

        DebugLogger::logEvent("StatusAdapter::applyStatus: Order $orderId status updated successfully.", null, 'ORDER_STATUS_UPDATE');
    }

    /**
     * Loads the order by entity ID, or by increment ID when the order reference option is on; shared by the ownership,
     * idempotency and status-write steps.
     *
     * @throws RuntimeException When the lookup returns something that is not an Order
     */
    private function loadOrder(string $orderId): ?Order
    {
        if (array_key_exists($orderId, $this->orders)) {
            return $this->orders[$orderId];
        }

        if (ConfigManager::isUseOrderReference()) {
            $items = $this->orderRepository->getList($this->searchCriteriaBuilder->addFilter('increment_id', $orderId)->create())->getItems();
            $order = $items !== [] ? reset($items) : null;
        } else {
            try {
                $order = $this->orderRepository->get((int) $orderId);
            } catch (NoSuchEntityException|InputException) {
                $order = null;
            }
        }

        if ($order === null) {
            DebugLogger::logEvent("StatusAdapter: Order \"$orderId\" not found.", null, 'ORDER_STATUS_UPDATE');
        } elseif (!$order instanceof Order) {
            throw new RuntimeException(__('Order with ID "%1" is not a valid Order instance.', $orderId));
        }

        return $this->orders[$orderId] = $order;
    }
}
