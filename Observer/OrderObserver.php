<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Connector\Service\ApplicationService;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\CancellationGuard;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * Cancels the ComfinoPay application when a ComfinoPay order is canceled in the shop.
 *
 * Nothing is sent when ComfinoPay already treats the order as closed (see CancellationGuard); each suppression is
 * debug-logged. Triggers: the state changes to STATE_CANCELED, or a ComfinoPay cancellation status is set directly
 * (e.g. in the admin panel) without that state transition.
 *
 * @see ApplicationService::cancelApplicationTransaction()
 * @see StatusApplicationContext
 */
class OrderObserver implements ObserverInterface
{
    private ApplicationService $applicationService;
    private CancellationGuard $cancellationGuard;

    public function __construct(ApplicationService $applicationService, CancellationGuard $cancellationGuard)
    {
        $this->applicationService = $applicationService;
        $this->cancellationGuard = $cancellationGuard;
    }

    public function execute(Observer $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof AbstractModel) {
            return;
        }

        /** @var Payment|null $payment */
        if (($payment = $order->getPayment()) === null || $payment->getMethod() !== 'comfino') {
            return;
        }

        /* Skip the cancel call for orders never submitted to ComfinoPay (orphans cleaned up by restoreCartAfterFailure()
           pass pending_payment -> canceled without a comfino_* status); it would 404. The previous status prefix covers
           orders created before the comfino_order_created flag. */
        $hadComfinoStatus = strncmp((string) $order->getOrigData('status'), 'comfino_', 8) === 0;

        if (!$hadComfinoStatus && !$payment->getAdditionalInformation('comfino_order_created')) {
            return;
        }

        if (!$this->isCancellation($order)) {
            return;
        }

        if (($guard = $this->cancellationGuard->getSuppressionReason($order)) !== null) {
            // ComfinoPay already knows the order is closed - no cancellation needed.
            DebugLogger::logEvent(
                'Order cancellation not sent to ComfinoPay API.',
                ['orderId' => (string) $order->getId(), 'status' => (string) $order->getStatus(), 'guard' => $guard],
                'ORDER_STATUS_UPDATE'
            );

            return;
        }

        $this->applicationService->cancelApplicationTransaction(
            ConfigManager::isUseOrderReference()
                ? (!empty($order->getIncrementId()) ? $order->getIncrementId() : (string) $order->getId())
                : (string) $order->getId(),
            (int) $order->getStoreId()
        );
    }

    /**
     * Tells whether this save cancels the order: a state change to "canceled", or a ComfinoPay cancellation status.
     */
    private function isCancellation(Order $order): bool
    {
        $previousState = $order->getOrigData('state');

        if ($previousState !== Order::STATE_CANCELED && $order->getState() === Order::STATE_CANCELED) {
            return true;
        }

        $cancelledStatuses = $this->cancelledStatuses();

        return $previousState !== Order::STATE_CANCELED &&
            in_array($order->getStatus(), $cancelledStatuses, true) &&
            !in_array($order->getOrigData('status'), $cancelledStatuses, true);
    }

    /**
     * ComfinoPay cancellation status codes, derived from ShopStatusManager::CUSTOM_STATUS_LABELS (STATE_CANCELED ones).
     *
     * @return string[]
     */
    private function cancelledStatuses(): array
    {
        return array_keys(array_filter(ShopStatusManager::CUSTOM_STATUS_LABELS, static fn (array $label): bool => $label['state'] === Order::STATE_CANCELED));
    }
}
