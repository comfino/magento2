<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests;

use Comfino\ComfinoGateway\Model\Config\Backend\AllowedProductsConfig;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/** @internal Test double — bypasses ConfigManager static dependency in AllowedProductsConfig::beforeSave(). */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class AllowedProductsConfigStub extends AllowedProductsConfig
{
    protected function isFeatureEnabled(): bool
    {
        return true;
    }
}

final class AllowedProductsConfigBackendModelTest extends TestCase
{
    private AllowedProductsConfig $backendModel;

    protected function setUp(): void
    {
        $this->backendModel = (new ObjectManager($this))->getObject(AllowedProductsConfigStub::class);
    }

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testAcceptsEmptyValue(): void
    {
        $this->backendModel->setValue('');
        $this->backendModel->beforeSave();

        $this->assertSame('', $this->backendModel->getValue());
    }

    public function testAcceptsWhitespaceOnlyValueAsEmpty(): void
    {
        $this->backendModel->setValue("   \n  ");
        $this->backendModel->beforeSave();

        $this->assertSame('', $this->backendModel->getValue());
    }

    public function testAcceptsValidConfig(): void
    {
        $this->backendModel->setValue('[{"type":"PAY_LATER","maxTerm":3}]');
        $this->backendModel->beforeSave();

        $this->assertSame('[{"type":"PAY_LATER","maxTerm":3}]', $this->backendModel->getValue());
    }

    public function testAcceptsEmptyJsonArray(): void
    {
        $this->backendModel->setValue('[]');
        $this->backendModel->beforeSave();

        $this->assertSame('', $this->backendModel->getValue());
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');

        $this->backendModel->setValue('{bad json');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonArrayRoot(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/top-level value must be a JSON array/');

        $this->backendModel->setValue('{"type":"PAY_LATER"}');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonObjectEntry(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/entry #1 must be an object/');

        $this->backendModel->setValue('["PAY_LATER"]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsUnknownProductType(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/unknown product type "NOT_REAL"/');

        $this->backendModel->setValue('[{"type":"NOT_REAL","maxTerm":6}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsMissingType(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/missing the required "type" field/');

        $this->backendModel->setValue('[{"maxTerm":6}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonPositiveMinTerm(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"minTerm" must be a positive integer/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","minTerm":0}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonPositiveMaxTerm(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"maxTerm" must be a positive integer/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","maxTerm":-5}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonNumericTerm(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"minTerm" must be a positive integer/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","minTerm":"banana"}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsMinTermGreaterThanMaxTerm(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/minTerm \(12\) greater than maxTerm \(6\)/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","minTerm":12,"maxTerm":6}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonPositiveTerms(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/terms\[0\] must be a positive integer/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","terms":[-1]}]');
        $this->backendModel->beforeSave();
    }

    public function testRejectsNonArrayTerms(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"terms" must be a JSON array/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","terms":"3,6,12"}]');
        $this->backendModel->beforeSave();
    }

    public function testDropsEntryWithoutAnyConstraint(): void
    {
        $this->backendModel->setValue('[{"type":"PAY_LATER"}]');
        $this->backendModel->beforeSave();

        $this->assertSame('', $this->backendModel->getValue());
    }

    public function testNormalizesCanonicalJsonOrderingTypeFirst(): void
    {
        $this->backendModel->setValue('[{"maxTerm":3,"type":"PAY_LATER"}]');
        $this->backendModel->beforeSave();

        $this->assertSame('[{"type":"PAY_LATER","maxTerm":3}]', $this->backendModel->getValue());
    }

    public function testDeduplicatesTerms(): void
    {
        $this->backendModel->setValue('[{"type":"PAY_LATER","terms":[3,3,6,6,12]}]');
        $this->backendModel->beforeSave();

        $this->assertSame('[{"type":"PAY_LATER","terms":[3,6,12]}]', $this->backendModel->getValue());
    }

    public function testAcceptsMinTermEqualToMaxTerm(): void
    {
        $this->backendModel->setValue('[{"type":"PAY_LATER","minTerm":6,"maxTerm":6}]');
        $this->backendModel->beforeSave();

        $this->assertSame('[{"type":"PAY_LATER","minTerm":6,"maxTerm":6}]', $this->backendModel->getValue());
    }

    public function testNormalizesMultipleEntries(): void
    {
        $this->backendModel->setValue(
            '[{"type":"PAY_LATER","maxTerm":3},{"type":"INSTALLMENTS_ZERO_PERCENT","minTerm":6,"maxTerm":24,"terms":[6,12,24]}]'
        );
        $this->backendModel->beforeSave();

        $this->assertSame(
            '[{"type":"PAY_LATER","maxTerm":3},{"type":"INSTALLMENTS_ZERO_PERCENT","minTerm":6,"maxTerm":24,"terms":[6,12,24]}]',
            $this->backendModel->getValue()
        );
    }

    public function testRejectsNonNumericTermInsideTermsArray(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/terms\[1\] must be a positive integer/');

        $this->backendModel->setValue('[{"type":"PAY_LATER","terms":[3,"banana",12]}]');
        $this->backendModel->beforeSave();
    }

    /*
       The tests below drive the REAL AllowedProductsConfig (not the stub) so its isFeatureEnabled() reads through
       the live ConfigManager facade, seeded with an in-memory ConfigurationManager via ConfigManagerHarness.
    */

    public function testFeatureDisabledRestoresOldValueAndSkipsValidation(): void
    {
        ConfigManagerHarness::install(['COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => false]);

        $model = (new ObjectManager($this))->getObject(AllowedProductsConfig::class);

        /* Invalid JSON would normally throw; with the feature off it is replaced by the persisted old value (here the
           mocked ScopeConfig resolves it to null), so validation never runs and no exception is thrown. */
        $model->setValue('{garbage');
        $model->beforeSave();

        $this->assertSame('', (string) $model->getValue());
    }

    public function testFeatureEnabledRunsValidation(): void
    {
        ConfigManagerHarness::install(['COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => true]);

        $model = (new ObjectManager($this))->getObject(AllowedProductsConfig::class);

        $model->setValue('[{"type":"PAY_LATER","maxTerm":3}]');
        $model->beforeSave();

        $this->assertSame('[{"type":"PAY_LATER","maxTerm":3}]', $model->getValue());
    }
}
