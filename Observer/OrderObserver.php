<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Comfino\ComfinoGateway\Model\Connector\Service\ApplicationService;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\Shop\Order\StatusApplicationContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * Listens for order save events and cancels the Comfino application when a Comfino order is canceled in the shop.
 *
 * Cancellations that originate from a Comfino API status notification are skipped: the SDK marks those saves via
 * StatusApplicationContext (see StatusAdapter::applyStatus()), so echoing a cancel request back to the API would be
 * redundant. Only shop-originated cancellations (admin panel, programmatic) reach the triggers below.
 *
 * Two triggers are handled:
 *   1. State-based: the order state transitions to STATE_CANCELED from any other state. This covers all custom Comfino
 *      cancellation statuses, because each of them is assigned to STATE_CANCELED in the database.
 *
 *   2. Status-based fallback: a Comfino cancellation status is set directly (e.g., via an admin panel) on an order
 *      whose state was not previously STATE_CANCELED. This guard is needed for the rare case where admin manually
 *      selects a cancellation status without the state transition being detected first.
 *
 * @see ApplicationService::cancelApplicationTransaction()
 * @see StatusApplicationContext
 */
class OrderObserver implements ObserverInterface
{
    private ApplicationService $applicationService;

    public function __construct(ApplicationService $applicationService)
    {
        $this->applicationService = $applicationService;
    }

    public function execute(Observer $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof AbstractModel) {
            /** @var Payment|null $payment */
            if (($payment = $order->getPayment()) === null) {
                return;
            }

            if ($payment->getMethod() === 'comfino') {
                if (StatusApplicationContext::isActive()) {
                    /*
                     * The status change is being applied by the SDK in response to a Comfino API notification, so both
                     * triggers below would only echo the cancellation back to the API. Skip them.
                     */
                    return;
                }

                /* Skip the cancel API call if this order was never successfully submitted to Comfino. Orphaned orders
                   cleaned up by restoreCartAfterFailure() go pending_payment → canceled without ever passing through a
                   comfino_* status; sending a cancel for them produces a 404 on the Comfino side. Two signals together
                   cover new and pre-existing orders:
                    - comfino_order_created flag: set by setComfinoCreatedStatus() on new orders.
                    - previous status prefix: backward-compatible fallback for orders created before the flag. */
                $hadComfinoStatus = strncmp((string) $order->getOrigData('status'), 'comfino_', 8) === 0;

                if (!$hadComfinoStatus && !$payment->getAdditionalInformation('comfino_order_created')) {
                    return;
                }

                $currentState = $order->getState();
                $previousState = $order->getOrigData('state');

                // Trigger 1: order state changed to "canceled".
                if ($currentState === Order::STATE_CANCELED && $previousState !== Order::STATE_CANCELED) {
                    $this->applicationService->cancelApplicationTransaction(
                        ConfigManager::isUseOrderReference()
                            ? (!empty($order->getIncrementId()) ? $order->getIncrementId() : (string) $order->getId())
                            : (string) $order->getId()
                    );

                    return;
                }

                // Trigger 2: a Comfino cancellation status was applied via admin without a state transition.
                $currentStatus = $order->getStatus();
                $previousStatus = $order->getOrigData('status');
                $cancelledStatuses = $this->cancelledStatuses();

                if ($previousState !== Order::STATE_CANCELED &&
                    in_array($currentStatus, $cancelledStatuses, true) &&
                    !in_array($previousStatus, $cancelledStatuses, true)
                ) {
                    $this->applicationService->cancelApplicationTransaction(
                        ConfigManager::isUseOrderReference()
                            ? (!empty($order->getIncrementId()) ? $order->getIncrementId() : (string) $order->getId())
                            : (string) $order->getId()
                    );
                }
            }
        }
    }

    /**
     * Returns Comfino status codes that represent a cancellation — derived from ShopStatusManager::CUSTOM_STATUS_LABELS
     * (statuses assigned to STATE_CANCELED). Single source of truth, no duplication.
     *
     * @return string[]
     */
    private function cancelledStatuses(): array
    {
        return array_keys(array_filter(
            ShopStatusManager::CUSTOM_STATUS_LABELS,
            static fn (array $label): bool => $label['state'] === Order::STATE_CANCELED
        ));
    }
}
