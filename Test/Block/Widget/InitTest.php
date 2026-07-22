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

namespace Comfino\Tests\Block\Widget;

use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\ComfinoGateway\Block\Widget\Init;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Init emits the product-page widget as a `#comfino-widget-config` JSON block + a per-platform CDN script
 * (WIDGET_BRIDGE_MIGRATION_PLAN §3). The block extends Template (a heavy constructor), so it is built via an
 * anonymous subclass with a no-op constructor and the three collaborators injected by reflection. Widget
 * enabled/key state is driven through the ConfigManager harness; SettingsManager's static caches are reset
 * between tests.
 *
 * getWidgetConfigJson()'s populated-JSON path pulls the full runtime payload through Magento's ObjectManager
 * (StoreManager, shop-environment builder, API client), which is out of scope for this unit harness — it is
 * exercised end-to-end elsewhere. Here we cover the suppression guards and the CDN script-URL resolution.
 */
final class InitTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private ProductRepositoryInterface&MockObject $productRepository;
    private OrderManager&MockObject $orderManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->orderManager = $this->createMock(OrderManager::class);
    }

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();
        (new ReflectionProperty(SettingsManager::class, 'filterManagers'))->setValue(null, []);
        ProductTypeFilterManager::reset();

        parent::tearDown();
    }

    private function makeBlock(): Init
    {
        $block = new class extends Init {
            public function __construct()
            {
            }

            public function getUrl($route = '', $params = [])
            {
                return 'http://shop.test/' . $route . '?product_id=' . ($params['product_id'] ?? '');
            }
        };

        foreach (
            [
                'request' => $this->request,
                'productRepository' => $this->productRepository,
                'orderManager' => $this->orderManager,
            ] as $name => $value
        ) {
            (new ReflectionProperty(Init::class, $name))->setValue($block, $value);
        }

        return $block;
    }

    public function testReturnsEmptyConfigWhenWidgetDisabled(): void
    {
        ConfigManagerHarness::install(['COMFINO_WIDGET_ENABLED' => false, 'COMFINO_WIDGET_KEY' => 'k']);

        $this->assertSame('', $this->makeBlock()->getWidgetConfigJson());
    }

    public function testReturnsEmptyConfigWhenWidgetKeyMissing(): void
    {
        ConfigManagerHarness::install(['COMFINO_WIDGET_ENABLED' => true, 'COMFINO_WIDGET_KEY' => '']);

        $this->assertSame('', $this->makeBlock()->getWidgetConfigJson());
    }

    public function testProductWidgetScriptUrlPointsAtPerPlatformBundle(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false]);

        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-magento-widget.min.js',
            $this->makeBlock()->getProductWidgetScriptUrl()
        );
    }

    public function testProductWidgetScriptUrlUsesSandboxHostInSandboxMode(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => true]);

        $this->assertSame(
            'https://sdk.craty.pl/product/v1/comfino-magento-widget.min.js',
            $this->makeBlock()->getProductWidgetScriptUrl()
        );
    }

    public function testInitConstructorInjectsCollaborators(): void
    {
        /* Smoke-checks that the readonly promoted properties exist with the expected names so the
           newInstanceWithoutConstructor() injection above stays valid if the constructor is refactored. */
        $class = new ReflectionClass(Init::class);

        foreach (['request', 'productRepository', 'orderManager'] as $name) {
            $this->assertTrue($class->hasProperty($name), "Init must declare property $name");
        }
    }
}