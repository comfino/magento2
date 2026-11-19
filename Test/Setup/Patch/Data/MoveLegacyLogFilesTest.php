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

namespace Comfino\Tests\Setup\Patch\Data;

use Comfino\ComfinoGateway\Setup\Patch\Data\MoveLegacyLogFiles;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;

final class MoveLegacyLogFilesTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/comfino_legacy_log_' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        rmdir($this->logDir);

        parent::tearDown();
    }

    public function testRenamesTheSingleLogFilesAndRestrictsThem(): void
    {
        file_put_contents($this->logDir . '/comfino_debug.log', "old entry\n");
        file_put_contents($this->logDir . '/comfino_errors.log', "old error\n");

        $this->createPatch()->apply();

        $this->assertFileDoesNotExist($this->logDir . '/comfino_debug.log');
        $this->assertFileDoesNotExist($this->logDir . '/comfino_errors.log');
        $this->assertSame("old entry\n", file_get_contents($this->logDir . '/comfino_debug-legacy.log'));
        $this->assertSame("old error\n", file_get_contents($this->logDir . '/comfino_errors-legacy.log'));
        $this->assertSame(0600, fileperms($this->logDir . '/comfino_debug-legacy.log') & 0777);
    }

    public function testDoesNothingWithoutALegacyFile(): void
    {
        $this->createPatch()->apply();

        $this->assertSame([], glob($this->logDir . '/*'));
    }

    public function testKeepsAnEarlierMovedFile(): void
    {
        file_put_contents($this->logDir . '/comfino_debug.log', "new\n");
        file_put_contents($this->logDir . '/comfino_debug-legacy.log', "moved before\n");

        $this->createPatch()->apply();

        $this->assertSame("moved before\n", file_get_contents($this->logDir . '/comfino_debug-legacy.log'));
        $this->assertFileExists($this->logDir . '/comfino_debug.log');
    }

    private function createPatch(): MoveLegacyLogFiles
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(DirectoryList::LOG)->willReturn($this->logDir);

        return new MoveLegacyLogFiles($directoryList, new File());
    }
}
