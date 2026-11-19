<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
use PHPUnit\Framework\Attributes\DataProvider;
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
                    $this->assertSame('12', $value);
                }

                return $this->paymentInfo;
            });

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => 'INSTALLMENTS_ZERO_PERCENT',
            DataAssignObserver::PAYMENT_LOAN_TERM => 12,
        ]));
    }

    public function testAssignsTheTrackIdOfThePaywallEvents(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_TRACK_ID, '01a1d4b7-46b3-725e-86cc-0047ff6f3881');

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_TRACK_ID => '01a1d4b7-46b3-725e-86cc-0047ff6f3881',
        ]));
    }

    public function testSkipsAMalformedTrackId(): void
    {
        $this->paymentInfo->expects($this->never())->method('setAdditionalInformation');

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_TRACK_ID => "bad\r\nvalue",
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
            ->with(DataAssignObserver::PAYMENT_LOAN_TERM, '6')
            ->willReturn($this->paymentInfo);

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => '',
            DataAssignObserver::PAYMENT_LOAN_TERM => 6,
        ]));
    }

    public function testStoresTheLoanTermNormalized(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_LOAN_TERM, '18');

        (new DataAssignObserver())->execute($this->makeObserver([DataAssignObserver::PAYMENT_LOAN_TERM => ' 018 ']));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function malformedSelectionProvider(): array
    {
        return [
            'markup in the type, zero term' => ['<script>', 0],
            'lower-case type, term above the cap' => ['pay_later', 121],
            'JS undefined, fractional term' => ['undefined', '12.5'],
            'array type, negative term' => [['PAY_LATER'], -3],
        ];
    }

    #[DataProvider('malformedSelectionProvider')]
    public function testDropsAStructurallyInvalidSelection(mixed $loanType, mixed $loanTerm): void
    {
        $this->paymentInfo->expects($this->never())->method('setAdditionalInformation');

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_LOAN_TYPE => $loanType,
            DataAssignObserver::PAYMENT_LOAN_TERM => $loanTerm,
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

    public function testAssignsPromoCode(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_PROMO_CODE, 'SUMMER10')
            ->willReturn($this->paymentInfo);

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_PROMO_CODE => 'SUMMER10',
        ]));
    }

    public function testPromoCodeIsStoredCleanedUp(): void
    {
        $this->paymentInfo
            ->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_PROMO_CODE, 'SUMMER10')
            ->willReturn($this->paymentInfo);

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_PROMO_CODE => '  <b>SUMMER10</b> ',
        ]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusablePromoCodeProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \t"],
            'markup only' => ['<b></b>'],
            'decoded nbsp' => ['&nbsp;'],
            'over 64 characters' => [str_repeat('A', 65)],
            'array' => [['SUMMER10']],
            'integer' => [10],
            'null' => [null],
        ];
    }

    #[DataProvider('unusablePromoCodeProvider')]
    public function testAnUnusablePromoCodeIsNotStored(mixed $promoCode): void
    {
        $this->paymentInfo->expects($this->never())->method('setAdditionalInformation');

        (new DataAssignObserver())->execute($this->makeObserver([
            DataAssignObserver::PAYMENT_PROMO_CODE => $promoCode,
        ]));
    }
}
