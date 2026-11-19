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

namespace Comfino\Tests\Model\Configuration;

use Comfino\Backend\Configuration\ConfigurationChangeSource;
use Comfino\ComfinoGateway\Model\Configuration\ConfigChangeAudit;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

final class ConfigChangeAuditTest extends TestCase
{
    private mixed $flagData = null;

    private function createAudit(): ConfigChangeAudit
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (): mixed => $this->flagData);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, mixed $value): bool {
            $this->flagData = $value;

            return true;
        });

        return new ConfigChangeAudit($flagManager);
    }

    public function testRecordsRemoteChangesWithTheScopeAndBothValues(): void
    {
        $audit = $this->createAudit();

        $audit->onConfigurationChanged(
            ['COMFINO_DEBUG' => ['before' => false, 'after' => true], 'COMFINO_STATUS_MAP' => ['before' => null, 'after' => ['ACCEPTED' => 'comfino_accepted']]],
            ConfigurationChangeSource::RemoteEndpoint,
            'default'
        );

        $entries = $audit->getEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('default', $entries[0]['scope']);
        $this->assertSame(['before' => 'false', 'after' => 'true'], $entries[0]['changes']['COMFINO_DEBUG']);
        $this->assertSame(['before' => 'null', 'after' => '{"ACCEPTED":"comfino_accepted"}'], $entries[0]['changes']['COMFINO_STATUS_MAP']);
    }

    public function testIgnoresProgrammaticChanges(): void
    {
        $audit = $this->createAudit();

        /* Programmatic writes include the error-reporting token, which must never be recorded. */
        $audit->onConfigurationChanged(
            ['COMFINO_ERROR_LOGGING_ACCESS_TOKEN' => ['before' => 'a', 'after' => 'secret']],
            ConfigurationChangeSource::Programmatic,
            'default'
        );

        $this->assertSame([], $audit->getEntries());
    }

    public function testKeepsTheLastHundredEntriesAndCapsLongValues(): void
    {
        $audit = $this->createAudit();

        for ($i = 1; $i <= ConfigChangeAudit::MAX_ENTRIES + 5; $i++) {
            $audit->onConfigurationChanged(
                ['COMFINO_PAYMENT_TEXT' => ['before' => (string) ($i - 1), 'after' => str_repeat('x', 300)]],
                ConfigurationChangeSource::RemoteEndpoint,
                null
            );
        }

        $entries = $audit->getEntries();

        $this->assertCount(ConfigChangeAudit::MAX_ENTRIES, $entries);
        $this->assertSame('5', $entries[0]['changes']['COMFINO_PAYMENT_TEXT']['before']);
        $this->assertSame(201, mb_strlen($entries[0]['changes']['COMFINO_PAYMENT_TEXT']['after']));
    }
}
