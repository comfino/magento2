<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Observer;

use Comfino\ComfinoGateway\Observer\DataAssignObserver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DataAssignObserverTest extends TestCase
{
    private InfoInterface&MockObject $paymentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentInfo = $this->createMock(InfoInterface::class);
    }

    /**
     * Builds an Observer carrying the data object and the payment model that AbstractDataAssignObserver reads.
     *
     * @param array<string, mixed>|string $additionalData
     */
    private function makeObserver(mixed $additionalData): Observer
    {
        $data = new DataObject([PaymentInterface::KEY_ADDITIONAL_DATA => $additionalData]);

        $event = new Event([
            DataAssignObserver::DATA_CODE => $data,
            DataAssignObserver::MODEL_CODE => $this->paymentInfo,
        ]);

        return new Observer(['event' => $event]);
    }

    public function testAssignsBothLoanTypeAndLoanTerm(): void
    {
        $matcher = $this->exactly(2);

        $this->paymentInfo
            ->expects($matcher)
            ->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, mixed $value) use ($matcher): InfoInterface {
                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame(DataAssignObserver::PAYMENT_LOAN_TYPE, $key);
                    $this->assertSame('INSTALLMENTS_ZERO_PERCENT', $value);
                } else {
                    $this->assertSame(DataAssignObserver::PAYMENT_LOAN_TERM, $key);
                    $this->assertSame(12, $value);
                }

                return $this->paymentInfo;
            });

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => 'INSTALLMENTS_ZERO_PERCENT',
            DataAssignObserver::PAYMENT_LOAN_TERM => 12,
        ]));
    }

    public function testAssignsOnlyLoanTypeWhenTermMissing(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_LOAN_TYPE, 'PAY_LATER')
            ->willReturn($this->paymentInfo);

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => 'PAY_LATER',
        ]));
    }

    public function testAssignsOnlyLoanTermWhenTypeEmpty(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_LOAN_TERM, 6)
            ->willReturn($this->paymentInfo);

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => '',
            DataAssignObserver::PAYMENT_LOAN_TERM => 6,
        ]));
    }

    public function testAssignsNothingWhenAdditionalDataIsNotArray(): void
    {
        $this->paymentInfo->expects($this->never())->method('setAdditionalInformation');

        (new DataAssignObserver())->execute($this->makeObserver('not-an-array'));
    }

    public function testAssignsNothingWhenLoanFieldsAbsent(): void
    {
        $this->paymentInfo->expects($this->never())->method('setAdditionalInformation');

        (new DataAssignObserver())->execute($this->makeObserver(['someOtherKey' => 'value']));
    }
}