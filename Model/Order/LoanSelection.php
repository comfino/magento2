<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Order;

use Comfino\Api\Dto\Payment\FinancialProduct;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Observer\DataAssignObserver;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\OrderValidator;
use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Throwable;

/**
 * The financial product (type and term) an order is created with.
 *
 * The browser-supplied selection is validated against the offered types and term limits. A missing or unacceptable one
 * falls back to a safe default product instead of refusing the order, and is reported as a summary warning at most daily.
 */
class LoanSelection
{
    public const CODE_DEFAULTED = 'LOAN_SELECTION_NOT_POSTED_DEFAULTED';
    public const CACHE_TAG = 'comfino_loan_selection';

    private const LOAN_TYPE_PATTERN = '/^[A-Z_]{1,40}$/';

    /** INSTANT_PAYMENTS starts a different payment path that cannot be changed afterwards; BLIK is not a default either. */
    private const EXCLUDED_DEFAULT_TYPES = ['INSTANT_PAYMENTS', 'BLIK'];

    private const REPORT_INTERVAL = 86400;
    private const STATE_TTL = 2592000;
    private const STATE_CACHE_ID_PREFIX = 'comfino_loan_selection_warning_';

    public function __construct(private readonly CacheInterface $cache, private readonly Json $serializer)
    {
    }

    /**
     * Returns the loan type when the value is structurally valid (upper-case letters and underscores), else null.
     */
    public static function sanitizeLoanType(mixed $loanType): ?string
    {
        if (!is_string($loanType) || preg_match(self::LOAN_TYPE_PATTERN, $loanType = trim($loanType)) !== 1) {
            return null;
        }

        return $loanType;
    }

    /**
     * Returns the loan term as an integer within the hard 1..120 month cap, else null.
     */
    public static function sanitizeLoanTerm(mixed $loanTerm): ?int
    {
        if (is_string($loanTerm) && ctype_digit(trim($loanTerm))) {
            $loanTerm = (int) trim($loanTerm);
        }

        if (!is_int($loanTerm) || $loanTerm < OrderValidator::MIN_LOAN_TERM || $loanTerm > OrderValidator::MAX_LOAN_TERM) {
            return null;
        }

        return $loanTerm;
    }

    /**
     * Picks the safe default product: the first offer that is not INSTANT_PAYMENTS or BLIK, or null when only those are offered.
     *
     * @param FinancialProduct[] $financialProducts Offers in the order returned by the financial products endpoint
     */
    public static function selectDefaultProduct(array $financialProducts): ?FinancialProduct
    {
        foreach ($financialProducts as $financialProduct) {
            if (!in_array($financialProduct->type->getValue(), self::EXCLUDED_DEFAULT_TYPES, true)) {
                return $financialProduct;
            }
        }

        return null;
    }

    /**
     * Validates a selection against the loan types this SDK knows, the types offered for the cart and the term limits.
     *
     * @param LoanTypeInterface[] $allowedProductTypes
     * @param array<int, array{type: string, minTerm?: int, maxTerm?: int, terms?: int[]}>|null $termLimits
     */
    public static function validate(
        mixed $loanType,
        mixed $loanTerm,
        array $allowedProductTypes,
        ?array $termLimits = null
    ): ?LoanType {
        if (($loanType = self::sanitizeLoanType($loanType)) === null || ($loanTerm = self::sanitizeLoanTerm($loanTerm)) === null) {
            return null;
        }

        if (($loanTypeEnum = LoanType::tryFrom($loanType)) === null) {
            return null;
        }

        $errors = (new OrderValidator())->validateLoanSelection($loanTypeEnum, $loanTerm, $allowedProductTypes, $termLimits);

        return $errors === [] ? $loanTypeEnum : null;
    }

    /**
     * Returns the validated selection, or the safe default product when the selection is missing or not acceptable.
     *
     * @param int $loanAmount Order total in grosze, used to look up the default product
     * @param bool $directRedirect Direct redirect mode renders no paywall, so a missing selection is expected there and is not reported
     *
     * @return array{0: LoanType, 1: int}
     *
     * @throws InvalidArgumentException When neither the selection nor a safe default product can be used
     */
    public function resolve(mixed $loanType, mixed $loanTerm, Cart $cart, int $loanAmount, bool $directRedirect): array
    {
        $allowedProductTypes = $this->getAllowedProductTypes($cart);
        $selectedType = self::validate($loanType, $loanTerm, $allowedProductTypes, $this->getTermLimits());

        if ($selectedType !== null) {
            return [$selectedType, (int) self::sanitizeLoanTerm($loanTerm)];
        }

        $defaultProduct = $allowedProductTypes !== [] ? self::selectDefaultProduct($this->getFinancialProducts($loanAmount, $allowedProductTypes)) : null;

        // The default is checked against the allowlist too; its term comes from the API's own offer.
        $defaultSelection = null;

        if ($defaultProduct !== null && ($defaultType = self::validate($defaultProduct->type->getValue(), $defaultProduct->loanTerm, $allowedProductTypes)) !== null) {
            $defaultSelection = [$defaultType, $defaultProduct->loanTerm];
        }

        $missingFields = self::getMissingFields($loanType, $loanTerm);

        DebugLogger::logEvent(
            'Loan selection not usable.',
            [
                'missingFields' => $missingFields,
                'directRedirect' => $directRedirect,
                'defaultType' => $defaultSelection[0]->value ?? null,
                'defaultTerm' => $defaultSelection[1] ?? null,
            ],
            'PAYMENT'
        );

        if ($defaultSelection === null) {
            throw new InvalidArgumentException(
                (string) __('Please select a financial product type in the payment form before placing the order.')
            );
        }

        if (!$directRedirect) {
            $this->reportDefaulted($missingFields);
        }

        return $defaultSelection;
    }

