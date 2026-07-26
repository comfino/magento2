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

namespace Comfino\Tests\Gateway\Http;

use Comfino\Backend\Webhook\WebhookManager;
use Comfino\ComfinoGateway\Gateway\Http\ApiService;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ApiServiceTest extends TestCase
{
    use LoggerHarnessTrait;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'shop.example';
        $_SERVER['REQUEST_URI'] = '/comfino/transactionstatus';
        $_SERVER['HTTPS'] = 'off';

        ConfigManagerHarness::install([
            'COMFINO_API_KEY' => 'prod-key',
            'COMFINO_SANDBOX_API_KEY' => 'sand-key',
            'COMFINO_DEBUG' => false,
            'COMFINO_SERVICE_MODE' => false,
        ]);

        $this->installLoggerHarness();
        $this->resetServiceState();
    }

    protected function tearDown(): void
    {
        $this->resetServiceState();
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();

        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    private function resetServiceState(): void
    {
        (new ReflectionProperty(ApiService::class, 'endpointManager'))->setValue(null, null);
        (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, false);
    }

    private function makeEndpointManager(): WebhookManager
    {
        $method = new ReflectionMethod(ApiService::class, 'getEndpointManager');
        $method->setAccessible(true);

        /** @var WebhookManager $manager */
        $manager = $method->invoke(null, '2.4.8', '4.0.0');

        return $manager;
    }

    /**
     * Pre-installs a real endpoint manager and marks the service initialized so processRequest can run without
     * touching the Magento ObjectManager (used by the production init path).
     */
    private function primeInitializedService(): void
    {
        (new ReflectionProperty(ApiService::class, 'endpointManager'))->setValue(null, $this->makeEndpointManager());
        (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, true);
    }

    // --- getEndpointManager() ---

    public function testGetEndpointManagerCreatesWebhookManager(): void
    {
        $this->assertInstanceOf(WebhookManager::class, $this->makeEndpointManager());
    }

    public function testGetEndpointManagerReturnsCachedInstance(): void
    {
        $first = $this->makeEndpointManager();
        $second = $this->makeEndpointManager();

        $this->assertSame($first, $second);
    }

    // --- init() guard ---

    public function testInitIsNoOpWhenAlreadyInitialized(): void
    {
        (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, true);

        $orderRepository = $this->createMock(OrderRepository::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        ApiService::init('a', 'b', 'c', '2.4.8', '4.0.0', $orderRepository, $searchCriteriaBuilder);

        /* No endpoint manager was created because the guard short-circuited. */
        $this->assertNull((new ReflectionProperty(ApiService::class, 'endpointManager'))->getValue());
    }

    // --- ensureInitialized() guard ---

    public function testEnsureInitializedIsNoOpWhenAlreadyInitialized(): void
    {
        (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, true);

        ApiService::ensureInitialized();

        $this->assertTrue((new ReflectionProperty(ApiService::class, 'initialized'))->getValue());
    }

    // --- processRequest() ---

    public function testProcessRequestReturnsResponseForUnknownEndpoint(): void
    {
        $this->primeInitializedService();

        $response = ApiService::processRequest('unknownEndpoint');

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testProcessRequestLogsWhenDebugModeEnabled(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_API_KEY' => 'prod-key',
            'COMFINO_SANDBOX_API_KEY' => 'sand-key',
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
        ]);

        $this->primeInitializedService();

        $response = ApiService::processRequest('unknownEndpoint');

        /* Debug mode on + non-200 response exercises both debug logging blocks in processRequest. */
        $this->assertNotSame(200, $response->getStatusCode());
    }
}