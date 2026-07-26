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

namespace Comfino\Tests\Model\Telemetry;

use Comfino\Api\Client;
use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopTheme;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Telemetry\HyvaCheckoutMetaResolver;
use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use Comfino\ComfinoGateway\Model\Telemetry\TestProductUrlResolver;
use Comfino\ComfinoGateway\Http\Psr17Factory;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * report() is fire-and-forget: it resolves a test product URL, builds a backend report, ships it via the shared
 * ApiClient, and returns the API's accept/reject boolean. Any throw on the way is swallowed and logged as a failure
 * (returns false). The shared ApiClient singleton is seeded with a real Comfino\Api\Client wrapping a mocked PSR-18
 * stack so no network is touched; the debug logger is mocked through LoggerHarnessTrait.
 */
final class ShopEnvironmentReporterTest extends TestCase
{
    use LoggerHarnessTrait;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install([
            'COMFINO_API_KEY' => 'test-api-key',
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
        ]);
        $this->installLoggerHarness();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, null);
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    /**
     * Installs a real API Client backed by a PSR-18 client that either returns a response or throws, then publishes
     * it as the ApiClient singleton, so ApiClient::getInstance() hands it back without building a real one.
     */
    private function seedApiClient(bool $sendThrows): void
    {
        $psrClient = $this->createMock(PsrClientInterface::class);

        if ($sendThrows) {
            $psrClient->method('sendRequest')->willThrowException(new RuntimeException('network down'));
        } else {
            $psrClient->method('sendRequest')->willReturn(new Response(200));
        }

        /* The module's own PSR-17 factory builds real PSR-7 request/stream objects so Client::reportShopEnvironment
           reaches the (mocked) transport instead of failing during request assembly. */
        $factory = new Psr17Factory();

        $client = new Client($psrClient, $factory, $factory, 'test-api-key');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, $client);

        /* ApiClient::getInstance() re-applies config to the cached client and reads SdkBootstrap::getHttpClient();
           seed it with the same PSR client (plain, not TimeoutAware) so the reuse branch resolves without a real
           Bootstrap. */
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $psrClient);
    }

    private function makeReport(): ShopEnvironmentReport
    {
        return new ShopEnvironmentReport(
            'magento',
            'Magento',
            '2.4.8',
            'community',
            'shop.example',
            '4.0.0',
            new ShopTheme('Luma', 'Luma'),
            'pl',
            'PLN'
        );
    }

    /**
     * @param ?string $resolvedUrl URL the resolver hands back; asserted to flow into buildForBackendReport()
     */
    private function makeReporter(?string $resolvedUrl, ShopEnvironmentReport $report): ShopEnvironmentReporter
    {
        $resolver = $this->createMock(TestProductUrlResolver::class);
        $resolver->method('resolve')->willReturn($resolvedUrl);

        $builder = $this->createMock(AbstractShopEnvironmentBuilder::class);
        $builder->expects($this->once())
            ->method('buildForBackendReport')
            ->with($resolvedUrl, $this->isType('array'))
            ->willReturn($report);

        $hyvaCheckoutMetaResolver = $this->createMock(HyvaCheckoutMetaResolver::class);
        $hyvaCheckoutMetaResolver->method('resolve')->willReturn([]);

        return new ShopEnvironmentReporter($builder, $resolver, $hyvaCheckoutMetaResolver);
    }

    public function testReportReturnsTrueWhenApiAccepts(): void
    {
        $this->seedApiClient(false);

        $reporter = $this->makeReporter('https://shop.example/p.html', $this->makeReport());

        $this->assertTrue($reporter->report());
    }

    public function testReportReturnsFalseWhenApiRejects(): void
    {
        /* The PSR client throws, so Client::reportShopEnvironment swallows it and returns false (rejected). */
        $this->seedApiClient(true);

        $reporter = $this->makeReporter(null, $this->makeReport());

        $this->assertFalse($reporter->report());
    }

    public function testReportReturnsFalseAndSwallowsBuilderException(): void
    {
        $this->seedApiClient(false);

        $resolver = $this->createMock(TestProductUrlResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $builder = $this->createMock(AbstractShopEnvironmentBuilder::class);
        $builder->method('buildForBackendReport')
            ->willThrowException(new RuntimeException('builder blew up'));

        $hyvaCheckoutMetaResolver = $this->createMock(HyvaCheckoutMetaResolver::class);
        $hyvaCheckoutMetaResolver->method('resolve')->willReturn([]);

        $reporter = new ShopEnvironmentReporter($builder, $resolver, $hyvaCheckoutMetaResolver);

        $this->assertFalse($reporter->report());
    }

    /**
     * No API key configured (e.g., a fresh installation) must short-circuit before touching the builder or the API
     * client — there is nothing to authenticate the report with yet.
     */
    public function testReportReturnsFalseWithoutBuildingWhenApiKeyMissing(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $resolver = $this->createMock(TestProductUrlResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $builder = $this->createMock(AbstractShopEnvironmentBuilder::class);
        $builder->expects($this->never())->method('buildForBackendReport');

        $hyvaCheckoutMetaResolver = $this->createMock(HyvaCheckoutMetaResolver::class);
        $hyvaCheckoutMetaResolver->expects($this->never())->method('resolve');

        $reporter = new ShopEnvironmentReporter($builder, $resolver, $hyvaCheckoutMetaResolver);

        $this->assertFalse($reporter->report());
    }
}
