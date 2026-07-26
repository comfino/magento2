<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Http
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Http;

use Comfino\ComfinoGateway\Http\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * The factory is a thin composition wrapper over GuzzleHttp\Psr7\HttpFactory; these tests confirm every PSR-17 method
 * delegates and returns the expected message type.
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

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.example/orders', (string) $request->getUri());
    }

    public function testCreateResponse(): void
    {
        $response = $this->factory->createResponse(201, 'Created');

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getReasonPhrase());
    }

    public function testCreateServerRequest(): void
    {
        $serverRequest = $this->factory->createServerRequest('POST', 'https://api.example/hook', ['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertInstanceOf(ServerRequestInterface::class, $serverRequest);
        $this->assertSame('POST', $serverRequest->getMethod());
        $this->assertSame(['REMOTE_ADDR' => '1.2.3.4'], $serverRequest->getServerParams());
    }

    public function testCreateStream(): void
    {
        $stream = $this->factory->createStream('payload');

        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('payload', (string) $stream);
    }

    public function testCreateStreamFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'comfino_psr17_');
        file_put_contents($path, 'from-file');

        try {
            $stream = $this->factory->createStreamFromFile($path, 'r');

            $this->assertInstanceOf(StreamInterface::class, $stream);
            $this->assertSame('from-file', (string) $stream);
        } finally {
            @unlink($path);
        }
    }

    public function testCreateStreamFromResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'from-resource');
        rewind($resource);

        $stream = $this->factory->createStreamFromResource($resource);

        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('from-resource', (string) $stream);
    }

    public function testCreateUri(): void
    {
        $uri = $this->factory->createUri('https://api.example/path?x=1');

        $this->assertInstanceOf(UriInterface::class, $uri);
        $this->assertSame('api.example', $uri->getHost());
        $this->assertSame('/path', $uri->getPath());
    }
}