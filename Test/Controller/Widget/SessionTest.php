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

namespace Comfino\Tests\Controller\Widget;

use Comfino\ComfinoGateway\Controller\Widget\Session;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SessionTest extends TestCase
{
    /** @var array<string, string> */
    private array $headers = [];
    private mixed $data = null;

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        parent::tearDown();
    }

    /**
     * @throws NotFoundException
     */
    private function execute(): void
    {
        $result = $this->createMock(Json::class);
        $result->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($result): Json {
            $this->headers[$name] = $value;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function (mixed $data) use ($result): Json {
            $this->data = $data;

            return $result;
        });

        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);

        (new Session($factory))->execute();
    }

    /**
     * @throws NotFoundException
     */
    public function testIsNeverCached(): void
    {
        ConfigManagerHarness::install(['COMFINO_WIDGET_ENABLED' => false]);

        $this->execute();

        $this->assertStringContainsString('no-store', $this->headers['Cache-Control']);
        $this->assertStringContainsString('private', $this->headers['Cache-Control']);
        $this->assertSame([], $this->data);
    }

    /**
     * @throws NotFoundException
     */
    public function testOmitsValuesThatCannotBeProduced(): void
    {
        // No error-logging token claimed yet and no API client: neither value can be produced, and none is invented.
        ConfigManagerHarness::install(['COMFINO_WIDGET_ENABLED' => true, 'COMFINO_WIDGET_KEY' => 'widget-key']);

        $this->execute();

        $this->assertSame([], $this->data);
    }
}
