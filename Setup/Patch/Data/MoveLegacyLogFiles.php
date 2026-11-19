<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup\Patch\Data
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Setup\Patch\Data;

use Comfino\ComfinoGateway\Logger\Handler\RotatingFile;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Throwable;

/**
 * Renames the single log files of earlier versions (var/log/comfino_debug.log, comfino_errors.log) to "<name>-legacy.log" and
 * makes them readable by the owner only. The logs are now written to daily files, and the admin log viewers read the undated file
 * first while it exists, so an old file would hide the new entries. The viewers' clear buttons still remove the renamed files.
 */
class MoveLegacyLogFiles implements DataPatchInterface
{
    public const LEGACY_SUFFIX = '-legacy';

    public function __construct(private readonly DirectoryList $directoryList, private readonly File $fileDriver)
    {
    }

    public function apply(): self
    {
        foreach ([RotatingFile::DEBUG_FILE_NAME, RotatingFile::ERROR_FILE_NAME] as $fileName) {
            try {
                $logDir = $this->directoryList->getPath(DirectoryList::LOG);
                $legacyFile = $logDir . '/' . $fileName;
                $movedFile = $logDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . self::LEGACY_SUFFIX . '.log';

                if ($this->fileDriver->isExists($legacyFile) && !$this->fileDriver->isExists($movedFile)) {
                    $this->fileDriver->rename($legacyFile, $movedFile);
                    $this->fileDriver->changePermissions($movedFile, RotatingFile::FILE_PERMISSION);
                }
            } catch (Throwable) {
                // A log file that cannot be moved must not stop the upgrade.
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
