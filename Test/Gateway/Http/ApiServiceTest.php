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

namespace Comfino\Tests\Gateway\Http;

use Comfino\Backend\Webhook\WebhookManager;
use Comfino\ComfinoGateway\Gateway\Http\ApiService;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Installs a real endpoint manager and marks the service initialized, avoiding the ObjectManager.
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

    // --- getWebhookApiKeys() ---

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string[]}>
     */
    public static function webhookApiKeysProvider(): array
    {
        $production = str_repeat('p', 32);
        $sandbox = str_repeat('s', 32);

        return [
            'production mode uses only the production key' => [
                ['COMFINO_API_KEY' => $production, 'COMFINO_SANDBOX_API_KEY' => $sandbox, 'COMFINO_IS_SANDBOX' => false],
                [$production],
            ],
            'sandbox mode adds the sandbox key first' => [
                ['COMFINO_API_KEY' => $production, 'COMFINO_SANDBOX_API_KEY' => $sandbox, 'COMFINO_IS_SANDBOX' => true],
                [$sandbox, $production],
            ],
            'sandbox mode with an empty sandbox key' => [
                ['COMFINO_API_KEY' => $production, 'COMFINO_SANDBOX_API_KEY' => '', 'COMFINO_IS_SANDBOX' => true],
                [$production],
            ],
            'no key configured' => [
                ['COMFINO_API_KEY' => '', 'COMFINO_SANDBOX_API_KEY' => null, 'COMFINO_IS_SANDBOX' => true],
                [],
            ],
            'too short keys are dropped' => [
                ['COMFINO_API_KEY' => 'prod-key', 'COMFINO_SANDBOX_API_KEY' => str_repeat('s', 15), 'COMFINO_IS_SANDBOX' => true],
                [],
            ],
            'surrounding whitespace is trimmed' => [
                ['COMFINO_API_KEY' => " $production\n", 'COMFINO_IS_SANDBOX' => false],
                [$production],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $expected
     */
    #[DataProvider('webhookApiKeysProvider')]
    public function testWebhookApiKeys(array $config, array $expected): void
    {
        ConfigManagerHarness::install($config);

        $this->assertSame($expected, ApiService::getWebhookApiKeys());
    }

    public function testWebhookIsRejectedWhenNoUsableKeyIsConfigured(): void
    {
        $this->assertNull($this->makeEndpointManager()->getTenantResolver()->resolveByTenantKey());
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

    public function testDebugLogCarriesNoHeadersBodyOrCalculatedSignature(): void
    {
        ConfigManagerHarness::install(['COMFINO_API_KEY' => str_repeat('p', 32), 'COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $_SERVER['HTTP_CR_SIGNATURE'] = str_repeat('a', 64);

        $logged = [];
        $debugLogger = (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->getValue();
        $debugLogger->method('logEvent')->willReturnCallback(
            static function (string $message, ?array $parameters, ?string $type) use (&$logged): void {
                $logged[(string) $type] = $parameters ?? [];
            }
        );

        $this->primeInitializedService();

        ApiService::processRequest('transactionStatus');

        $this->assertArrayHasKey('REST_API_REQUEST', $logged);
        $this->assertArrayNotHasKey('HEADERS', $logged['REST_API_REQUEST']);
        $this->assertArrayNotHasKey('BODY', $logged['REST_API_REQUEST']);

        $this->assertArrayHasKey('REST_API_RESPONSE', $logged);
        $this->assertArrayNotHasKey('CALCULATED-CR-SIGNATURE', $logged['REST_API_RESPONSE']);
        $this->assertArrayNotHasKey('HEADERS', $logged['REST_API_RESPONSE']);
        $this->assertArrayHasKey('VERIFICATION', $logged['REST_API_RESPONSE']);
        $this->assertLessThanOrEqual(8, strlen($logged['REST_API_RESPONSE']['RECEIVED-CR-SIGNATURE-PREFIX']));
    }
}
