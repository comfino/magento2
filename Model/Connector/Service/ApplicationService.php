<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Connector\Service
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Model\Connector\Service;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Response\CreateOrder;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Backend\Factory\OrderFactory;
use Comfino\Shop\Order\OrderValidator;
use Comfino\ComfinoGateway\Api\ApplicationServiceInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\ComfinoGateway\Model\Queue\TenantKey;
use Comfino\ComfinoGateway\Model\Ui\ConfigProvider;
use Comfino\ComfinoGateway\Observer\DataAssignObserver;
use Comfino\Api\Validation\UrlValidator;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\PromoCode;
use InvalidArgumentException;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;
use Throwable;
use UnexpectedValueException;

/**
 * Magento-specific implementation of ApplicationService.
 */
class ApplicationService implements ApplicationServiceInterface
{
    /* Order states from which an application may still be opened. STATE_PENDING_PAYMENT lets a retry after a failed
       submission that never reached setComfinoCreatedStatus() still qualify; later states are past the payment stage. */
    private const SUBMITTABLE_ORDER_STATES = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT];

    public function __construct(
        private readonly Session $session,
        private readonly OrderRepository $orderRepository,
        private readonly UrlInterface $urlBuilder,
        private readonly RemoteAddress $remoteAddress,
        private readonly CustomerSession $customerSession,
        private readonly OrderManager $orderManager,
        private readonly LoanSelection $loanSelection
    ) {
    }

    /**
     * Creates an application in the ComfinoPay API and returns the redirect URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function save(): array
    {
        $magentoOrder = $this->session->getLastRealOrder();

        /* The endpoint is anonymous and acts on the session's last order, so it is re-qualified on every call. Ineligible
           orders must not reach the API nor be canceled by restoreCartAfterFailure(), hence the early return. */
        if (($rejectionReason = $this->checkOrderEligibility($magentoOrder)) !== null) {
            DebugLogger::logEvent(
                'save: Order rejected before submission.',
                ['orderId' => (string) $magentoOrder->getId(), 'reason' => $rejectionReason],
                'APPLICATION_SERVICE'
            );

            return [['error' => (string) __('This order can not be submitted to ComfinoPay.')]];
        }

        try {
            $response = $this->createApplicationTransaction($magentoOrder);
        } catch (InvalidArgumentException $e) {
            /* Local or API validation failure - restore cart so the customer can correct the data and retry. Not reported
               to the ComfinoPay error tracker (expected validation outcome, not a fault). */
            $this->restoreCartAfterFailure($e->getMessage());

            /* Return the error WITHOUT a redirectUrl: both checkouts (Luma's afterPlaceOrder and Hyvä's PlaceOrderService)
               then keep the customer on the payment step and show the message inline, so they can fix the data, retry
               ComfinoPay, or pick another payment method instead of landing on the generic failure page. */
            return [['error' => $e->getMessage()]];
        } catch (RequestValidationError $e) {
            /* HTTP 400 from createOrder() - the API rejected the request payload. Treated like local validation: the
               cart is restored and the error tracker skipped. The API text goes to the order history and the debug log;
               the shopper gets a translated message for the fields it names. */
            $this->restoreCartAfterFailure($e->getMessage());

            DebugLogger::logEvent('API rejected the order data.', ['fields' => array_keys($e->getFieldErrors())], 'PAYMENT');

            return [['error' => CheckoutErrorMessage::forFieldErrors($e->getFieldErrors())]];
        } catch (Throwable $e) {
            /* An application opened here but never followed by the customer redirect is ignored API-side as an orphan.
               So, as with every other failure, cancel the order, restore the cart and show the error inline (no redirectUrl).
               The real message goes to the order history; the customer sees a generic one. */
            $reason = $e->getMessage() !== '' ? $e->getMessage() : (string) __('Communication error with ComfinoPay API.');

            $this->restoreCartAfterFailure($reason);

            /* Reported as Critical unless the failure is transient (timeout, network error, HTTP 5xx or 429), which
               processApiError() reports as a Warning. */
            ApiClient::processApiError(OperationContext::OrderCreation, ErrorCategory::ApiError, ErrorSeverity::Critical, $e);

            if ((new ErrorClassifier())->isTransient($e)) {
                return [['error' => (string) __('ComfinoPay is temporarily unavailable. Please try again in a moment or choose another payment method.')]];
            }

            return [['error' => (string) __('Unsuccessful attempt to open the application. Communication error with ComfinoPay API.')]];
        }

        DebugLogger::logEvent('Redirect URL: ' . $response->applicationUrl, null, 'APPLICATION_SERVICE');

        return [['redirectUrl' => $response->applicationUrl]];
    }

    /**
     * Sends a cancellation request to the ComfinoPay API, or enqueues it for retry when the queue is enabled.
     */
    /**
     * @param int|null $storeId Store the order belongs to. Recorded in the queue payload so a deferred resend uses that
     *                          store's API credentials, not the ambient ones (see StoreAwareCancelOrderHandler).
     */
    public function cancelApplicationTransaction(string $orderId, ?int $storeId = null): void
    {
        DebugLogger::logEvent("cancelApplicationTransaction: Cancelling order $orderId.", null, 'APPLICATION_SERVICE');

        if (ConfigManager::isRetryQueueEnabled()) {
            // Enqueue cancel notification for reliable delivery with automatic retries.
            $payload = ['orderId' => $orderId];

            if ($storeId !== null) {
                $payload['storeId'] = $storeId;
            }

            /* The tenant key makes the drain deliver it with this store's credentials. The payload keeps storeId too,
               so a module downgraded to 4.0.x can still deliver the row. */
            $result = Bootstrap::getOutboundQueue()->submit(
                'cancel_order',
                $payload,
                $storeId !== null ? TenantKey::forStore($storeId) : null
            );

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
            // Send notification about canceled order paid by ComfinoPay.
            ApiClient::getInstance()->cancelOrder($orderId);

            DebugLogger::logEvent("cancelApplicationTransaction: Order $orderId cancelled successfully.", null, 'APPLICATION_SERVICE');
        } catch (Throwable $e) {
            ApiClient::processApiError(OperationContext::OrderCancellation, ErrorCategory::ApiError, ErrorSeverity::Error, $e);
        }
    }

    /**
     * Returns widget key received from ComfinoPay API.
     */
    public function getWidgetKey(): string
    {
        try {
            return ApiClient::getInstance()->getWidgetKey();
        } catch (Throwable $e) {
            ApiClient::processApiError(OperationContext::WidgetRendering, ErrorCategory::ApiError, ErrorSeverity::Error, $e);

            return '';
        }
    }

    /**
     * Returns the list of available product types for ComfinoPay widget.
     *
     * @return array<string, mixed>|null
     */
    public function getProductTypes(): ?array
    {
        try {
            return ApiClient::getInstance()->getProductTypes(ProductListType::WIDGET)->productTypesWithNames;
        } catch (Throwable $e) {
            ApiClient::processApiError(OperationContext::WidgetRendering, ErrorCategory::ApiError, ErrorSeverity::Error, $e);

            return null;
        }
    }

    /**
     * Returns true if the shop account is active in ComfinoPay API.
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
     * Checks whether the given order may be submitted to ComfinoPay.
     *
     * @param Order $order The session's last real order
     *
     * @return string|null Operator-facing rejection reason, or null when the order qualifies
     */
    private function checkOrderEligibility(Order $order): ?string
    {
        if (!$order->getId()) {
            return 'No order associated with the current session.';
        }

        /** @var Payment|null $payment */
        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return sprintf('Order is not paid with ComfinoPay (payment method: "%s").', $payment !== null ? (string) $payment->getMethod() : 'none');
        }

        /* Idempotency guard: the flag is set by setComfinoCreatedStatus() once the application has been opened, so a
           repeated call cannot generate a second application for the same order. */
        if ($payment->getAdditionalInformation('comfino_order_created')) {
            return 'A ComfinoPay application has already been created for this order.';
        }

        if (!in_array($order->getState(), self::SUBMITTABLE_ORDER_STATES, true)) {
            return sprintf('Order is in a non-submittable state ("%s").', (string) $order->getState());
        }

        return null;
    }

    /**
     * Validates and creates a ComfinoPay order via the shared library API client:
     * local validation, then API validation (simulation), then creation.
     *
     * @param Order $magentoOrder The order qualified by checkOrderEligibility().
     *
     * @return CreateOrder
     *
     * @throws InvalidArgumentException On local or API validation failure.
     * @throws Throwable On API communication error.
     */
    private function createApplicationTransaction(Order $magentoOrder): CreateOrder
    {
        $orderDto = $this->buildOrderDto($magentoOrder);

        // Step 1: Local pre-validation
        $errors = $this->validatePaymentData($orderDto);

        if (!empty($errors)) {
            DebugLogger::logEvent('Local validation failed.', ['errors' => $errors], 'PAYMENT');

            throw new InvalidArgumentException(implode(' ', $errors));
        }

        /* Create the order with the trackId of the paywall events: sent with the payment data by the frontend SDK, else
           the one of this checkout session's paywall render, if any. */
        $payment = $magentoOrder->getPayment();

        $submittedTrackId = $payment instanceof Payment
            ? (string) $payment->getAdditionalInformation(DataAssignObserver::PAYMENT_TRACK_ID)
            : '';

        ApiClient::pinCheckoutTrackId($submittedTrackId);

        // Step 2: API-side validation (simulation=true, no order created yet)
        $validationResult = ApiClient::getInstance()->validateOrder($orderDto);

        if (!$validationResult->success) {
            $apiErrors = (array) $validationResult->errors;

            /* Only the field names are logged: the API text may quote the submitted customer data.
               The shopper (and the order history) get the translated field messages. */
            DebugLogger::logEvent('API validation failed.', ['fields' => array_keys($apiErrors)], 'PAYMENT');

            throw new InvalidArgumentException(CheckoutErrorMessage::forFieldErrors($apiErrors));
        }

        // Step 3: Create order
        $response = ApiClient::getInstance()->createOrder($orderDto);

        /* The URL becomes the shopper's redirect, so it must point at a ComfinoPay domain (local hosts too in a
           development environment). Anything else is handled as a failed application: the cart is restored. */
        if (!self::isTrustedApplicationUrl($response)) {
            throw new UnexpectedValueException(sprintf(
                'Application URL host "%s" is not a ComfinoPay domain.',
                parse_url($response->applicationUrl, PHP_URL_HOST)
            ));
        }

        // The order is created: the next checkout starts a new journey (own trackId).
        ApiClient::releaseCheckoutTrackId();

        // Step 4: Mark order with the configured initial ComfinoPay status
        $this->setComfinoCreatedStatus($magentoOrder);

        return $response;
    }

    private static function isTrustedApplicationUrl(CreateOrder $response): bool
    {
        if ($response->hasTrustedApplicationUrl()) {
            return true;
        }

        return UrlValidator::devEnvActive() && UrlValidator::isAllowedUrl($response->applicationUrl);
    }

    /**
     * Builds the shared-lib Order DTO. With COMFINO_USE_ORDER_REFERENCE enabled, increment_id is the external order
     * identifier instead of entity_id.
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
        $orderCart = $this->orderManager->getShopCartFromOrder($magentoOrder);
        $directRedirect = (bool) ConfigManager::getConfigurationValue('COMFINO_PAYWALL_DIRECT_REDIRECT');

        [$loanTypeEnum, $loanTerm] = $this->loanSelection->resolve(
            $paymentInfo->getAdditionalInformation(DataAssignObserver::PAYMENT_LOAN_TYPE),
            $paymentInfo->getAdditionalInformation(DataAssignObserver::PAYMENT_LOAN_TERM),
            $orderCart,
            $totalAmount,
            $directRedirect
        );

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
            allowedProductsConfig: SettingsManager::getAllowedProductsConfig(),
            promoCode: PromoCode::normalize($paymentInfo->getAdditionalInformation(DataAssignObserver::PAYMENT_PROMO_CODE)),
            directRedirect: $directRedirect
        );
    }

    /**
     * Validates payment data from the Order DTO before submission. Checks live in the SDK
     * OrderValidator; this method maps its failure keys to translated, customer-facing messages.
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

        return array_map(static fn (string $key): string => (string) ($messages[$key] ?? $key), (new OrderValidator())->validate($orderDto));
    }

    /**
     * Sets the order to the configured COMFINO_INITIAL_ORDER_STATUS (default comfino_created) after successful submission.
     *
     * @param Order $order
     */
    private function setComfinoCreatedStatus(Order $order): void
    {
        try {
            $initialStatus = ConfigManager::getInitialOrderStatus();
            $initialState = ShopStatusManager::CUSTOM_STATUS_LABELS[$initialStatus]['state']
                ?? Order::STATE_PENDING_PAYMENT;

            /* Flag persisted with the order so the cancel observer can distinguish orders that were actually
               submitted to ComfinoPay from orphaned orders canceled by restoreCartAfterFailure(). */
            $order->getPayment()->setAdditionalInformation('comfino_order_created', true);
            $order->setState($initialState)->setStatus($initialStatus);
            $order->addStatusToHistory($initialStatus, __('Order submitted to ComfinoPay - waiting for payment.'));

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
                    (string) __('Order automatically canceled — ComfinoPay application could not be created. Reason: %1', $reason)
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
