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

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Comfino\Tests\Support\ObjectManagerHarness;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\FailureToSendException;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;

final class ApiClientTest extends TestCase
{
    use LoggerHarnessTrait;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
        ]);

        $this->installLoggerHarness();
    }

    protected function tearDown(): void
    {
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdReusesValidCookieValue(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();
        $this->installCookieManager('checkout-cookie-track-id');

        ApiClient::pinCheckoutTrackId();

        $this->assertSame('checkout-cookie-track-id', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdRejectsInvalidCookieValueAndMintsFresh(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();
        $this->installCookieManager("evil\r\nSet-Cookie: pwned=1");

        ApiClient::pinCheckoutTrackId();

        $this->assertNotSame("evil\r\nSet-Cookie: pwned=1", ApiClient::getInstance()->getTrackId());
        $this->assertNotSame('', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdMintsFreshValueWhenCookieAbsent(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();
        $this->installCookieManager('');

        ApiClient::pinCheckoutTrackId();

        $this->assertNotSame('', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdStoresTrackIdInASecureHttpOnlyCookie(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();

        $sentMetadata = null;
        $sentValue = null;

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn('');
        $cookieManager->expects($this->once())
            ->method('setPublicCookie')
            ->willReturnCallback(
                function (string $name, string $value, PublicCookieMetadata $metadata) use (
                    &$sentMetadata,
                    &$sentValue
                ): void {
                    $this->assertSame('comfino_checkout_track_id', $name);

                    $sentValue = $value;
                    $sentMetadata = $metadata;
                }
            );

        $this->installObjectManagerWithCookieManager($cookieManager);

        ApiClient::pinCheckoutTrackId();

        $this->assertSame(ApiClient::getInstance()->getTrackId(), $sentValue);
        $this->assertNotNull($sentMetadata);
        $this->assertTrue($sentMetadata->getSecure());
        $this->assertTrue($sentMetadata->getHttpOnly());
        $this->assertSame('Lax', $sentMetadata->getSameSite());
        $this->assertSame('/', $sentMetadata->getPath());
        $this->assertSame(1800, $sentMetadata->getDuration());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * Browsers drop a Secure cookie received over plain HTTP, so an HTTP shop would lose the paywall trackId.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdStoresTrackIdInANonSecureCookieOverPlainHttp(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();

        $sentMetadata = null;

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn('');
        $cookieManager->expects($this->once())
            ->method('setPublicCookie')
            ->willReturnCallback(
                function (string $name, string $value, PublicCookieMetadata $metadata) use (&$sentMetadata): void {
                    $sentMetadata = $metadata;
                }
            );

        $this->installObjectManagerWithCookieManager($cookieManager, false);

        ApiClient::pinCheckoutTrackId();

        $this->assertNotNull($sentMetadata);
        $this->assertFalse($sentMetadata->getSecure());
        $this->assertTrue($sentMetadata->getHttpOnly());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdSurvivesAnUnsendableCookie(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->initSdkBootstrap();

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn('');
        $cookieManager->method('setPublicCookie')
            ->willThrowException(new FailureToSendException(new Phrase('Headers already sent.')));

        $this->installObjectManagerWithCookieManager($cookieManager);

        ApiClient::pinCheckoutTrackId();

        // The render keeps its own trackId rather than failing the paywall.
        $this->assertNotSame('', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdPrefersJourneyCookieWhenTrackingConsentEnabled(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => '1',
        ]);
        $this->initSdkBootstrap();
        $this->installCookieManagerWithCookies('journey-track-id.1', 'checkout-track-id.1');

        ApiClient::pinCheckoutTrackId();

        $this->assertSame('journey-track-id.1', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * The track ID sent with the payment data by the frontend SDK is the one of the paywall events.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdPrefersSubmittedTrackIdOverBothCookies(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => '1',
        ]);
        $this->initSdkBootstrap();
        $this->installCookieManagerWithCookies('journey-track-id.1', 'checkout-track-id.1');

        ApiClient::pinCheckoutTrackId('01a1d4b7-46b3-725e-86cc-0047ff6f3881');

        $this->assertSame('01a1d4b7-46b3-725e-86cc-0047ff6f3881', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdIgnoresInvalidSubmittedTrackId(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => '0',
        ]);
        $this->initSdkBootstrap();
        $this->installCookieManagerWithCookies('journey-track-id.1', 'checkout-track-id.1');

        ApiClient::pinCheckoutTrackId("bad\r\nvalue");

        $this->assertSame('checkout-track-id.1', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdIgnoresJourneyCookieWhenTrackingConsentDisabled(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => '0',
        ]);
        $this->initSdkBootstrap();
        $this->installCookieManagerWithCookies('journey-track-id.1', 'checkout-track-id.1');

        ApiClient::pinCheckoutTrackId();

        $this->assertSame('checkout-track-id.1', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws ReflectionException
     */
    public function testPinCheckoutTrackIdFallsBackToCheckoutCookieOnInvalidJourneyCookie(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => true,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => '1',
        ]);
        $this->initSdkBootstrap();
        $this->installCookieManagerWithCookies("evil\r\nSet-Cookie: pwned=1", 'checkout-track-id.1');

        ApiClient::pinCheckoutTrackId();

        $this->assertSame('checkout-track-id.1', ApiClient::getInstance()->getTrackId());

        ObjectManagerHarness::reset();
        $this->resetApiClientState();
    }


    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReleaseCheckoutTrackIdDeletesCheckoutCookieOnly(): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->expects($this->once())
            ->method('deleteCookie')
            ->with('comfino_checkout_track_id', $this->anything());

        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createCookieMetadata')
            ->willReturnCallback(static fn (): CookieMetadata => new CookieMetadata());

        ObjectManagerHarness::install([
            CookieManagerInterface::class => $cookieManager,
            CookieMetadataFactory::class => $metadataFactory,
        ]);

        ApiClient::releaseCheckoutTrackId();

        ObjectManagerHarness::reset();
    }

    /* processApiError is intentionally void; these tests assert that each exception branch runs to completion (logging
       through the injected debug/error loggers) without throwing. */

    public function testProcessApiErrorHandlesPlainThrowable(): void
    {
        ApiClient::processApiError(
            OperationContext::Unknown,
            ErrorCategory::Other,
            ErrorSeverity::Error,
            new RuntimeException('boom', 7)
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorRedactsTheDebugEvent(): void
    {
        $events = [];

        $debugLogger = (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->getValue();
        $debugLogger->method('logEvent')->willReturnCallback(
            static function (string $message, ?array $parameters, ?string $type) use (&$events): void {
                $events[(string) $type] = $parameters ?? [];
            }
        );

        ApiClient::processApiError(
            OperationContext::OrderCreation,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            new RuntimeException('Rejected customer jan.kowalski@example.com, phone +48 600 700 800')
        );

        $this->assertArrayHasKey('API_ERROR', $events);
        $this->assertStringNotContainsString('jan.kowalski@example.com', $events['API_ERROR']['error_message']);
        $this->assertStringNotContainsString('600 700 800', $events['API_ERROR']['error_message']);
    }

    public function testProcessApiErrorHandlesHttpErrorException(): void
    {
        $exception = new AccessDenied('Forbidden', 403, null, 'https://api.example/order', 'req-body', 'resp-body');

        ApiClient::processApiError(
            OperationContext::OrderCreation,
            ErrorCategory::ApiError,
            ErrorSeverity::Critical,
            $exception
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorHandlesConnectionTimeoutWithPrevious(): void
    {
        $exception = new ConnectionTimeout(
            'Timed out',
            0,
            new RuntimeException('curl timeout', 28),
            2,
            4,
            12,
            'https://api.example/timeout',
            'req',
            ''
        );

        ApiClient::processApiError(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            $exception
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorHandlesConnectionTimeoutWithoutPrevious(): void
    {
        $exception = new ConnectionTimeout('Timed out', 0, null, 1, 1, 3, 'https://api.example/timeout');

        ApiClient::processApiError(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            $exception
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorHandlesNetworkException(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn('network-request-body');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getRequestTarget')->willReturn('/v1/orders');

        $exception = new class ('Network down', $request) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        ApiClient::processApiError(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            $exception
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorSkipsErrorReportForResponseValidationError(): void
    {
        ApiClient::processApiError(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            new ResponseValidationError('invalid', 400)
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorSkipsErrorReportForAuthorizationError(): void
    {
        ApiClient::processApiError(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Warning,
            new AuthorizationError('unauthorized', 401)
        );

        $this->addToAssertionCount(1);
    }

    public function testProcessApiErrorWithDebugModeDisabledStillReportsError(): void
    {
        ConfigManagerHarness::install([
            'COMFINO_DEBUG' => false,
            'COMFINO_SERVICE_MODE' => false,
        ]);

        ApiClient::processApiError(
            OperationContext::Unknown,
            ErrorCategory::Other,
            ErrorSeverity::Error,
            new RuntimeException('boom')
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Wires a minimal SdkBootstrap with a stub PSR-18 client, so ApiClient::getInstance() builds a real client.
     */
    private function initSdkBootstrap(): void
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getCode')->willReturn('MG');
        $platformInfo->method('getName')->willReturn('Magento');
        $platformInfo->method('getVersion')->willReturn('2.4.7');
        $platformInfo->method('getLanguage')->willReturn('en');
        $platformInfo->method('getCurrency')->willReturn('PLN');
        $platformInfo->method('getDomain')->willReturn('shop.example');
        $platformInfo->method('getDatabaseVersion')->willReturn('8.0');
        $platformInfo->method('getPhpVersion')->willReturn(PHP_VERSION);
        $platformInfo->method('getPluginVersion')->willReturn('4.0.0');

        SdkBootstrap::init(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(LoggerInterface::class),
            $platformInfo,
            $this->createMock(LanguageProviderInterface::class),
            new ThemeFamilyRules()
        );
    }

    /**
     * @throws ReflectionException
     */
    private function resetApiClientState(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        $reflectionClass = new ReflectionClass(SdkBootstrap::class);

        $properties = [
            'httpClient',
            'requestFactory',
            'streamFactory',
            'platformInfo',
            'languageProvider',
            'themeFamilyRules',
        ];

        foreach ($properties as $prop) {
            $reflectionClass->getProperty($prop)->setValue(null, null);
        }
    }

    /**
     * Installs an ObjectManager whose cookie manager reports the given journey and checkout cookie values.
     */
    private function installCookieManagerWithCookies(string $journeyTrackId, string $checkoutTrackId): void
    {
        $cookies = ['comfino_journey_track_id' => $journeyTrackId, 'comfino_checkout_track_id' => $checkoutTrackId];

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')
            ->willReturnCallback(static fn (string $name, $default = null) => $cookies[$name] ?? $default);

        $this->installObjectManagerWithCookieManager($cookieManager);
    }

    /**
     * Installs an ObjectManager whose cookie manager reports $storedTrackId as the current cookie value and accepts any writing.
     */
    private function installCookieManager(string $storedTrackId): void
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturn($storedTrackId);

        $this->installObjectManagerWithCookieManager($cookieManager);
    }

    private function installObjectManagerWithCookieManager(
        CookieManagerInterface $cookieManager,
        bool $secureRequest = true
    ): void
    {
        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')
            ->willReturnCallback(static fn (): PublicCookieMetadata => new PublicCookieMetadata());

        $request = $this->createMock(HttpRequest::class);
        $request->method('isSecure')->willReturn($secureRequest);

        ObjectManagerHarness::install([
            CookieManagerInterface::class => $cookieManager,
            CookieMetadataFactory::class => $metadataFactory,
            HttpRequest::class => $request,
        ]);
    }
}
