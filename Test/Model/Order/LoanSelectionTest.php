<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Order;

use Comfino\Api\Dto\Payment\FinancialProduct;
use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoanSelectionTest extends TestCase
{
    use LoggerHarnessTrait;

    /** @var array<string, string> In-memory cache backing the report counter */
    private array $cacheEntries = [];

    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install(['COMFINO_DEBUG' => true]);
        $this->installLoggerHarness();
    }

    protected function tearDown(): void
    {
        $this->resetLoggerHarness();
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    // --- sanitizeLoanType() / sanitizeLoanTerm() ---

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function loanTypeProvider(): array
    {
        return [
            'known type' => ['PAY_LATER', 'PAY_LATER'],
            'surrounding spaces' => [' PAY_LATER ', 'PAY_LATER'],
            'unknown but well-formed type' => ['NEW_PRODUCT', 'NEW_PRODUCT'],
            'lower case' => ['pay_later', null],
            'markup' => ['<b>PAY_LATER</b>', null],
            'too long' => [str_repeat('A', 41), null],
            'empty' => ['', null],
            'JS undefined' => ['undefined', null],
            'not a string' => [12, null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('loanTypeProvider')]
    public function testSanitizeLoanType(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, LoanSelection::sanitizeLoanType($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function loanTermProvider(): array
    {
        return [
            'integer' => [12, 12],
            'digit string' => ['18', 18],
            'lower bound' => [1, 1],
            'upper bound' => [120, 120],
            'zero' => [0, null],
            'above the cap' => [121, null],
            'negative' => ['-6', null],
            'fraction' => ['12.5', null],
            'float' => [12.0, null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('loanTermProvider')]
    public function testSanitizeLoanTerm(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, LoanSelection::sanitizeLoanTerm($value));
    }

    // --- validate() ---

    public function testValidateAcceptsAnOfferedTypeWithinTheTermLimits(): void
    {
        $termLimits = [['type' => 'PAY_LATER', 'minTerm' => 1, 'maxTerm' => 3]];

        $this->assertSame(LoanType::PAY_LATER, LoanSelection::validate('PAY_LATER', '3', [LoanType::PAY_LATER], $termLimits));
    }

    public function testValidateRejectsATypeNotOfferedForTheCart(): void
    {
        $this->assertNull(LoanSelection::validate('LEASING', 12, [LoanType::PAY_LATER]));
    }

    public function testValidateRejectsATermOutsideTheLimits(): void
    {
        $termLimits = [['type' => 'PAY_LATER', 'minTerm' => 1, 'maxTerm' => 3]];

        $this->assertNull(LoanSelection::validate('PAY_LATER', 4, [LoanType::PAY_LATER], $termLimits));
    }

    public function testValidateRejectsEverythingWhenNothingIsOffered(): void
    {
        $this->assertNull(LoanSelection::validate('PAY_LATER', 1, []));
    }

    public function testValidateRejectsATypeThisSdkDoesNotKnow(): void
    {
        $this->assertNull(LoanSelection::validate('NEW_PRODUCT', 12, [LoanType::fromApiValue('NEW_PRODUCT')]));
    }

    // --- selectDefaultProduct() ---

    public function testDefaultProductSkipsInstantPaymentsAndBlik(): void
    {
        $default = LoanSelection::selectDefaultProduct([
            self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1),
            self::createFinancialProduct(LoanType::BLIK, 1),
            self::createFinancialProduct(LoanType::CONVENIENT_INSTALLMENTS, 24),
            self::createFinancialProduct(LoanType::PAY_LATER, 3),
        ]);

        $this->assertNotNull($default);
        $this->assertSame(LoanType::CONVENIENT_INSTALLMENTS, $default->type);
    }

    public function testDefaultProductIsTheFirstOffer(): void
    {
        $default = LoanSelection::selectDefaultProduct([
            self::createFinancialProduct(LoanType::PAY_LATER, 3),
            self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1),
        ]);

        $this->assertSame(LoanType::PAY_LATER, $default?->type);
    }

    public function testNoDefaultProductWhenOnlyExcludedTypesAreOffered(): void
    {
        $this->assertNull(LoanSelection::selectDefaultProduct([
            self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1),
            self::createFinancialProduct(LoanType::BLIK, 1),
        ]));
        $this->assertNull(LoanSelection::selectDefaultProduct([]));
    }

    // --- resolve() ---

    public function testResolveReturnsAValidSelectionWithoutLookingUpOffers(): void
    {
        $loanSelection = $this->createLoanSelection([LoanType::PAY_LATER], [], expectOfferLookup: false);

        $this->assertSame([LoanType::PAY_LATER, 3], $loanSelection->resolve('PAY_LATER', '3', $this->createCart(), 12000, false));
    }

    public function testResolveFallsBackToTheDefaultProductForAMissingSelection(): void
    {
        $loanSelection = $this->createLoanSelection(
            [LoanType::INSTANT_PAYMENTS, LoanType::BLIK, LoanType::CONVENIENT_INSTALLMENTS],
            [
                self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1),
                self::createFinancialProduct(LoanType::BLIK, 1),
                self::createFinancialProduct(LoanType::CONVENIENT_INSTALLMENTS, 24),
            ]
        );

        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS, 24],
            $loanSelection->resolve(null, null, $this->createCart(), 12000, false)
        );
        $this->assertNotEmpty($this->cacheEntries, 'A defaulted selection outside direct redirect mode must be counted for the report.');
    }

    public function testResolveFallsBackToTheDefaultProductForADisallowedSelection(): void
    {
        $loanSelection = $this->createLoanSelection(
            [LoanType::PAY_LATER],
            [self::createFinancialProduct(LoanType::PAY_LATER, 3)]
        );

        $this->assertSame([LoanType::PAY_LATER, 3], $loanSelection->resolve('LEASING', 24, $this->createCart(), 12000, false));
    }

    public function testResolveDoesNotReportInDirectRedirectMode(): void
    {
        $loanSelection = $this->createLoanSelection(
            [LoanType::PAY_LATER],
            [self::createFinancialProduct(LoanType::PAY_LATER, 3)]
        );

        $loanSelection->resolve(null, null, $this->createCart(), 12000, true);

        $this->assertSame([], $this->cacheEntries);
    }

    public function testResolveRefusesWhenNoSafeDefaultIsOffered(): void
    {
        $loanSelection = $this->createLoanSelection(
            [LoanType::INSTANT_PAYMENTS, LoanType::BLIK],
            [self::createFinancialProduct(LoanType::INSTANT_PAYMENTS, 1), self::createFinancialProduct(LoanType::BLIK, 1)]
        );

        $this->expectException(InvalidArgumentException::class);

        $loanSelection->resolve(null, null, $this->createCart(), 12000, false);
    }

    public function testResolveRefusesWhenTheDefaultIsNotOfferedForTheCart(): void
    {
        $loanSelection = $this->createLoanSelection(
            [LoanType::PAY_LATER],
            [self::createFinancialProduct(LoanType::LEASING, 24)]
        );

        $this->expectException(InvalidArgumentException::class);

        $loanSelection->resolve(null, null, $this->createCart(), 12000, false);
    }

    /**
     * A failed product type lookup offers nothing: the selection is not trusted and no offers are looked up.
     */
    public function testResolveFailsClosedWhenTheProductTypeLookupFails(): void
    {
        $loanSelection = $this->createLoanSelection([], [], expectOfferLookup: false);

        $this->expectException(InvalidArgumentException::class);

        $loanSelection->resolve('PAY_LATER', 3, $this->createCart(), 12000, false);
    }

    // --- recordOccurrence() ---

    public function testTheFirstOccurrenceIsReportedAtOnceThenOncePerDay(): void
    {
        $loanSelection = $this->createLoanSelection([], []);
        $start = 1_700_000_000;

        $this->assertSame(['count' => 1, 'since' => $start], $loanSelection->recordOccurrence('CODE', $start));
        $this->assertNull($loanSelection->recordOccurrence('CODE', $start + 60));
        $this->assertNull($loanSelection->recordOccurrence('CODE', $start + 3600));
        $this->assertSame(['count' => 3, 'since' => $start], $loanSelection->recordOccurrence('CODE', $start + 86400));
        $this->assertNull($loanSelection->recordOccurrence('CODE', $start + 86460));
    }

    public function testAnUnreadableCounterStartsOver(): void
    {
        $loanSelection = $this->createLoanSelection([], []);
        $this->cacheEntries['comfino_loan_selection_warning_code'] = 'not json';

        $this->assertSame(['count' => 1, 'since' => 1_700_000_000], $loanSelection->recordOccurrence('CODE', 1_700_000_000));
    }

    /**
     * @param LoanTypeInterface[] $allowedProductTypes
     * @param FinancialProduct[] $financialProducts
     */
    private function createLoanSelection(
        array $allowedProductTypes,
        array $financialProducts,
        bool $expectOfferLookup = true
    ): LoanSelection {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id): string|false => $this->cacheEntries[$id] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $id): bool {
            $this->cacheEntries[$id] = $data;

            return true;
        });

        $offerLookup = function () use ($financialProducts, $expectOfferLookup): array {
            if (!$expectOfferLookup) {
                $this->fail('The financial products endpoint must not be called.');
            }

            return $financialProducts;
        };

        return new class ($cache, new Json(), $allowedProductTypes, $offerLookup) extends LoanSelection {
            /**
             * @param LoanTypeInterface[] $allowedProductTypes
             */
            public function __construct(
                CacheInterface $cache,
                Json $serializer,
                private readonly array $allowedProductTypes,
                private readonly \Closure $offerLookup
            ) {
                parent::__construct($cache, $serializer);
            }

            protected function getAllowedProductTypes(Cart $cart): array
            {
                return $this->allowedProductTypes;
            }

            protected function getTermLimits(): ?array
            {
                return null;
            }

            protected function getFinancialProducts(int $loanAmount, array $allowedProductTypes): array
            {
                return ($this->offerLookup)();
            }
        };
    }

    private function createCart(): Cart
    {
        return new Cart(12000, null, null, 0, null, null, null, []);
    }

    private static function createFinancialProduct(LoanType $type, int $loanTerm): FinancialProduct
    {
        return new FinancialProduct($type->value, $type, 'Creditor', '', '', 1000, 3000, $loanTerm, 0.0, '', null, []);
    }
}
