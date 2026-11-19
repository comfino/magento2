<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Connector\Service;

use Comfino\Api\Dto\Payment\FinancialProduct;
use Comfino\Api\Client;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Response\CreateOrder;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Connector\Service\ApplicationService;
use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\Customer\Address;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Comfino\Tests\Support\ObjectManagerHarness;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
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

    /** @var FinancialProduct[] Offers returned by the stubbed financial products lookup */
    private array $financialProducts = [];

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
            $this->orderManager,
            $this->createLoanSelection()
        );
    }

    /**
     * A real LoanSelection with API lookups replaced: all loan types offered, no term limits, products from $this->financialProducts.
     */
    private function createLoanSelection(): LoanSelection
    {
        return new class ($this->createMock(CacheInterface::class), new Json(), fn (): array => $this->financialProducts) extends LoanSelection {
            public function __construct(CacheInterface $cache, Json $serializer, private readonly Closure $financialProducts)
            {
                parent::__construct($cache, $serializer);
            }

            protected function getAllowedProductTypes(Cart $cart): array
            {
                return LoanType::cases();
            }

            protected function getTermLimits(): ?array
            {
                return null;
            }

            protected function getFinancialProducts(int $loanAmount, array $allowedProductTypes): array
            {
                return ($this->financialProducts)();
            }
        };
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
     * Builds an order that clears checkOrderEligibility(); tests of the guard override the relevant getter.
     */
    private function createMagentoOrder(
        ?string $loanType,
        ?string $loanTerm = '12',
        string $incrementId = '000000042',
        ?string $promoCode = null
    ): Order&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['loanTerm', $loanTerm],
            ['loanType', $loanType],
            ['promoCode', $promoCode],
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
     * The queued payload must carry the order's store: the drain runs in the crontab area with the default scope and
     * would otherwise use the wrong API credentials.
     */
    public function testCancelApplicationTransactionRecordsTheStoreIdInTheQueuedPayload(): void
    {
        $storage = $this->installQueueWithFailingHandler();

        $this->createService()->cancelApplicationTransaction('100000042', 7);

        $this->assertCount(1, $storage->requests, 'The failed cancel should have been deferred to the queue.');
        $this->assertSame(['orderId' => '100000042', 'storeId' => 7], $storage->requests[0]->payload);
        $this->assertSame('store:7', $storage->requests[0]->tenantKey);
    }

    public function testCancelApplicationTransactionOmitsTheStoreIdWhenNotSupplied(): void
    {
        $storage = $this->installQueueWithFailingHandler();

        $this->createService()->cancelApplicationTransaction('100000043');

        $this->assertCount(1, $storage->requests);
        $this->assertSame(['orderId' => '100000043'], $storage->requests[0]->payload);
    }

    /**
     * Installs a real OutboundRequestQueue whose cancel_order handler fails transiently, so submit() defers to storage.
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

            public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
            {
                $matching = array_values(array_filter(
                    $this->requests,
                    static function (QueuedRequest $request) use ($tenantKey, $dueAt): bool {
                        if ($tenantKey !== null && $request->tenantKey !== $tenantKey) {
                            return false;
                        }

                        return $dueAt === null || $request->isDue($dueAt);
                    }
                ));

                return array_slice($matching, 0, $limit);
            }

            public function update(QueuedRequest $request): void
            {
                // Not exercised by these tests.
            }

            public function remove(QueuedRequest $request): void
            {
                // Not exercised by these tests.
            }

            public function count(?string $tenantKey = null): int
            {
                if ($tenantKey === null) {
                    return count($this->requests);
                }

                return count(array_filter($this->requests, static fn(QueuedRequest $request): bool => $request->tenantKey === $tenantKey));
            }

            public function pendingTenantKeys(?int $dueAt = null): array
            {
                $keys = [];

                foreach ($this->requests as $request) {
                    if ($dueAt !== null && !$request->isDue($dueAt)) {
                        continue;
                    }

                    $keys[] = $request->tenantKey;
                }

                return array_values(array_unique($keys, SORT_REGULAR));
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

    /**
     * A transient failure (here HTTP 503) tells the shopper the service is
     * temporarily unavailable rather than that something is wrong with their data.
     */
    public function testSaveReturnsTheOutageMessageForATransientFailure(): void
    {
        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT');
        $this->session->method('getLastRealOrder')->willReturn($order);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $client = $this->createMock(Client::class);
        $client->method('validateOrder')->willThrowException(new ServiceUnavailable('Service unavailable', 503));
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, $client);

        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')->willReturn(new PublicCookieMetadata());

        ObjectManagerHarness::install([
            CookieManagerInterface::class => $this->createMock(CookieManagerInterface::class),
            CookieMetadataFactory::class => $metadataFactory,
            HttpRequest::class => $this->createMock(HttpRequest::class),
        ]);

        $this->session->expects(self::once())->method('restoreQuote');

        try {
            $result = $this->createService()->save();
        } finally {
            ObjectManagerHarness::reset();
        }

        $this->assertArrayNotHasKey('redirectUrl', $result[0]);
        $this->assertStringContainsString('temporarily unavailable', $result[0]['error']);
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

    // --- buildOrderDto(): loan selection ---

    /**
     * @throws ReflectionException
     */
    public function testBuildOrderDtoSendsTheSubmittedSelection(): void
    {
        $order = $this->createMagentoOrder('CONVENIENT_INSTALLMENTS', '18');

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $dto = (new ReflectionMethod(ApplicationService::class, 'buildOrderDto'))->invoke($this->createService(), $order);

        $this->assertSame(LoanType::CONVENIENT_INSTALLMENTS, $dto->getLoanParameters()->getType());
        $this->assertSame(18, $dto->getLoanParameters()->getTerm());
    }

    /**
     * A missing selection no longer refuses the order: it goes out with the first
     * offer that is neither INSTANT_PAYMENTS nor BLIK, with that offer's term.
     *
     * @throws ReflectionException
     */
    public function testBuildOrderDtoFallsBackToTheSafeDefaultProduct(): void
    {
        $this->financialProducts = [
            self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1),
            self::createFinancialProduct(LoanType::BLIK, 1),
            self::createFinancialProduct(LoanType::PAY_LATER, 3),
        ];

        $order = $this->createMagentoOrder(null, null);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $dto = (new ReflectionMethod(ApplicationService::class, 'buildOrderDto'))->invoke($this->createService(), $order);

        $this->assertSame(LoanType::PAY_LATER, $dto->getLoanParameters()->getType());
        $this->assertSame(3, $dto->getLoanParameters()->getTerm());
    }

    private static function createFinancialProduct(LoanType $type, int $loanTerm): FinancialProduct
    {
        return new FinancialProduct($type->value, $type, 'Creditor', '', '', 1000, 3000, $loanTerm, 0.0, '', null, []);
    }

    // --- buildOrderDto(): promo code ---

    /**
     * The code the customer entered in the paywall (stored by DataAssignObserver) must reach the API request, normalized.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function promoCodeProvider(): array
    {
        return [
            'code is forwarded' => ['SUMMER10', 'SUMMER10'],
            'code is cleaned up' => ['  <b>SUMMER10</b> ', 'SUMMER10'],
            'no code' => [null, null],
            'blank code is dropped' => ['  ', null],
            'over-long code is dropped' => [str_repeat('A', 65), null],
        ];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('promoCodeProvider')]
    public function testBuildOrderDtoPassesTheNormalisedPromoCode(?string $stored, ?string $expected): void
    {
        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT', '12', '000000042', $stored);

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $dto = (new ReflectionMethod(ApplicationService::class, 'buildOrderDto'))->invoke($this->createService(), $order);

        $this->assertSame($expected, $dto->getPromoCode());
    }

    /**
     * @return array<string, array{0: bool|null, 1: bool}>
     */
    public static function directRedirectProvider(): array
    {
        return [
            'mode enabled' => [true, true],
            'mode disabled' => [false, false],
            'option never saved' => [null, false],
        ];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('directRedirectProvider')]
    public function testBuildOrderDtoReflectsTheDirectRedirectMode(?bool $configured, bool $expected): void
    {
        ConfigManagerHarness::install([
            'COMFINO_USE_ORDER_REFERENCE' => false,
            'COMFINO_PAYWALL_DIRECT_REDIRECT' => $configured
        ]);

        $order = $this->createMagentoOrder('INSTALLMENTS_ZERO_PERCENT');

        $this->orderManager->method('getShopCartFromOrder')->willReturn($this->createCart());
        $this->orderManager->method('getShopCustomerFromOrder')->willReturn($this->createValidCustomer());

        $dto = (new ReflectionMethod(ApplicationService::class, 'buildOrderDto'))->invoke($this->createService(), $order);

        $this->assertSame($expected, $dto->getDirectRedirect());
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
        /* A cancelable order must receive a status-history note explaining WHY it was auto-canceled, so the shop operator
           can see the underlying Comfino/validation reason rather than just an unexplained cancellation. */
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

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function applicationUrlProvider(): array
    {
        return [
            'production domain' => ['https://wniosek.comfino.pl/abc', false, true],
            'sandbox domain' => ['https://wniosek.craty.pl/abc', false, true],
            'foreign domain' => ['https://comfino.pl.attacker.example/abc', false, false],
            'look-alike suffix' => ['https://evilcomfino.pl/abc', false, false],
            'docker host outside dev env' => ['http://wniosek/abc', false, false],
            'docker host in dev env' => ['http://wniosek/abc', true, true],
            'foreign domain in dev env' => ['https://attacker.example/abc', true, false],
        ];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('applicationUrlProvider')]
    public function testOnlyComfinoPayApplicationUrlsAreTrusted(string $url, bool $devEnv, bool $expected): void
    {
        putenv($devEnv ? 'COMFINO_DEV_ENV=TRUE' : 'COMFINO_DEV_ENV');

        try {
            $response = (new \ReflectionClass(CreateOrder::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(CreateOrder::class, 'applicationUrl'))->setValue($response, $url);

            $method = new ReflectionMethod(ApplicationService::class, 'isTrustedApplicationUrl');

            $this->assertSame($expected, $method->invoke(null, $response));
        } finally {
            putenv('COMFINO_DEV_ENV');
        }
    }
}
