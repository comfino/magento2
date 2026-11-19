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

use Comfino\Backend\Webhook\WebhookVerificationObserverInterface;
use Comfino\Backend\Webhook\WebhookVerificationOutcome;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Counts webhook requests per store whose signature did not verify over the last
 * WINDOW_SECONDS, for the diagnostics. Only timestamps are kept.
 */
class FailedSignatureCounter implements WebhookVerificationObserverInterface
{
    public const WINDOW_SECONDS = 86400;
    private const MAX_ENTRIES = 1000;
    private const FAILED_OUTCOMES = [
        WebhookVerificationOutcome::MissingSignature,
        WebhookVerificationOutcome::SignatureMismatch,
        WebhookVerificationOutcome::UnknownTenant,
    ];

    public function __construct(private readonly CacheItemPoolInterface $cachePool)
    {
    }

    public function onVerification(WebhookVerificationOutcome $outcome, ?string $tenantKey, ?string $clientIp): void
    {
        if (!in_array($outcome, self::FAILED_OUTCOMES, true)) {
            return;
        }

        try {
            $item = $this->cachePool->getItem(self::cacheKey($tenantKey));
            $now = time();
            $timestamps = self::recent(is_array($item->get()) ? $item->get() : [], $now);
            $timestamps[] = $now;

            $item->set(array_slice($timestamps, -self::MAX_ENTRIES));
            $item->expiresAfter(self::WINDOW_SECONDS);
            $this->cachePool->save($item);
        } catch (Throwable) {
            // Counting must not affect the webhook response.
        }
    }

    /**
     * Number of failed verifications for the store within the window.
     */
    public function count(?string $tenantKey, ?int $now = null): int
    {
        try {
            $timestamps = $this->cachePool->getItem(self::cacheKey($tenantKey))->get();
        } catch (Throwable) {
            return 0;
        }

        return count(self::recent(is_array($timestamps) ? $timestamps : [], $now ?? time()));
    }

    /**
     * @param array<int|string, mixed> $timestamps
     *
     * @return list<int>
     */
    private static function recent(array $timestamps, int $now): array
    {
        return array_values(array_filter(
            array_map('intval', $timestamps),
            static fn (int $timestamp): bool => $timestamp > $now - self::WINDOW_SECONDS
        ));
    }

    private static function cacheKey(?string $tenantKey): string
    {
        return 'comfino_webhook_failed_signatures_' . sha1($tenantKey ?? '-');
    }
}
