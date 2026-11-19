<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block\Payment;

use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Ui\ConfigProvider;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

/**
 * Promotion hint data for the paywall bootstrap (Luma and Hyvä). The unfiltered flags map goes into its own <script
 * type="application/json"> element with HTML-significant characters escaped, never into window.checkoutConfig.
 */
class RemoteFlags extends Template
{
    public const ELEMENT_ID = 'comfino-remote-flags';

    /**
     * JSON of {flags, flagAttributes}, or '' when the shop has no flags or the payment method is off.
     */
    public function getFlagsJson(): string
    {
        if (!$this->_scopeConfig->isSetFlag(ConfigProvider::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE)) {
            return '';
        }

        return self::encode(SettingsManager::getCheckoutFlags());
    }

    /**
     * @param array{flags: string[]|null, flagAttributes: array<string, mixed>|null} $checkoutFlags
     */
    public static function encode(array $checkoutFlags): string
    {
        if ($checkoutFlags['flags'] === null && $checkoutFlags['flagAttributes'] === null) {
            return '';
        }

        $json = json_encode($checkoutFlags, Comfino::SCRIPT_JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? '' : $json;
    }
}
