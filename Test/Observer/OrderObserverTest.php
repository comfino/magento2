<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Observer;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Connector\Service\ApplicationService;
use Comfino\ComfinoGateway\Observer\OrderObserver;
use Comfino\Shop\Order\StatusApplicationContext;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class OrderObserverTest extends TestCase
{
    private ApplicationService&MockObject $applicationService;
    private OrderObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->primeConfig([]);

        $this->applicationService = $this->createMock(ApplicationService::class);
        $this->observer = new OrderObserver($this->applicationService);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);
        ConfigurationManager::reset();

        parent::tearDown();
    }

    /**
     * Builds a real ConfigurationManager backed by an in-memory storage adapter and injects it into the
     * ConfigManager facade, so the static config getters can be exercised without Magento's ObjectManager.
     *
     * @param array<string, mixed> $storedValues
     */
    private function primeConfig(array $storedValues): void
    {
        /* ConfigurationManager is a singleton; reset it so re-priming with new values within a test takes effect. */
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

    /**
     * Wraps an order in the order_cancel_after event payload the observer reads.
     */
    private function makeObserver(?Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }

    /**
     * @param array<string, mixed> $origData
     */
    private function makeOrder(
        ?Payment $payment,
        string $state = Order::STATE_PROCESSING,
        string $status = 'processing',
        array $origData = ['state' => Order::STATE_PROCESSING, 'status' => 'processing'],
        ?int $id = 100,
        string $incrementId = '000000100'
    ): Order&MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($status);
        $order->method('getOrigData')->willReturnCallback(
            static fn (string $key): mixed => $origData[$key] ?? null
        );
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }

    private function comfinoPayment(): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('comfino');

        return $payment;
    }

    public function testIgnoresEventWithoutOrder(): void
    {
        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver(null));
    }

    public function testIgnoresOrderWithoutPayment(): void
    {
        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver($this->makeOrder(null)));
    }

    public function testIgnoresNonComfinoPayment(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver($this->makeOrder($payment)));
    }

    public function testCancelsWhenStateTransitionsToCanceled(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_CANCELED,
            status: 'canceled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_accepted']
        );

        $this->applicationService
            ->expects($this->once())
            ->method('cancelApplicationTransaction')
            ->with('100');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testDoesNotCancelWhenStateWasAlreadyCanceled(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_CANCELED,
            status: 'canceled',
            origData: ['state' => Order::STATE_CANCELED, 'status' => 'comfino_cancelled']
        );

        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testCancelsWhenComfinoCancellationStatusAppliedWithoutStateTransition(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_PROCESSING,
            status: 'comfino_cancelled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_accepted']
        );

        $this->applicationService
            ->expects($this->once())
            ->method('cancelApplicationTransaction')
            ->with('100');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testDoesNotCancelWhenCancellationStatusWasAlreadySet(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_PROCESSING,
            status: 'comfino_cancelled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_rejected']
        );

        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testDoesNotCancelForNonCancellationStatusChange(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_PROCESSING,
            status: 'comfino_accepted',
            origData: ['state' => Order::STATE_NEW, 'status' => 'comfino_created']
        );

        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testDoesNotCancelWhenStatusChangeIsApiInitiated(): void
    {
        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_CANCELED,
            status: 'canceled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'processing']
        );

        $this->applicationService->expects($this->never())->method('cancelApplicationTransaction');

        // Simulates the SDK applying a Comfino API status notification, which must not echo a cancel back to the API.
        StatusApplicationContext::run(fn () => $this->observer->execute($this->makeObserver($order)));
    }

    public function testUsesIncrementIdWhenOrderReferenceEnabled(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_CANCELED,
            status: 'canceled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_accepted'],
            id: 100,
            incrementId: '000000100'
        );

        $this->applicationService
            ->expects($this->once())
            ->method('cancelApplicationTransaction')
            ->with('000000100');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testTrigger2UsesIncrementIdWhenOrderReferenceEnabled(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_PROCESSING,
            status: 'comfino_cancelled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_accepted'],
            id: 100,
            incrementId: '000000100'
        );

        $this->applicationService
            ->expects($this->once())
            ->method('cancelApplicationTransaction')
            ->with('000000100');

        $this->observer->execute($this->makeObserver($order));
    }

    public function testFallsBackToIdWhenOrderReferenceEnabledButIncrementIdEmpty(): void
    {
        $this->primeConfig(['COMFINO_USE_ORDER_REFERENCE' => '1']);

        $order = $this->makeOrder(
            payment: $this->comfinoPayment(),
            state: Order::STATE_CANCELED,
            status: 'canceled',
            origData: ['state' => Order::STATE_PROCESSING, 'status' => 'comfino_accepted'],
            id: 100,
            incrementId: ''
        );

        $this->applicationService
            ->expects($this->once())
            ->method('cancelApplicationTransaction')
            ->with('100');

        $this->observer->execute($this->makeObserver($order));
    }
}