<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Logger
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Logger;

use Comfino\Backend\Log\CookieServiceModeChecker;
use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\ComfinoGateway\Logger\Debug;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Exercises the Debug logging facade. The facade gates every call on debug mode and the service-mode cookie before
 * delegating to the SDK DebugLogger; tests inject a mock SDK logger and a controllable cookie checker so no filesystem
 * or ObjectManager access is required.
 *
 * The facade forwards contextDepth: 1 to the SDK so the auto-captured caller is the real call site rather than the
 * facade's own static frame.
 */
final class DebugTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(Debug::class, 'debugLogger'))->setValue(null, null);
        (new ReflectionProperty(Debug::class, 'cookieChecker'))->setValue(null, null);
        ConfigManagerHarness::reset();
        SdkDebugLogger::reset();

        unset($_COOKIE['COMFINO_SERVICE_SESSION']);

        parent::tearDown();
    }

    /**
     * Injects a mock SDK debug logger into the facade and returns it for expectation setting.
     */
    private function injectSdkLogger(): SdkDebugLogger&MockObject
    {
        $logger = $this->getMockBuilder(SdkDebugLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['logEvent', 'clearLogs'])
            ->getMock();

        (new ReflectionProperty(Debug::class, 'debugLogger'))->setValue(null, $logger);

        return $logger;
    }

    /**
     * Injects a cookie checker whose isServiceMode() returns the given value.
     */
    private function injectCookieChecker(bool $serviceModeActive): void
    {
        $checker = $this->createMock(CookieServiceModeChecker::class);
        $checker->method('isServiceMode')->willReturn($serviceModeActive);

        (new ReflectionProperty(Debug::class, 'cookieChecker'))->setValue(null, $checker);
    }

    public function testLogEventDelegatesWhenDebugModeOn(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        /* The facade appends contextDepth: 1 so the SDK skips the facade frame and records the real caller. */
        $logger->expects($this->once())->method('logEvent')->with('message', ['k' => 'v'], 'PREFIX', 1);

        Debug::logEvent('message', ['k' => 'v'], 'PREFIX');
    }

    public function testLogEventDoesNotDelegateWhenDebugModeOff(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => false, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->never())->method('logEvent');

        Debug::logEvent('message', null, 'PREFIX');
    }

    public function testLogEventReturnsEarlyInServiceModeWithoutCookie(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => true]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->never())->method('logEvent');

        Debug::logEvent('message', null, 'PREFIX');
    }

    public function testLogEventDelegatesInServiceModeWhenCookiePresent(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => true]);

        $this->injectCookieChecker(true);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())->method('logEvent');

        Debug::logEvent('message', null, 'PREFIX');
    }

    public function testLogEventForwardsContextDepthWithoutEventTypeOrParameters(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())->method('logEvent')->with('contextual', null, null, 1);

        Debug::logEvent('contextual');
    }

    public function testLogEventWithContextMethodNoLongerExists(): void
    {
        /* The facade dropped logEventWithContext(); logEvent() now always auto-captures caller context. */
        $this->assertFalse(method_exists(Debug::class, 'logEventWithContext'));
    }

    public function testClearLogsDelegatesToLoggerInstance(): void
    {
        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())->method('clearLogs')->willReturn(2);

        Debug::clearLogs();
    }

    /**
     * The cookie-backed CookieServiceModeChecker is lazily built on first use. With the service-mode cookie set, the
     * facade proceeds to delegate, proving the real checker was constructed and consulted.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLazyCookieCheckerHonoursServiceSessionCookie(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => true]);

        $_COOKIE['COMFINO_SERVICE_SESSION'] = 'ACTIVE';

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())->method('logEvent');

        Debug::logEvent('message', null, 'PREFIX');
    }

    /**
     * Covers the lazy build of the real SDK DebugLogger inside getLoggerInstance() using the BP constant branch. The
     * SDK logger constructor only stores the path, so no file is written by this delegation.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetLoggerInstanceLazilyBuildsRealSdkLoggerUsingBpConstant(): void
    {
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }

        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = Debug::getLoggerInstance();

        $this->assertInstanceOf(SdkDebugLogger::class, $logger);
        /* The instance is memoized: a second call returns the same object. */
        $this->assertSame($logger, Debug::getLoggerInstance());
    }
}