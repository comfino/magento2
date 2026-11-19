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

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ApiKeyCrypt;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Encrypts the production and test environment API keys stored in plain text by earlier versions, in every scope. A value
 * that is already encrypted is left alone, so running it again changes nothing. After a later `bin/magento encryption:key:change`
 * Magento re-encrypts the values itself.
 */
class EncryptComfinoApiKeys implements DataPatchInterface
{
    public const KEY_PATHS = [Data::XML_PATH_API_KEY, Data::XML_PATH_SANDBOX_API_KEY];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $connection->startSetup();

        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['config_id', 'value'])->where('path IN (?)', self::KEY_PATHS)
        );

        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '' || ApiKeyCrypt::isEncrypted($value)) {
                continue;
            }

            $connection->update(
                $table,
                ['value' => $this->encryptor->encrypt($value)],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }

        $connection->endSetup();

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
