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

use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Observer\ConfigObserver;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * ConfigObserver runs after the payment config is saved. It always flushes the config cache, and — when an API key is
 * present — validates the key against the Comfino API and persists the returned widget key.
 *
 * The branches that reach a live API client are validated here only through their error handling: with the SDK
 * Bootstrap left uninitialized, ApiClient::getInstance() throws, which the observer catches and surfaces as an admin
 * error message. The happy path and the dedicated authorization-error path call isShopAccountActive() on the SDK's
 * final Client, which needs a live HTTP stack, so those are left to integration tests (mirroring SystemInfoTest).
 */
final class ConfigObserverTest extends TestCase
{
    use LoggerHarnessTrait;

    private WriterInterface&MockObject $configWriter;
    private TypeListInterface&MockObject $cacheTypeList;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private ManagerInterface&MockObject $messageManager;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->installLoggerHarness();

        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    private function makeObserver(): ConfigObserver
    {
        return new ConfigObserver(
            $this->configWriter,
            $this->cacheTypeList,
            $this->scopeConfig,
            $this->messageManager
        );
    }

    /**
     * Configures the scope config to report the given sandbox flag and API keys.
     */
    private function primeScopeConfig(bool $sandbox, string $productionKey, string $sandboxKey): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($sandbox, $productionKey, $sandboxKey): mixed {
                return match ($path) {
                    Data::XML_PATH_SANDBOX_ENABLED => $sandbox,
                    Data::XML_PATH_API_KEY => $productionKey,
                    Data::XML_PATH_SANDBOX_API_KEY => $sandboxKey,
                    default => null,
                };
            }
        );
    }

    public function testAlwaysFlushesConfigCacheWhenNoApiKeyConfigured(): void
    {
        $this->primeScopeConfig(false, '', '');

        $this->configWriter->expects($this->never())->method('save');
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testSurfacesErrorAndStillFlushesCacheWhenClientCreationFails(): void
    {
        /* A non-empty key forces the API branch. The SDK Bootstrap is uninitialized, so ApiClient::getInstance()
           throws a LogicException, which the catch-all converts into an admin error message. */
        $this->primeScopeConfig(true, '', 'sandbox-key');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testTrimsApiKeyBeforeDecidingWhetherToValidate(): void
    {
        /* A whitespace-only key trims to empty, so no validation runs but the cache is still flushed. */
        $this->primeScopeConfig(false, "   \t", '');

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testReadsScopeWithStoreScope(): void
    {
        /* Sanity check that the observer queries config under the store scope, not default. */
        $this->scopeConfig
            ->expects($this->atLeastOnce())
            ->method('getValue')
            ->with($this->anything(), ScopeInterface::SCOPE_STORE)
            ->willReturn('');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testFlushesFullPageCacheWhenProductCategoryFiltersChanged(): void
    {
        /* A stale full_page-cached product page bakes the widget config (incl. category-filtered offer types) into
           its markup, so changing this path must also bust full_page — not just config. */
        $this->primeScopeConfig(false, '', '');

        $this->cacheTypeList
            ->expects($this->exactly(2))
            ->method('cleanType')
            ->with($this->logicalOr('config', 'full_page'));

        $observer = new Observer();
        $observer->setEvent(new Event([
            'changed_paths' => [Data::XML_PATH_PRODUCT_CATEGORY_FILTERS],
        ]));

        $this->makeObserver()->execute($observer);
    }

    public function testDoesNotFlushFullPageCacheWhenUnrelatedPathChanged(): void
    {
        $this->primeScopeConfig(false, '', '');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $observer = new Observer();
        $observer->setEvent(new Event([
            'changed_paths' => [Data::XML_PATH_PAYMENT_TEXT],
        ]));

        $this->makeObserver()->execute($observer);
    }
}