<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Http
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Http;

use GuzzleHttp\Psr7\HttpFactory as GuzzleHttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 factory adapter registered in Magento's DI compilation scope.
 *
 * Magento's DI compiler only includes classes from registered Magento modules; plain vendor library classes
 * (including GuzzleHttp\Psr7\HttpFactory which is also final) are excluded. This wrapper lives inside the
 * comfino/magento2 module and delegates to GuzzleHttp\Psr7\HttpFactory via composition.
 */
class Psr17Factory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UriFactoryInterface
{
    private GuzzleHttpFactory $factory;

    public function __construct()
    {
        $this->factory = new GuzzleHttpFactory();
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return $this->factory->createResponse($code, $reasonPhrase);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return $this->factory->createServerRequest($method, $uri, $serverParams);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->factory->createStreamFromResource($resource);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return $this->factory->createUri($uri);
    }
}
