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

namespace Comfino\Tests\Model\Webhook;

use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Backend\Store\Psr6TokenBucketStore;
use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\WebhookVerificationOutcome;
use Comfino\ComfinoGateway\Model\Webhook\FailedSignatureCounter;
use Comfino\ComfinoGateway\Model\Webhook\WebhookRateLimiter;
use Comfino\Tests\Support\ArrayCachePool;
use PHPUnit\Framework\TestCase;

final class WebhookGuardsTest extends TestCase
{
    public function testTheRateLimiterRejectsABurstAboveItsCapacityPerStoreAndCaller(): void
    {
        $limiter = new WebhookRateLimiter(new TokenBucketRateLimiter(3, 0.001, new Psr6TokenBucketStore(new ArrayCachePool())));
        $key = new RateLimitKey('transactionStatus', '2001:db8::1', 'store:1');

        $verdicts = array_map(static fn (): bool => $limiter->consume($key)->accepted, range(1, 4));

        $this->assertSame([true, true, true, false], $verdicts);
        $this->assertTrue($limiter->consume(new RateLimitKey('transactionStatus', '2001:db8::1', 'store:2'))->accepted);
    }

    public function testTheCounterCountsOnlyFailedVerificationsPerStore(): void
    {
        $counter = new FailedSignatureCounter(new ArrayCachePool());

        $counter->onVerification(WebhookVerificationOutcome::SignatureMismatch, 'store:1', '10.0.0.1');
        $counter->onVerification(WebhookVerificationOutcome::MissingSignature, 'store:1', null);
        $counter->onVerification(WebhookVerificationOutcome::Verified, 'store:1', '10.0.0.1');
        $counter->onVerification(WebhookVerificationOutcome::SignatureMismatch, 'store:2', '10.0.0.1');

        $this->assertSame(2, $counter->count('store:1'));
        $this->assertSame(1, $counter->count('store:2'));
        $this->assertSame(0, $counter->count('store:1', time() + FailedSignatureCounter::WINDOW_SECONDS + 1));
    }

    public function testTheCounterKeepsNoCallerAddress(): void
    {
        $pool = new ArrayCachePool();
        (new FailedSignatureCounter($pool))->onVerification(WebhookVerificationOutcome::SignatureMismatch, null, '203.0.113.9');

        $this->assertStringNotContainsString('203.0.113.9', serialize($pool->values));
    }
}
