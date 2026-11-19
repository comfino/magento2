<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Order;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\CancellationGuard;
use Comfino\ComfinoGateway\Model\Order\StatusAdapter;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class StatusAdapterTest extends TestCase
{
    private OrderRepository&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->primeConfig([]);

        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);

        ConfigurationManager::reset();

        parent::tearDown();
    }

    public function testIgnoredStatusIsSilentlySkipped(): void
    {
        $this->primeConfig(['COMFINO_IGNORED_STATUSES' => 'WAITING_FOR_PAYMENT']);

        $this->orderRepository->expects($this->never())->method('get');
        $this->orderRepository->expects($this->never())->method('getList');

        $this->makeAdapter()->setStatus('100', 'WAITING_FOR_PAYMENT');
    }

    public function testForbiddenStatusIsBlocked(): void
    {
        $this->primeConfig(['COMFINO_FORBIDDEN_STATUSES' => 'RESIGN']);

        $this->orderRepository->expects($this->never())->method('get');

        $this->makeAdapter()->setStatus('100', 'RESIGN');
    }

    public function testUnmappedStatusIsSkipped(): void
    {
        $this->orderRepository->expects($this->never())->method('get');

        $this->makeAdapter()->setStatus('100', 'SOME_UNKNOWN_STATUS');
    }

    public function testAppliesMappedStatusViaDirectIdLookup(): void
    {
        $order = $this->createComfinoOrder();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(false);
        $order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $order->expects($this->once())->method('setStatus')->with('comfino_accepted');
        $order->expects($this->once())->method('addStatusToHistory')->with('comfino_accepted');

        $this->orderRepository->expects($this->once())->method('get')->with(100)->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testAppliesMappedStatusViaIncrementIdLookupWhenOrderReferenceEnabled(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $order = $this->createComfinoOrder();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(false);

        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder
            ->expects($this->once())
            ->method('addFilter')
            ->with('increment_id', '000000100')
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->expects($this->once())->method('getList')->willReturn($searchResult);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('000000100', 'ACCEPTED');
    }

    public function testSkipsWhenOrderReferenceLookupFindsNothing(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([]);
        $this->orderRepository->method('getList')->willReturn($searchResult);
        $this->orderRepository->expects($this->never())->method('save');

        $this->makeAdapter()->setStatus('000000100', 'ACCEPTED');
    }

    public function testThrowsWhenResolvedEntityIsNotAnOrderInstance(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $notAnOrder = $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class);
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$notAnOrder]);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $this->expectException(RuntimeException::class);

        $this->makeAdapter()->setStatus('000000100', 'ACCEPTED');
    }

    /**
     * The status map is remotely writable: one naming an undefined status is ignored as a whole and the default map applies.
     */
    public function testIgnoresAStatusMapWithAnUnknownPlatformStatusCode(): void
    {
        $this->primeConfig(['COMFINO_STATUS_MAP' => '{"ACCEPTED":"some_unknown_code"}']);

        $order = $this->createComfinoOrder();
        $order->method('getIsVirtual')->willReturn(false);
        $order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING)->willReturnSelf();
        $order->expects($this->once())->method('setStatus')->with('comfino_accepted')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testCreatesInvoiceForAcceptedVirtualOrder(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->expects($this->once())->method('register')->willReturnSelf();

        $order = $this->createComfinoOrder();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(true);
        $order->method('canInvoice')->willReturn(true);
        $order->expects($this->once())->method('prepareInvoice')->willReturn($invoice);
        $order->expects($this->once())->method('addRelatedObject')->with($invoice);

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testInvoiceCreationFailureIsCaughtAndOrderStillSaved(): void
    {
        $order = $this->createComfinoOrder();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(true);
        $order->method('canInvoice')->willReturn(true);
        $order->method('prepareInvoice')->willThrowException(new LocalizedException(__('boom')));
        $order->expects($this->never())->method('addRelatedObject');

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testNoInvoiceWhenAcceptedButNotVirtual(): void
    {
        $order = $this->createComfinoOrder();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(false);
        $order->expects($this->never())->method('prepareInvoice');

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testTerminalComfinoStatusIsRecordedOnThePayment(): void
    {
        $payment = $this->createComfinoPayment();
        $payment->expects($this->once())->method('setAdditionalInformation')
            ->with(CancellationGuard::TERMINAL_STATUS_KEY, 'REJECTED');

        $order = $this->createComfinoOrder($payment);
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->makeAdapter()->setStatus('100', 'REJECTED');
    }

    public function testNonTerminalComfinoStatusIsNotRecorded(): void
    {
        $payment = $this->createComfinoPayment();
        $payment->expects($this->never())->method('setAdditionalInformation');

        $order = $this->createComfinoOrder($payment);
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('getIsVirtual')->willReturn(false);

        $this->orderRepository->method('get')->willReturn($order);

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    // --- order ownership and redelivery (acceptsOrder / isStatusAlreadyApplied) ---

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function foreignOrderProvider(): array
    {
        return [
            'paid with another method' => ['checkmo', true],
            'no ComfinoPay application opened' => ['comfino', false],
        ];
    }

    #[DataProvider('foreignOrderProvider')]
    public function testSkipsAnOrderWithoutAComfinoPayApplication(string $method, bool $applicationCreated): void
    {
        $order = $this->createComfinoOrder($this->createComfinoPayment($method, $applicationCreated));
        $order->expects($this->never())->method('setStatus');

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects($this->never())->method('save');

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    public function testSkipsAnOrderThatDoesNotExist(): void
    {
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->orderRepository->expects($this->never())->method('save');

        $this->makeAdapter()->setStatus('999', 'ACCEPTED');
    }

    public function testARedeliveredStatusWritesNothing(): void
    {
        $order = $this->createComfinoOrder(null, 'comfino_accepted');
        $order->expects($this->never())->method('addStatusToHistory');

        $this->orderRepository->expects($this->once())->method('get')->willReturn($order);
        $this->orderRepository->expects($this->never())->method('save');

        $this->makeAdapter()->setStatus('100', 'ACCEPTED');
    }

    /**
     * Builds a real ConfigurationManager on an in-memory storage adapter and injects it into the ConfigManager facade.
     *
     * @param array<string, mixed> $storedValues
     */
    private function primeConfig(array $storedValues): void
    {
        // ConfigurationManager is a singleton; reset it so re-priming with new values within a test takes effect.
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);

        ConfigurationManager::reset();

        $storage = new class ($storedValues) implements StorageAdapterInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function load(): array
            {
                return $this->values;
            }

            public function save(array $configurationOptions): void
            {
                $this->values = array_merge($this->values, $configurationOptions);
            }
        };

        $manager = ConfigurationManager::getInstance(
            array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)),
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            $storage,
            new JsonSerializer()
        );

        $manager->setDefaults(ConfigManager::getDefaultConfigurationValues());

        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, $manager);
    }

    private function makeAdapter(): StatusAdapter
    {
        return new StatusAdapter($this->orderRepository, $this->searchCriteriaBuilder);
    }

    /**
     * A payment of an order this module opened a ComfinoPay application for.
     */
    private function createComfinoPayment(string $method = 'comfino', bool $applicationCreated = true): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (?string $key = null): mixed => $key === 'comfino_order_created' ? $applicationCreated : null
        );

        return $payment;
    }

    private function createComfinoOrder(?Payment $payment = null, ?string $status = 'pending_payment'): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment ?? $this->createComfinoPayment());
        $order->method('getStatus')->willReturn($status);

        return $order;
    }
}
