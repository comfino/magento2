<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Config\Backend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/** @method array getFieldConfig() */
class NumericField extends Value
{
    /**
     * @throws LocalizedException
     */
    public function beforeSave(): static
    {
        $value = trim((string) $this->getValue());
        $label = $this->getFieldConfig()['label'] ?? $this->getPath();

        if ($value === '') {
            throw new LocalizedException(__('"%1" cannot be empty.', $label));
        }

        if (!is_numeric($value)) {
            throw new LocalizedException(__('"%1" must be a numeric value.', $label));
        }

        return parent::beforeSave();
    }
}
