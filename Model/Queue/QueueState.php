<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Queue;

use Magento\Framework\FlagManager;
use Throwable;

/**
 * What the queue drain (cron) leaves for the admin to see: dead-lettered requests and paused stores. Kept in Magento's
 * flag table, because the drain and the admin page run in different processes. Holds no payloads or error texts.
 */
class QueueState
{
    public const FLAG_CODE = 'comfino_queue_state';

    /** A store stays listed as paused this long after its last pause; the drain pauses it again while it still fails. */
    public const PAUSE_DISPLAY_SECONDS = 3600;

    /** Dead letters older than this are dropped from the summary. */
    public const DEAD_LETTER_WINDOW_SECONDS = 604800;

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    public function recordDeadLetter(string $operationType, ?int $now = null): void
    {
        $state = $this->load();
        $state['deadLetters'][] = ['operation' => $operationType, 'at' => $now ?? time()];

        $this->save($this->prune($state, $now ?? time()));
    }

    public function recordPause(?string $tenantKey, string $reason, ?int $now = null): void
    {
        $state = $this->load();
        $state['paused'][$tenantKey ?? ''] = ['reason' => $reason, 'at' => $now ?? time()];

        $this->save($this->prune($state, $now ?? time()));
    }

    /**
     * Stores paused within the display window.
     *
     * @return array<string, array{reason: string, at: int}> Keyed by tenant key ('' for requests without one)
     */
    public function getPausedTenants(?int $now = null): array
    {
        return $this->prune($this->load(), $now ?? time())['paused'];
    }

    /**
     * Dead-lettered requests within the last 7 days, counted per operation type.
     *
     * @return array<string, int>
     */
    public function getDeadLetterCounts(?int $now = null): array
    {
        $counts = [];

        foreach ($this->prune($this->load(), $now ?? time())['deadLetters'] as $deadLetter) {
            $counts[$deadLetter['operation']] = ($counts[$deadLetter['operation']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array{deadLetters: list<array{operation: string, at: int}>, paused: array<string, array{reason: string, at: int}>}
     */
    private function load(): array
    {
        try {
            $state = $this->flagManager->getFlagData(self::FLAG_CODE);
        } catch (Throwable) {
            $state = null;
        }

        $deadLetters = [];
        $paused = [];

        foreach (is_array($state['deadLetters'] ?? null) ? $state['deadLetters'] : [] as $deadLetter) {
            if (is_array($deadLetter) && isset($deadLetter['operation'], $deadLetter['at'])) {
                $deadLetters[] = ['operation' => (string) $deadLetter['operation'], 'at' => (int) $deadLetter['at']];
            }
        }

        foreach (is_array($state['paused'] ?? null) ? $state['paused'] : [] as $tenantKey => $pause) {
            if (is_array($pause) && isset($pause['reason'], $pause['at'])) {
                $paused[(string) $tenantKey] = ['reason' => (string) $pause['reason'], 'at' => (int) $pause['at']];
            }
        }

        return ['deadLetters' => $deadLetters, 'paused' => $paused];
    }

    /**
     * @param array{deadLetters: list<array{operation: string, at: int}>, paused: array<string, array{reason: string, at: int}>} $state
     *
     * @return array{deadLetters: list<array{operation: string, at: int}>, paused: array<string, array{reason: string, at: int}>}
     */
    private function prune(array $state, int $now): array
    {
        $state['deadLetters'] = array_values(array_filter(
            $state['deadLetters'],
            static fn (array $deadLetter): bool => $deadLetter['at'] >= $now - self::DEAD_LETTER_WINDOW_SECONDS
        ));
        $state['paused'] = array_filter(
            $state['paused'],
            static fn (array $pause): bool => $pause['at'] >= $now - self::PAUSE_DISPLAY_SECONDS
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        try {
            $this->flagManager->saveFlag(self::FLAG_CODE, $state);
        } catch (Throwable) {
            // Visibility is best-effort: the drain must not fail because the flag table cannot be written.
        }
    }
}
