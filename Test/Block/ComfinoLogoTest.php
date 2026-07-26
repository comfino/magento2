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

namespace Comfino\Tests\Block;

use Comfino\Api\Client;
use Comfino\ComfinoGateway\Block\ComfinoLogo;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * ComfinoLogo::render() builds an admin logo <img> (via the SDK FrontendHelper) using the API base URL plus shop and
 * module version metadata, then appends a styled version label. The block is built via newInstanceWithoutConstructor()
 * to skip the Magento Field constructor; the SDK ApiClient singleton is replaced with a mock Client, so no API client
 * is bootstrapped, and the Data helper is mocked for the version strings.
 *
 * The SDK Client class is final and cannot be doubled, so a real instance is built via
 * newInstanceWithoutConstructor() with only its customApiBaseUrl property set (read by getApiBaseUrl()).
 */
final class ComfinoLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install([]);

        /* getInstance() re-applies the API key / timeouts to the reused client and reads the SDK HTTP client; a plain
           PSR client (not TimeoutAware) short-circuits the timeout re-application. */
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $this->createMock(ClientInterface::class));
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, null);
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testRendersLogoImageAndVersionLabel(): void
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $baseUrlProperty = (new ReflectionClass($client))->getProperty('customApiBaseUrl');
        $baseUrlProperty->setValue($client, 'https://api.example.test');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, $client);

        $helper = $this->createMock(Data::class);
        $helper->method('getShopVersion')->willReturn('2.4.8');
        $helper->method('getModuleVersion')->willReturn('4.0.0');

        $block = (new ReflectionClass(ComfinoLogo::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ComfinoLogo::class, 'helper'))->setValue($block, $helper);

        $html = $block->render($this->createMock(AbstractElement::class));

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://api.example.test', $html);
        $this->assertStringContainsString('Comfino logo', $html);
        $this->assertStringContainsString('width: 300px', $html);
        /* The trailing version label uses the module version. */
        $this->assertStringContainsString('>4.0.0</span>', $html);
    }
}