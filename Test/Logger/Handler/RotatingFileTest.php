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

namespace Comfino\Tests\Logger\Handler;

use Comfino\Backend\Log\SensitiveDataProcessor;
use Comfino\ComfinoGateway\Logger\Handler\RotatingFile;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class RotatingFileTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/comfino_rotating_debug_' . bin2hex(random_bytes(4));

        mkdir($this->logDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        rmdir($this->logDir);

        parent::tearDown();
    }

    /**
     * @throws FileSystemException
     */
    public function testWritesADatedOwnerOnlyFileWithRedactedRecords(): void
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(DirectoryList::LOG)->willReturn($this->logDir);

        $logger = new Logger('comfino', [new RotatingFile($directoryList)], [new SensitiveDataProcessor(['telephone'])]);
        $logger->debug('event', ['email' => 'jan@example.com', 'telephone' => '+48 600 700 800', 'orderId' => '42']);

        $files = glob($this->logDir . '/comfino_debug-*.log') ?: [];

        $this->assertCount(1, $files);
        $this->assertSame(0600, fileperms($files[0]) & 0777);

        $content = (string) file_get_contents($files[0]);

        $this->assertStringNotContainsString('jan@example.com', $content);
        $this->assertStringNotContainsString('600 700 800', $content);
        $this->assertStringContainsString('"orderId":"42"', $content);
    }

    /**
     * @throws FileSystemException
     */
    public function testWritesTheGivenBaseName(): void
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->willReturn($this->logDir);

        (new Logger('comfino', [new RotatingFile($directoryList, RotatingFile::ERROR_FILE_NAME)]))->error('failure');

        $this->assertCount(1, glob($this->logDir . '/comfino_errors-*.log') ?: []);
    }
}
