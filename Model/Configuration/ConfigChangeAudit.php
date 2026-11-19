<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Comfino\Backend\Configuration\ConfigurationChangeObserverInterface;
use Comfino\Backend\Configuration\ConfigurationChangeSource;
use Magento\Framework\FlagManager;
use Throwable;

/**
 * Append-only record of settings changed by the remote configuration endpoint (when, option, before/after, scope); the
 * last MAX_ENTRIES are kept in the flag table. Admin panel changes are not recorded.
 */
class ConfigChangeAudit implements ConfigurationChangeObserverInterface
{
    public const FLAG_CODE = 'comfino_config_change_audit';
    public const MAX_ENTRIES = 100;
    private const MAX_VALUE_LENGTH = 200;

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    public function onConfigurationChanged(array $changes, ConfigurationChangeSource $source, ?string $tenantKey): void
    {
        if ($source !== ConfigurationChangeSource::RemoteEndpoint || $changes === []) {
            return;
        }

        $entry = ['at' => time(), 'scope' => $tenantKey ?? 'default', 'changes' => []];

        foreach ($changes as $optionName => $change) {
            $entry['changes'][$optionName] = [
                'before' => self::describe($change['before'] ?? null),
                'after' => self::describe($change['after'] ?? null),
            ];
        }

        $entries = $this->getEntries();
        $entries[] = $entry;

        try {
            $this->flagManager->saveFlag(self::FLAG_CODE, array_slice($entries, -self::MAX_ENTRIES));
        } catch (Throwable) {
            // The audit record must not break the configuration write.
        }
    }

    /**
     * @return list<array{at: int, scope: string, changes: array<string, array{before: string, after: string}>}>
     */
    public function getEntries(): array
    {
        try {
            $entries = $this->flagManager->getFlagData(self::FLAG_CODE);
        } catch (Throwable) {
            return [];
        }

        $valid = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && isset($entry['at'], $entry['scope'], $entry['changes']) && is_array($entry['changes'])) {
                $valid[] = ['at' => (int) $entry['at'], 'scope' => (string) $entry['scope'], 'changes' => $entry['changes']];
            }
        }

        return $valid;
    }

    private static function describe(mixed $value): string
    {
        if (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $text = 'null';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH) . '…' : $text;
    }
}
