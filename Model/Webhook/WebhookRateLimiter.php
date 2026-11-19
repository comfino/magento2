<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Webhook;

use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\RateLimitVerdict;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;
use Throwable;

/**
 * Token-bucket rate limit per store, endpoint and caller address, kept in the Magento cache so it holds across workers.
 * A limiter that cannot reach its store accepts the request: the signature check is the control that matters.
 */
class WebhookRateLimiter implements TenantAwareRateLimiterInterface
{
    public const CAPACITY = 30;
    public const REFILL_PER_SECOND = 0.5;

    public function __construct(private readonly TokenBucketRateLimiter $limiter)
    {
    }

    public function consume(RateLimitKey $key, int $tokens = 1): RateLimitVerdict
    {
        try {
            $reservation = $this->limiter->reserve(self::storageKey($key), $tokens);
        } catch (Throwable) {
            return RateLimitVerdict::accept();
        }

        return $reservation->accepted
            ? RateLimitVerdict::accept($reservation->remaining, self::CAPACITY)
            : RateLimitVerdict::reject((int) ceil($reservation->retryAfterMs / 1000), self::CAPACITY);
    }

    /**
     * A cache-safe key: PSR-6 keys may not contain ":" (store tenant keys and IPv6 addresses do).
     */
    private static function storageKey(RateLimitKey $key): string
    {
        return 'comfino_webhook_rl_' . sha1($key->toStorageKey());
    }
}