    /**
     * Counts one occurrence of a code and tells whether a report is due: the first occurrence at once, then one report
     * per 24 hours carrying the number of occurrences since the previous report and its start time.
     *
     * @return array{count: int, since: int}|null Summary to report, or null when the interval has not elapsed yet
     */
    public function recordOccurrence(string $code, ?int $now = null): ?array
    {
        $cacheId = self::STATE_CACHE_ID_PREFIX . strtolower($code);
        $now ??= time();

        try {
            $state = $this->serializer->unserialize((string) $this->cache->load($cacheId));
        } catch (Throwable) {
            $state = null;
        }

        if (!is_array($state) || !isset($state['since'], $state['count'])) {
            $state = ['since' => $now, 'count' => 0, 'reported' => false];
        }

        $state['count'] = (int) $state['count'] + 1;

        if (empty($state['reported']) || $now - (int) $state['since'] >= self::REPORT_INTERVAL) {
            $summary = ['count' => $state['count'], 'since' => (int) $state['since']];
            $state = ['since' => $now, 'count' => 0, 'reported' => true];
        } else {
            $summary = null;
        }

        $this->cache->save((string) $this->serializer->serialize($state), $cacheId, [self::CACHE_TAG], self::STATE_TTL);

        return $summary;
    }

    /**
     * @return LoanTypeInterface[]
     */
    protected function getAllowedProductTypes(Cart $cart): array
    {
        return SettingsManager::resolveAllowedProductTypes(ProductListType::PAYWALL->value, $cart);
    }

    /**
     * @return array<int, array{type: string, minTerm?: int, maxTerm?: int, terms?: int[]}>|null
     */
    protected function getTermLimits(): ?array
    {
        return SettingsManager::getAllowedProductsConfigForFrontend();
    }

    /**
     * @param LoanTypeInterface[] $allowedProductTypes
     *
     * @return FinancialProduct[]
     */
    protected function getFinancialProducts(int $loanAmount, array $allowedProductTypes): array
    {
        try {
            return ApiClient::getInstance()->getFinancialProducts(new LoanQueryCriteria(
                loanAmount: $loanAmount,
                productTypes: $allowedProductTypes,
                allowedProductsConfig: SettingsManager::getAllowedProductsConfig()
            ))->financialProducts;
        } catch (Throwable $e) {
            ApiClient::processApiError(OperationContext::OrderCreation, ErrorCategory::ApiError, ErrorSeverity::Error, $e);

            return [];
        }
    }

    /**
     * @param string[] $missingFields
     */
    private function reportDefaulted(array $missingFields): void
    {
        try {
            if (($summary = $this->recordOccurrence(self::CODE_DEFAULTED)) === null) {
                return;
            }

            ErrorLogger::sendWarning(
                ErrorCategory::ConfigurationError,
                OperationContext::OrderCreation,
                self::CODE_DEFAULTED,
                sprintf(
                    'Orders submitted without a usable financing offer selection: %d since %s (in the latest: %s), safe default financing offer used.',
                    $summary['count'],
                    gmdate('Y-m-d H:i:s', $summary['since']) . ' UTC',
                    implode(', ', $missingFields)
                )
            );
        } catch (Throwable) {
            // Reporting is best-effort.
        }
    }

    /**
     * @return string[] The payment fields that were missing, or both when they were posted but not acceptable
     */
    private static function getMissingFields(mixed $loanType, mixed $loanTerm): array
    {
        $missingFields = array_keys(array_filter([
            DataAssignObserver::PAYMENT_LOAN_TYPE => $loanType === null || $loanType === '',
            DataAssignObserver::PAYMENT_LOAN_TERM => $loanTerm === null || $loanTerm === '' || $loanTerm === 0,
        ]));

        return $missingFields !== [] ? $missingFields : [DataAssignObserver::PAYMENT_LOAN_TYPE, DataAssignObserver::PAYMENT_LOAN_TERM];
    }
}
