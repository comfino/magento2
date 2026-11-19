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

namespace Comfino\Tests\Block;

use Comfino\Api\Client;
use Comfino\ComfinoGateway\Block\ComfinoLogo;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * ComfinoLogo::render() collects the admin logo <img> and the version data for its template. The block is built without
 * the Magento Field constructor and the SDK ApiClient singleton is replaced with a mock.
 *
 * The SDK Client class is final, so a real instance is built via newInstanceWithoutConstructor() with only customApiBaseUrl set.
 */
final class ComfinoLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install([]);

        /* getInstance() re-applies the API key / timeouts to the reused client and reads the SDK HTTP client; a plain
           PSR client (not TimeoutConfigurable) short-circuits the timeout re-application. */
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $this->createMock(ClientInterface::class));
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, null);
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     */
    public function testRendersLogoImageAndVersionLabel(): void
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $baseUrlProperty = (new ReflectionClass($client))->getProperty('customApiBaseUrl');
        $baseUrlProperty->setValue($client, 'https://api.example.test');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, $client);

        $helper = $this->createMock(Data::class);
        $helper->method('getShopVersion')->willReturn('2.4.8');
        $helper->method('getModuleVersion')->willReturn('4.0.0');

        $apiCredentials = $this->createMock(ScopedApiCredentials::class);
        $apiCredentials->method('resolveScope')->willReturn(['default', 0]);
        $apiCredentials->method('getCredentials')->willReturn([false, '', null]);
        $apiCredentials->method('getApiClient')->willReturn($client);

        $block = new class extends ComfinoLogo {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function __construct()
            {
            }

            public RequestInterface $request;

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }

            public function toHtml(): string
            {
                $this->captured = $this->getData();

                return '';
            }
        };
        $block->request = $this->createMock(RequestInterface::class);
        $block->request->method('getParam')->willReturn('');

        (new ReflectionProperty(ComfinoLogo::class, 'helper'))->setValue($block, $helper);
        (new ReflectionProperty(ComfinoLogo::class, 'apiCredentials'))->setValue($block, $apiCredentials);

        $block->render($this->createMock(AbstractElement::class));

        $logoHtml = (string) $block->captured['logo_html'];

        $this->assertStringContainsString('<img', $logoHtml);
        $this->assertStringContainsString('https://api.example.test', $logoHtml);
        $this->assertStringContainsString('ComfinoPay logo', $logoHtml);
        $this->assertStringContainsString('width: 300px', $logoHtml);
        // The template renders the version label from the module version.
        $this->assertSame('4.0.0', $block->captured['module_version']);
    }
}
