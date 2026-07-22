<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Connector\Service
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Model\Connector\Service;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Response\CreateOrder;
use Comfino\Backend\Factory\OrderFactory;
use Comfino\Shop\Order\OrderValidator;
use Comfino\ComfinoGateway\Api\ApplicationServiceInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\Enum\LoanType;
use Comfino\Enum\ProductListType;
use InvalidArgumentException;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;
use Throwable;

/**
 * Magento-specific implementation of ApplicationService.
 */
class ApplicationService implements ApplicationServiceInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly OrderRepository $orderRepository,
        private readonly UrlInterface $urlBuilder,
        private readonly RemoteAddress $remoteAddress,
        private readonly CustomerSession $customerSession,
        private readonly OrderManager $orderManager
    ) {
    }

    /**
     * Creates an application in the Comfino API and returns the redirect URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function save(): array
    {
        try {
            $response = $this->createApplicationTransaction();
        } catch (InvalidArgumentException $e) {
            /* Local or API validation failure - restore cart so the customer can correct the data and retry.
               Not reported to the Comfino error tracker (expected validation outcome, not a fault). */
            $this->restoreCartAfterFailure($e->getMessage());

            /* Return the error WITHOUT a redirectUrl: both checkouts (Luma's afterPlaceOrder and Hyvä's
               PlaceOrderService) then keep the customer on the payment step and show the message inline, so they can fix
               the data, retry Comfino, or pick another payment method instead of landing on the generic failure page. */
            return [['error' => $e->getMessage()]];
        } catch (RequestValidationError $e) {
            /* HTTP 400 from createOrder() - the API rejected the request payload.
               Treat the same as local validation: restore cart, show the real API error inline, skip the error tracker. */
            $this->restoreCartAfterFailure($e->getMessage());

            return [['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            /* Communication or unexpected error. A Comfino application only becomes a real transaction once the customer
               is redirected to the Comfino service to finish payment; an application opened here but never followed by
               that redirect is treated as an orphan and safely ignored API-side. So handle this like every other failure:
               cancel the orphaned order and restore the cart, and surface the error inline (no redirectUrl) so the
               customer can retry or pick another payment method. The real exception message is recorded in the order's
               status history (for the shop operator), while the customer sees a generic communication-error message. */
            $reason = $e->getMessage() !== ''
                ? $e->getMessage()
                : (string) __('Communication error with Comfino API.');

            $this->restoreCartAfterFailure($reason);

            ApiClient::processApiError(
                OperationContext::OrderCreation,
                ErrorCategory::ApiError,
                ErrorSeverity::Critical,
                $e
            );

            return [['error' => (string) __('Unsuccessful attempt to open the application. Communication error with Comfino API.')]];
        }

        DebugLogger::logEvent('Redirect URL: ' . $response->applicationUrl, null, 'APPLICATION_SERVICE');

        return [['redirectUrl' => $response->applicationUrl]];
    }

    /**
     * Sends a cancellation request to the Comfino API, or enqueues it for retry when the queue is enabled.
     */
    public function cancelApplicationTransaction(string $orderId): void
    {
        DebugLogger::logEvent(
            "cancelApplicationTransaction: Cancelling order $orderId.",
            null,
            'APPLICATION_SERVICE'
        );

        if (ConfigManager::isRetryQueueEnabled()) {
            // Enqueue cancel notification for reliable delivery with automatic retries.
            $result = Bootstrap::getOutboundQueue()->submit('cancel_order', ['orderId' => $orderId]);

            DebugLogger::logEvent(
                sprintf(
                    'cancelApplicationTransaction: Order %s cancel submit result: %s.',
                    $orderId,
                    $result->value
                ),
                null,
                'APPLICATION_SERVICE'
            );

            return;
        }

        try {
            // Send notification about canceled order paid by Comfino.
            ApiClient::getInstance()->cancelOrder($orderId);

            DebugLogger::logEvent(
                "cancelApplicationTransaction: Order $orderId cancelled successfully.",
                null,
                'APPLICATION_SERVICE'
            );
        } catch (Throwable $e) {
            ApiClient::processApiError(
                OperationContext::OrderCancellation,
                ErrorCategory::ApiError,
                ErrorSeverity::Error,
                $e
            );
        }
    }

    /**
     * Returns widget key received from Comfino API.
     */
    public function getWidgetKey(): string
    {
        try {
            return ApiClient::getInstance()->getWidgetKey();
        } catch (Throwable $e) {
            ApiClient::processApiError(
                OperationContext::WidgetRendering,
                ErrorCategory::ApiError,
                ErrorSeverity::Error,
                $e
            );

            return '';
        }
    }

    /**
     * Returns the list of available product types for Comfino widget.
     *
     * @return array<string, mixed>|null
     */
    public function getProductTypes(): ?array
    {
        try {
            $response = ApiClient::getInstance()->getProductTypes(
                ProductListType::WIDGET
            );

            return $response->productTypesWithNames;
        } catch (Throwable $e) {
            ApiClient::processApiError(
                OperationContext::WidgetRendering,
                ErrorCategory::ApiError,
                ErrorSeverity::Error,
                $e
            );

            return null;
        }
    }

    /**
     * Returns true if the shop account is active in Comfino API.
     */
    public function isShopAccountActive(): bool
    {
        if (empty(ConfigManager::getApiKey())) {
            return false;
        }

        try {
            return ApiClient::getInstance()->isShopAccountActive();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Validates and creates a Comfino order via the shared library API client.
     *
     * Performs local data validation, then Comfino API validation (simulation), then creates the order.
     * The Magento order entity_id is used as the external order identifier passed to Comfino.
     *
     * @return CreateOrder
     *
     * @throws InvalidArgumentException On local or API validation failure.
     * @throws Throwable On API communication error.
     */
    private function createApplicationTransaction(): CreateOrder
    {
        $magentoOrder = $this->session->getLastRealOrder();
        $orderDto = $this->buildOrderDto($magentoOrder);

        // Step 1: Local pre-validation
        $errors = $this->validatePaymentData($orderDto);

        if (!empty($errors)) {
            DebugLogger::logEvent('Local validation failed.', ['errors' => $errors], 'PAYMENT');

            throw new InvalidArgumentException(implode(' ', $errors));
        }

        // Reuse the trackId minted during this checkout session's paywall render, if any.
        ApiClient::pinCheckoutTrackId();

        // Step 2: API-side validation (simulation=true, no order created yet)
        $validationResult = ApiClient::getInstance()->validateOrder($orderDto);

        if (!$validationResult->success) {
            $apiErrors = array_values((array) $validationResult->errors);

            DebugLogger::logEvent('API validation failed.', ['errors' => $apiErrors], 'PAYMENT');

            throw new InvalidArgumentException(implode(' ', $apiErrors));
        }

        // Step 3: Create order
        $response = ApiClient::getInstance()->createOrder($orderDto);

        // Step 4: Mark order with the configured initial Comfino status
        $this->setComfinoCreatedStatus($magentoOrder);

        return $response;
    }

    /**
     * Builds the shared-lib Order DTO from the given Magento order.
     *
     * When COMFINO_USE_ORDER_REFERENCE is enabled, the increment_id (customer-visible order number, e.g. "100000001")
     * is used as the external order identifier passed to Comfino instead of the internal entity_id.
     *
     * @param Order $magentoOrder
     *
     * @return \Comfino\Shop\Order\Order
     */
    private function buildOrderDto(Order $magentoOrder): \Comfino\Shop\Order\Order
    {
        $totalAmount = (int) round($magentoOrder->getGrandTotal() * 100);

        /** @var Payment $paymentInfo */
        $paymentInfo = $magentoOrder->getPayment();
        $loanTerm = (int) $paymentInfo->getAdditionalInformation('loanTerm');
        $loanTypeEnum = LoanType::tryFrom((string) $paymentInfo->getAdditionalInformation('loanType'));

        if ($loanTypeEnum === null) {
            throw new InvalidArgumentException(
                (string) __('Please select a financial product type in the payment form before placing the order.')
            );
        }

        $orderCart = $this->orderManager->getShopCartFromOrder($magentoOrder);

        if (ConfigManager::isUseOrderReference()) {
            $externalId = !empty($magentoOrder->getIncrementId())
                ? $magentoOrder->getIncrementId()
                : (string) $magentoOrder->getId();
        } else {
            $externalId = (string) $magentoOrder->getId();
        }

        return (new OrderFactory())->createOrder(
            orderId: $externalId,
            orderTotal: $totalAmount,
            deliveryCost: $orderCart->getDeliveryCost(),
            loanTerm: $loanTerm,
            loanType: $loanTypeEnum,
            cartItems: $orderCart->getCartItems(),
            customer: $this->orderManager->getShopCustomerFromOrder(
                $magentoOrder,
                (string) $this->remoteAddress->getRemoteAddress(),
                $this->customerSession->isLoggedIn()
            ),
            returnUrl: rtrim($this->urlBuilder->getUrl('checkout/onepage/success'), '/'),
            notificationUrl: rtrim($this->urlBuilder->getUrl('comfino/transactionstatus'), '/'),
            allowedProductTypes: SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $orderCart),
            deliveryNetCost: $orderCart->getDeliveryNetCost(),
            deliveryCostTaxRate: $orderCart->getDeliveryTaxRate(),
            deliveryCostTaxValue: $orderCart->getDeliveryTaxValue(),
            allowedProductsConfig: SettingsManager::getAllowedProductsConfig()
        );
    }

    /**
     * Validates payment data from the Order DTO before submission to Comfino API.
     *
     * The generic checks live in the SDK OrderValidator (shared across all Comfino plugins); this method only maps the
     * stable failure keys it returns to translated, customer-facing messages.
     *
     * @param \Comfino\Shop\Order\Order $orderDto
     *
     * @return string[] Array of error messages; empty if validation passes.
     */
    private function validatePaymentData(\Comfino\Shop\Order\Order $orderDto): array
    {
        $messages = [
            OrderValidator::CUSTOMER_EMAIL_INVALID =>
                __('Invalid customer e-mail address. Please check your account contact data.'),
            OrderValidator::CUSTOMER_PHONE_REQUIRED =>
                __('Phone number is required. Please add a phone number to your billing or delivery address.'),
            OrderValidator::CUSTOMER_FIRST_NAME_REQUIRED => __('First name is required.'),
            OrderValidator::CUSTOMER_LAST_NAME_REQUIRED => __('Last name is required.'),
            OrderValidator::ADDRESS_REQUIRED => __('Delivery address is required.'),
            OrderValidator::ADDRESS_CITY_REQUIRED => __('City is required.'),
            OrderValidator::ADDRESS_POSTAL_CODE_REQUIRED => __('Postal code is required.'),
            OrderValidator::CART_EMPTY => __('Cart is empty. Please add products to your cart.'),
            OrderValidator::CART_TOTAL_AMOUNT_NON_POSITIVE => __('Cart total amount must be greater than zero.'),
        ];

        return array_map(
            static fn (string $key): string => (string) ($messages[$key] ?? $key),
            (new OrderValidator())->validate($orderDto)
        );
    }

    /**
     * Marks the order with the configured initial Comfino order status after successful API submission.
     * Uses COMFINO_INITIAL_ORDER_STATUS config value; defaults to comfino_created.
     *
     * @param Order $order
     */
    private function setComfinoCreatedStatus(Order $order): void
    {
        try {
            $initialStatus = ConfigManager::getInitialOrderStatus();
            $initialState = ShopStatusManager::CUSTOM_STATUS_LABELS[$initialStatus]['state']
                ?? Order::STATE_PENDING_PAYMENT;

            /* Flag persisted with the order so the cancel observer can distinguish orders that were actually submitted
               to Comfino from orphaned orders canceled by restoreCartAfterFailure(). */
            $order->getPayment()->setAdditionalInformation('comfino_order_created', true);
            $order->setState($initialState)->setStatus($initialStatus);
            $order->addStatusToHistory(
                $initialStatus,
                __('Order submitted to Comfino - waiting for payment.')
            );

            $this->orderRepository->save($order);
        } catch (Throwable $e) {
            ErrorLogger::sendError(
                $e,
                ErrorCategory::Other,
                ErrorSeverity::Error,
                OperationContext::OrderCreation,
                (string) $e->getCode(),
                $e->getMessage()
            );
        }
    }

    /**
     * Cancels the orphaned Magento order and restores the source quote so the customer can retry.
     *
     * @param string $reason Human-readable failure reason recorded in the order status history.
     */
    private function restoreCartAfterFailure(string $reason): void
    {
        try {
            $order = $this->session->getLastRealOrder();

            if ($order->getId() && $order->canCancel()) {
                $order->cancel();
                $order->addStatusToHistory(
                    $order->getStatus(),
                    (string) __('Order automatically canceled — Comfino application could not be created. Reason: %1', $reason)
                );
                $this->orderRepository->save($order);
            }
        } catch (Throwable $e) {
            ErrorLogger::sendError(
                $e,
                ErrorCategory::Other,
                ErrorSeverity::Error,
                OperationContext::OrderCancellation,
                (string) $e->getCode(),
                $e->getMessage()
            );
        }

        try {
            $this->session->restoreQuote();
        } catch (Throwable $e) {
            ErrorLogger::sendError(
                $e,
                ErrorCategory::Other,
                ErrorSeverity::Error,
                OperationContext::OrderCancellation,
                (string) $e->getCode(),
                $e->getMessage()
            );
        }
    }
}
