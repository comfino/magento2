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

namespace Magento\Framework\Controller\Result {
    /* DI-generated factory not shipped in vendor; define a stand-in so the constructor type-hint resolves.
       Tests inject a mock that overrides create(). */
    if (!class_exists(RawFactory::class, false)) {
        class RawFactory
        {
            public function create(): Raw
            {
                throw new \LogicException('stub');
            }
        }
    }
}

namespace Comfino\Tests\Controller {

    use Comfino\ComfinoGateway\Controller\AbstractApiEndpoint;
    use Magento\Framework\Controller\Result\Raw;
    use Magento\Framework\Controller\Result\RawFactory;
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\StreamInterface;
    use ReflectionMethod;

    /**
     * prepareResult() copies status, every header value, and the body from a PSR-7 response into a Magento Raw result.
     * When the PSR body is empty it falls back to the reason phrase. Exercised through a concrete subclass that
     * exposes the protected method.
     */
    final class AbstractApiEndpointTest extends TestCase
    {
        private function makeFactory(Raw $raw): RawFactory
        {
            $factory = $this->createMock(RawFactory::class);
            $factory->method('create')->willReturn($raw);

            return $factory;
        }

        private function invoke(AbstractApiEndpoint $endpoint, ResponseInterface $response): Raw
        {
            $method = new ReflectionMethod($endpoint, 'prepareResult');

            return $method->invoke($endpoint, $response);
        }

        private function makeEndpoint(RawFactory $factory): AbstractApiEndpoint
        {
            return new class ($factory) extends AbstractApiEndpoint {
            };
        }

        private function makeResponse(int $status, string $body, string $reason, array $headers): ResponseInterface
        {
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('getContents')->willReturn($body);

            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($status);
            $response->method('getReasonPhrase')->willReturn($reason);
            $response->method('getHeaders')->willReturn($headers);
            $response->method('getBody')->willReturn($stream);

            return $response;
        }

        public function testCopiesStatusHeadersAndBody(): void
        {
            $capturedHeaders = [];
            $capturedContents = null;
            $capturedCode = null;

            $raw = $this->createMock(Raw::class);
            $raw->method('setHttpResponseCode')->willReturnCallback(
                function ($code) use (&$capturedCode, $raw): Raw {
                    $capturedCode = $code;

                    return $raw;
                }
            );
            $raw->method('setHeader')->willReturnCallback(
                function ($name, $value) use (&$capturedHeaders, $raw): Raw {
                    $capturedHeaders[] = [$name, $value];

                    return $raw;
                }
            );
            $raw->method('setContents')->willReturnCallback(
                function ($contents) use (&$capturedContents, $raw): Raw {
                    $capturedContents = $contents;

                    return $raw;
                }
            );

            $response = $this->makeResponse(
                201,
                '{"ok":true}',
                'Created',
                ['Content-Type' => ['application/json'], 'X-Multi' => ['a', 'b']]
            );

            $result = $this->invoke($this->makeEndpoint($this->makeFactory($raw)), $response);

            $this->assertSame($raw, $result);
            $this->assertSame(201, $capturedCode);
            $this->assertSame('{"ok":true}', $capturedContents);
            $this->assertContains(['Content-Type', 'application/json'], $capturedHeaders);
            $this->assertContains(['X-Multi', 'a'], $capturedHeaders);
            $this->assertContains(['X-Multi', 'b'], $capturedHeaders);
        }

        public function testFallsBackToReasonPhraseWhenBodyEmpty(): void
        {
            $captured = null;

            $raw = $this->createMock(Raw::class);
            $raw->method('setHttpResponseCode')->willReturnSelf();
            $raw->method('setHeader')->willReturnSelf();
            $raw->method('setContents')->willReturnCallback(
                function ($contents) use (&$captured, $raw): Raw {
                    $captured = $contents;

                    return $raw;
                }
            );

            $response = $this->makeResponse(404, '', 'Not Found', []);

            $this->invoke($this->makeEndpoint($this->makeFactory($raw)), $response);

            $this->assertSame('Not Found', $captured);
        }
    }
}