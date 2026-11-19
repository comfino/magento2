<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Logger\Handler
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Logger\Handler;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

/**
 * Daily rotated ComfinoPay log in var/log (last MAX_FILES days kept, owner-readable only). The admin log viewers find
 * the dated files by the base name.
 */
class RotatingFile extends RotatingFileHandler
{
    public const DEBUG_FILE_NAME = 'comfino_debug.log';
    public const ERROR_FILE_NAME = 'comfino_errors.log';
    public const MAX_FILES = 30;
    public const FILE_PERMISSION = 0600;

    /**
     * @throws FileSystemException
     */
    public function __construct(DirectoryList $directoryList, string $fileName = self::DEBUG_FILE_NAME)
    {
        parent::__construct(
            $directoryList->getPath(DirectoryList::LOG) . '/' . basename($fileName),
            self::MAX_FILES,
            Logger::DEBUG,
            true,
            self::FILE_PERMISSION
        );
    }
}
