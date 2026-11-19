<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Http
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Http;

use Comfino\ComfinoGateway\Http\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Confirms every PSR-17 method delegates to GuzzleHttp\Psr7\HttpFactory and returns the expected message type.
 */
final class Psr17FactoryTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Psr17Factory();
    }

    public function testCreateRequest(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example/orders');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.example/orders', (string) $request->getUri());
    }

    public function testCreateResponse(): void
    {
        $response = $this->factory->createResponse(201, 'Created');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getReasonPhrase());
    }

    public function testCreateServerRequest(): void
    {
        $serverRequest = $this->factory->createServerRequest('POST', 'https://api.example/hook', ['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame('POST', $serverRequest->getMethod());
        $this->assertSame(['REMOTE_ADDR' => '1.2.3.4'], $serverRequest->getServerParams());
    }

    public function testCreateStream(): void
    {
        $this->assertSame('payload', (string) $this->factory->createStream('payload'));
    }

    public function testCreateStreamFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'comfino_psr17_');

        file_put_contents($path, 'from-file');

        try {
            $this->assertSame('from-file', (string) $this->factory->createStreamFromFile($path, 'r'));
        } finally {
            @unlink($path);
        }
    }

    public function testCreateStreamFromResource(): void
    {
        $fh = fopen('php://temp', 'rb+');

        fwrite($fh, 'from-resource');
        rewind($fh);

        $this->assertSame('from-resource', (string) $this->factory->createStreamFromResource($fh));
    }

    public function testCreateUri(): void
    {
        $uri = $this->factory->createUri('https://api.example/path?x=1');

        $this->assertSame('api.example', $uri->getHost());
        $this->assertSame('/path', $uri->getPath());
    }
}
