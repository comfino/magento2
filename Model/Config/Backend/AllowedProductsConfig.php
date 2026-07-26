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

use Comfino\Backend\Payment\AllowedProductsConfigValidator;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Backend model for `payment/comfino/allowed_products_config`.
 *
 * Validates the raw JSON textarea on save: rejects unknown product types, non-array shape,
 * non-positive `terms`, and `minTerm > maxTerm`. On success, normalizes to canonical JSON
 * (omits null/empty fields), so the saved DB value matches the php-sdk builder's expectations.
 *
 * The generic validation and canonicalization live in the SDK {@see AllowedProductsConfigValidator} (shared with every
 * Comfino plugin and kept in sync with the SDK config builder); this backend model only gates on the feature flag and
 * maps the validator's stable failure keys to translated, admin-facing messages.
 *
 * @method array getFieldConfig()
 */
class AllowedProductsConfig extends Value
{
    /**
     * @throws LocalizedException
     */
    public function beforeSave(): static
    {
        if (!$this->isFeatureEnabled()) {
            $this->setValue($this->getOldValue());

            return parent::beforeSave();
        }

        $result = (new AllowedProductsConfigValidator())->validateAndNormalize((string) $this->getValue());

        if ($result['valid'] === false) {
            $error = $result['errors'][0];

            throw new LocalizedException($this->buildErrorMessage($error['key'], $error['params']));
        }

        $this->setValue($result['normalizedJson']);

        return parent::beforeSave();
    }

    protected function isFeatureEnabled(): bool
    {
        return (bool) ConfigManager::getConfigurationValue('COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED');
    }

    /**
     * Maps a validator failure key + interpolation params to a translated, admin-facing message.
     *
     * @param array<int, mixed> $params
     */
    private function buildErrorMessage(string $key, array $params): Phrase
    {
        return match ($key) {
            AllowedProductsConfigValidator::ERROR_INVALID_JSON =>
                __('Installment term limits: invalid JSON (%1).', ...$params),
            AllowedProductsConfigValidator::ERROR_NOT_ARRAY =>
                __('Installment term limits: top-level value must be a JSON array of entries.'),
            AllowedProductsConfigValidator::ERROR_ENTRY_NOT_OBJECT =>
                __('Installment term limits: entry #%1 must be an object.', ...$params),
            AllowedProductsConfigValidator::ERROR_MISSING_TYPE =>
                __('Installment term limits: entry #%1 is missing the required "type" field.', ...$params),
            AllowedProductsConfigValidator::ERROR_UNKNOWN_TYPE =>
                __('Installment term limits: entry #%1 has an unknown product type "%2".', ...$params),
            AllowedProductsConfigValidator::ERROR_FIELD_NOT_POSITIVE_INT =>
                __('Installment term limits: entry #%1 "%2" must be a positive integer.', ...$params),
            AllowedProductsConfigValidator::ERROR_MIN_TERM_GREATER_THAN_MAX_TERM =>
                __(
                    'Installment term limits: entry #%1 ("%2") has minTerm (%3) greater than maxTerm (%4).',
                    ...$params
                ),
            AllowedProductsConfigValidator::ERROR_TERMS_NOT_ARRAY =>
                __(
                    'Installment term limits: entry #%1 ("%2") "terms" must be a JSON array of positive integers.',
                    ...$params
                ),
            AllowedProductsConfigValidator::ERROR_TERM_NOT_POSITIVE_INT =>
                __('Installment term limits: entry #%1 ("%2") terms[%3] must be a positive integer.', ...$params),
            default => __('Installment term limits: invalid configuration.'),
        };
    }
}
