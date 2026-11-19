<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Config\Backend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Config\Backend;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ApiKeyCrypt;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\LocalizedException;

/**
 * Masked, encrypted API key field: submitting the "******" placeholder keeps the saved key. The production key is
 * required; an empty sandbox key also keeps the saved one, as removing it takes the explicit remove switch.
 *
 * @method array getFieldConfig()
 */
class ApiKey extends Encrypted
{
    /**
     * @return void
     *
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        $this->setValue($value);

        if ($value === '') {
            if ($this->getPath() === Data::XML_PATH_API_KEY) {
                throw new LocalizedException(__('"%1" cannot be empty.', $this->getFieldConfig()['label'] ?? $this->getPath()));
            }

            // Keep the saved key (see the class comment).
            $this->setValue('******');
        } elseif (preg_match('/^\*+$/', $value) !== 1) {
            ReplacedApiKeys::remember(
                (string) $this->getPath(),
                (string) $this->getScope(),
                (int) $this->getScopeId(),
                (string) $this->getOldValue()
            );
        }

        parent::beforeSave();
    }

    /**
     * Decrypts a stored value; a key stored before encryption was introduced is returned as is.
     *
     * @param string $value
     */
    public function processValue($value)
    {
        return ApiKeyCrypt::decrypt($value, $this->_encryptor);
    }

    /**
     * @return void
     */
    protected function _afterLoad()
    {
        $this->setValue(ApiKeyCrypt::decrypt($this->getValue(), $this->_encryptor));
    }
}
