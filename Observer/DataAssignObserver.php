<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\Shop\Order\PromoCode;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class DataAssignObserver extends AbstractDataAssignObserver
{
    public const PAYMENT_LOAN_TYPE = 'loanType';
    public const PAYMENT_LOAN_TERM = 'loanTerm';
    public const PAYMENT_PROMO_CODE = 'promoCode';
    public const PAYMENT_TRACK_ID = 'trackId';

    public function execute(Observer $observer): void
    {
        $data = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        $paymentInfo = $this->readPaymentModelArgument($observer);

        if (!is_array($additionalData)) {
            return;
        }

        /* Only structurally invalid values are dropped here. Whether the product is offered for the cart is decided when
           the order is submitted (LoanSelection::resolve()), which falls back to a safe default product. */
        $loanType = LoanSelection::sanitizeLoanType($additionalData[self::PAYMENT_LOAN_TYPE] ?? null);
        $loanTerm = LoanSelection::sanitizeLoanTerm($additionalData[self::PAYMENT_LOAN_TERM] ?? null);

        if ($loanType !== null) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_LOAN_TYPE, $loanType);
        }

        if ($loanTerm !== null) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_LOAN_TERM, (string) $loanTerm);
        }

        // Optional customer-entered promotional code; a blank or unusable value is not stored at all.
        $promoCode = PromoCode::normalize($additionalData[self::PAYMENT_PROMO_CODE] ?? null);

        if ($promoCode !== null) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_PROMO_CODE, $promoCode);
        }

        /* Track ID of the paywall events, sent by the frontend SDK: the ComfinoPay order is created with it in a later
           request (see ApplicationService::createApplicationTransaction()). A malformed value is not stored. */
        $trackId = $additionalData[self::PAYMENT_TRACK_ID] ?? null;

        if (is_string($trackId) && preg_match(ApiClient::CHECKOUT_TRACK_ID_PATTERN, $trackId) === 1) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_TRACK_ID, $trackId);
        }
    }
}
