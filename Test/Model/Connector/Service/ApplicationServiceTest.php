<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Connector\Service;

use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Connector\Service\ApplicationService;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\Customer\Address;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class ApplicationServiceTest extends TestCase
{
    use LoggerHarnessTrait;

    private Session&MockObject $session;
    private OrderRepository&MockObject $orderRepository;
    private UrlInterface&MockObject $urlBuilder;
    private RemoteAddress&MockObject $remoteAddress;
    private CustomerSession&MockObject $customerSession;
    private OrderManager&MockObject $orderManager;

    protected function setUp(): void
    {
        parent::setUp();

        // getInstance() will reach the uninitialized SDK bootstrap and throw — that drives the catch branches.
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_USE_ORDER_REFERENCE' => false,
            'COMFINO_INITIAL_ORDER_STATUS' => 'comfino_created',
            'COMFINO_RETRY_QUEUE_ENABLED' => false,
        ]);

        $this->installLoggerHarness();

        $this->session = $this->createMock(Session::class);
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->orderManager = $this->createMock(OrderManager::class);

        $this->urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route): string => 'https://shop.example/' . trim($route, '/')
        );
        $this->remoteAddress->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->customerSession->method('isLoggedIn')->willReturn(true);
    }

    protected function tearDown(): void
    {
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        (new ReflectionProperty(Bootstrap::class, 'outboundQueue'))->setValue(null, null);

        parent::tearDown();
    }

    private function createService(): ApplicationService
    {
        return new ApplicationService(
            $this->session,
            $this->orderRepository,
            $this->urlBuilder,
            $this->remoteAddress,
            $this->customerSession,
            $this->orderManager
        );
    }

    private function createCart(int $totalValue = 12000): Cart
    {
        $product = $this->createMock(ProductInterface::class);

        return new Cart($totalValue, null, null, 500, null, null, null, [new CartItem($product, 1)]);
    }

    private function createValidCustomer(): Customer
    {
        return new Customer(
            'John',
            'Doe',
            'john.doe@example.com',
            '+48123456789',
            '127.0.0.1',
            null,
            null,
            true,
            new Address(null, null, null, '00-001', 'Warsaw', 'PL')
        );
    }

    /**
     * Builds an order that clears checkOrderEligibility(): paid with Comfino, no application opened yet, still in a
     * submittable state. Tests covering the guard itself override the relevant getter on the returned mock.
     */
    private function createMagentoOrder(?string $loanType, ?string $loanTerm = '12', string $incrementId = '000000042'): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['loanTerm', $loanTerm],
            ['loanType', $loanType],
            ['comfino_order_created', null],
        ]);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getGrandTotal')->willReturn(120.0);
        $order->method('getId')->willReturn(42);
        $order->method('getIncrementId')->willReturn($incrementId);
        /* Allow the chained status setters in restoreCartAfterFailure / setComfinoCreatedStatus to run to completion. */
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('addStatusToHistory')->willReturnSelf();

        return $order;
    }

    // --- cancelApplicationTransaction() ---

    public function testCancelApplicationTransactionSwallowsApiErrors(): void
    {
        $this->createService()->cancelApplicationTransaction('100000001');

        $this->addToAssertionCount(1);
    }

    /**
     * The queued payload must carry the order's store, otherwise the drain (which runs in the crontab area, where the
     * ambient config scope is always the default store) resends the cancellation with the wrong API credentials.
     */
    public function testCancelApplicationTransactionRecordsTheStoreIdInTheQueuedPayload(): void
    {
        $storage = $this->installQueueWithFailingHandler();

        $this->createService()->cancelApplicationTransaction('100000042', 7);

        $this->assertCount(1, $storage->requests, 'The failed cancel should have been deferred to the queue.');
        $this->assertSame(
            ['orderId' => '100000042', 'storeId' => 7],
            $storage->requests[0]->payload
        );
    }

    public function testCancelApplicationTransactionOmitsTheStoreIdWhenNotSupplied(): void
    {
        $storage = $this->installQueueWithFailingHandler();

        $this->createService()->cancelApplicationTransaction('100000043');

        $this->assertCount(1, $storage->requests);
        $this->assertSame(['orderId' => '100000043'], $storage->requests[0]->payload);
    }

    /**
     * Installs a real OutboundRequestQueue on Bootstrap whose cancel_order handler always fails transiently, so
     * submit() takes the "defer to storage" branch, and the enqueued payload can be inspected.
     */
    private function installQueueWithFailingHandler(): object
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_USE_ORDER_REFERENCE' => false,
            'COMFINO_INITIAL_ORDER_STATUS' => 'comfino_created',
            'COMFINO_RETRY_QUEUE_ENABLED' => true,
        ]);

        $storage = new class implements RetryQueueStorageInterface {
            /** @var QueuedRequest[] */
            public array $requests = [];

            public function enqueue(QueuedRequest $request): void
            {
                $this->requests[] = $request;
            }

            public function peekBatch(int $limit): array
            {
                return array_slice($this->requests, 0, $limit);
            }

            public function update(QueuedRequest $request): void
            {
                // Not exercised by these tests.
            }

            public function remove(QueuedRequest $request): void
            {
                // Not exercised by these tests.
            }

            public function count(): int
            {
                return count($this->requests);
            }
        };

        $queue = new OutboundRequestQueue($storage, new ApiTransientErrorClassifier());

        // A plain RuntimeException classifies as Retry, which is what drives submit() into the enqueue branch.
        $queue->registerHandler('cancel_order', new class implements RetryableOperationHandlerInterface {
            public function execute(array $payload): void
            {
                throw new RuntimeException('Comfino API unreachable.');
            }
        });

        (new ReflectionProperty(Bootstrap::class, 'outboundQueue'))->setValue(null, $queue);

        return $storage;
    }

    // --- getWidgetKey() ---

    public function testGetWidgetKeyReturnsEmptyStringOnApiError(): void
    {
        $this->assertSame('', $this->createService()->getWidgetKey());
    }

    // --- getProductTypes() ---

    public function testGetProductTypesReturnsNullOnApiError(): void
    {
        $this->assertNull($this->createService()->getProductTypes());
    }

    // --- isShopAccountActive() ---

    public function testIsShopAccountActiveReturnsFalseWhenApiKeyEmpty(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_API_KEY' => '',
        ]);

        $this->assertFalse($this->createService()->isShopAccountActive());
    }

    public function testIsShopAccountActiveReturnsFalseOnApiError(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_API_KEY' => 'configured-key',
        ]);

        $this->assertFalse($this->createService()->isShopAccountActive());
    }

    // --- save() ---

    public function testSaveReturnsValidationErrorWhenLoanTypeMissing(): void
    {
        $order = $this->createMagentoOrder(null);
        $this->session->method('getLastRealOrder')->willReturn($order);

        $result = $this->createService()->save();

        $this->assertCount(1, $result);
        // No redirectUrl on failure: the customer stays on the payment step and sees the error inline.
        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('financial product type', $result[0]['error']);
    }

    public function testSaveReturnsValidationErrorWhenCustomerDataInvalid(): void
    {
        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT');
        $this->session->method('getLastRealOrder')->willReturn($order);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        // Customer with empty contact fields and no address triggers every validatePaymentData branch.
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn(
            new Customer('', '', 'not-an-email', '', '127.0.0.1', null, null, true, null)
        );

        $result = $this->createService()->save();

        $error = $result[0]['error'];
        $this->assertStringContainsString('e-mail', $error);
        $this->assertStringContainsString('Phone number', $error);
        $this->assertStringContainsString('First name', $error);
        $this->assertStringContainsString('Last name', $error);
        $this->assertStringContainsString('Delivery address', $error);
        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
    }

    public function testSaveReturnsValidationErrorForMissingAddressFieldsAndEmptyCart(): void
    {
        // grandTotal 0 drives the "greater than zero" branch; the DTO cart total is derived from grandTotal.
        $order = $this->createMock(Order::class);
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['loanTerm', '12'],
            ['loanType', 'PAY_LATER'],
        ]);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getGrandTotal')->willReturn(0.0);
        $order->method('getId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('addStatusToHistory')->willReturnSelf();

        $this->session->method('getLastRealOrder')->willReturn($order);

        /* Empty items exercise the cart branch; address present but null city/postcode exercises the null-coalescing
           guards in the trim() checks. */
        $this->orderManager->method('getShopCartFromOrder')->willReturn(new Cart(0, null, null, 0, null, null, null, []));
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn(
            new Customer(
                'John',
                'Doe',
                'john@example.com',
                '+48123456789',
                '127.0.0.1',
                null,
                null,
                true,
                new Address(null, null, null, null, null, 'PL')
            )
        );

        $result = $this->createService()->save();

        $error = $result[0]['error'];
        $this->assertStringContainsString('City', $error);
        $this->assertStringContainsString('Postal code', $error);
        $this->assertStringContainsString('Cart is empty', $error);
        $this->assertStringContainsString('greater than zero', $error);
    }

    public function testSaveReturnsGenericErrorWhenApiCommunicationFails(): void
    {
        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT');
        $this->session->method('getLastRealOrder')->willReturn($order);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        /* Communication errors are treated like any other failure: the cart quote is restored so the customer can retry
           or pick another method. A Comfino application without the follow-up customer redirect is an ignored orphan. */
        $this->session->expects(self::once())->method('restoreQuote');

        // Valid data passes local validation, then ApiClient::getInstance() fails -> generic Throwable branch.
        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('Unsuccessful attempt', $result[0]['error']);
    }

    public function testSaveUsesIncrementIdWhenOrderReferenceEnabled(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_USE_ORDER_REFERENCE' => true,
            'COMFINO_INITIAL_ORDER_STATUS' => 'comfino_created',
        ]);

        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT');
        $this->session->method('getLastRealOrder')->willReturn($order);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        // Order reference path still ends in the API error branch since the client cannot be built.
        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    public function testSaveFallsBackToEntityIdWhenIncrementIdEmptyAndOrderReferenceEnabled(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_USE_ORDER_REFERENCE' => true,
            'COMFINO_INITIAL_ORDER_STATUS' => 'comfino_created',
        ]);

        // Empty increment_id forces the entity-id fallback branch in buildOrderDto.
        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT', '12', '');
        $this->session->method('getLastRealOrder')->willReturn($order);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertArrayHasKey('error', $result[0]);
    }

    // --- save(): order eligibility guard ---

    public function testSaveRejectsOrderPaidWithAnotherPaymentMethod(): void
    {
        /* The endpoint is anonymous and reads the session's last real order, so an order placed with a different
           method must not be turned into a Comfino application - nor be canceled by the failure path. */
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getId')->willReturn(42);

        $this->session->method('getLastRealOrder')->willReturn($order);
        $this->session->expects(self::never())->method('restoreQuote');
        $order->expects(self::never())->method('cancel');

        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('can not be submitted', $result[0]['error']);
    }

    public function testSaveRejectsOrderWhoseApplicationWasAlreadyCreated(): void
    {
        // Idempotency: a repeated call must not open a second application for the same order.
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['loanTerm', '12'],
            ['loanType', 'INSTALLMENTS_ZERO_PERCENT'],
            ['comfino_order_created', true],
        ]);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getId')->willReturn(42);

        $this->session->method('getLastRealOrder')->willReturn($order);
        $this->session->expects(self::never())->method('restoreQuote');
        $order->expects(self::never())->method('cancel');

        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('can not be submitted', $result[0]['error']);
    }

    public function testSaveRejectsOrderPastThePaymentStage(): void
    {
        $order = $this->createMock(Order::class);
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getId')->willReturn(42);
        $order->method('getState')->willReturn(Order::STATE_COMPLETE);

        $this->session->method('getLastRealOrder')->willReturn($order);
        $order->expects(self::never())->method('cancel');

        $result = $this->createService()->save();

        $this->assertStringContainsString('can not be submitted', $result[0]['error']);
    }

    public function testSaveRejectsSessionWithoutAnOrder(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $this->session->method('getLastRealOrder')->willReturn($order);

        $result = $this->createService()->save();

        $this->assertStringContainsString('can not be submitted', $result[0]['error']);
    }

    public function testSaveRecordsCancellationReasonInOrderHistory(): void
    {
        /* A cancelable order must receive a status-history note explaining WHY it was auto-canceled, so the shop
           operator can see the underlying Comfino/validation reason rather than just an unexplained cancellation. */
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['loanTerm', '12'],
            ['loanType', null],
        ]);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_NEW);
        $order->method('getGrandTotal')->willReturn(120.0);
        $order->method('getId')->willReturn(42);
        $order->method('canCancel')->willReturn(true);
        $order->method('getStatus')->willReturn('canceled');
        $order->expects(self::once())->method('cancel')->willReturnSelf();
        $order->expects(self::once())
            ->method('addStatusToHistory')
            ->with(
                'canceled',
                self::callback(
                    static fn (string $note): bool => str_contains($note, 'automatically canceled')
                        && str_contains($note, 'financial product type')
                )
            )
            ->willReturnSelf();

        $this->session->method('getLastRealOrder')->willReturn($order);

        $result = $this->createService()->save();

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('financial product type', $result[0]['error']);
    }
}