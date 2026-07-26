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

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
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
use Magento\Sales\Model\OrderRepository;

/**
 * Magento-specific Comfino order status adapter.
 *
 * Extends the shared AbstractStatusAdapter (php-sdk) which handles ignore/forbidden/map routing.
 * This class only implements the Magento-specific applyStatus() hook.
 *
 * @see AbstractStatusAdapter
 */
class StatusAdapter extends AbstractStatusAdapter
{
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

        if (ConfigManager::isUseOrderReference()) {
            $items = $this->orderRepository->getList(
                $this->searchCriteriaBuilder->addFilter('increment_id', $orderId)->create()
            )->getItems();

            if (empty($items)) {
                DebugLogger::logEvent(
                    "StatusAdapter::applyStatus: Order with increment_id \"$orderId\" not found - skipping.",
                    null,
                    'ORDER_STATUS_UPDATE'
                );

                return;
            }

            $order = reset($items);
        } else {
            $order = $this->orderRepository->get((int) $orderId);
        }

        if (!$order instanceof Order) {
            throw new RuntimeException(__('Order with ID "%1" is not a valid Order instance.', $orderId));
        }

        $order->setState($customState)->setStatus($platformStatusCode);
        $order->addStatusToHistory($platformStatusCode, (string) __('Comfino payment status: %1', $comfinoStatus));

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

        /*
         * The SDK's AbstractStatusAdapter::setStatus() wraps this call in StatusApplicationContext, so the save event
         * below is recognized as API-initiated and OrderObserver suppresses the reflexive cancel request.
         */
        $this->orderRepository->save($order);

        DebugLogger::logEvent(
            "StatusAdapter::applyStatus: Order $orderId status updated successfully.",
            null,
            'ORDER_STATUS_UPDATE'
        );
    }
}
