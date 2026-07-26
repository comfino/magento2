<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class DataAssignObserver extends AbstractDataAssignObserver
{
    public const PAYMENT_LOAN_TYPE = 'loanType';
    public const PAYMENT_LOAN_TERM = 'loanTerm';

    public function execute(Observer $observer): void
    {
        $data = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        $paymentInfo = $this->readPaymentModelArgument($observer);

        if (!is_array($additionalData)) {
            return;
        }

        $loanType = $additionalData[self::PAYMENT_LOAN_TYPE] ?? null;
        $loanTerm = $additionalData[self::PAYMENT_LOAN_TERM] ?? null;

        if (!empty($loanType)) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_LOAN_TYPE, $loanType);
        }

        if (!empty($loanTerm)) {
            $paymentInfo->setAdditionalInformation(self::PAYMENT_LOAN_TERM, $loanTerm);
        }
    }
}
