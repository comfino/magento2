<a href="https://developers.comfino.pl">
  <img src="assets/comfino_logo.svg" alt="Comfino" width="220">
</a>

# Comfino Payment Gateway for Magento 2

[![Latest Version](https://img.shields.io/badge/release-4.0.1-blue.svg)](https://github.com/comfino/magento2/releases)
[![PHP Version](https://img.shields.io/packagist/dependency-v/comfino/magento2/php.svg)](https://packagist.org/packages/comfino/magento2)
[![Build Status](https://github.com/comfino/magento2/actions/workflows/tests.yml/badge.svg)](https://github.com/comfino/magento2/actions/workflows/tests.yml)
[![Software License](https://img.shields.io/badge/license-OSL--3.0-orange.svg)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/comfino/magento2.svg)](https://packagist.org/packages/comfino/magento2)
[![API Documentation](https://img.shields.io/badge/API-documentation-5a9e33)](https://developers.comfino.pl)

Magento 2 payment module for [Comfino](https://comfino.pl/) — Polish payment gateway offering installment payments, buy now pay later (BNPL), and business financing solutions.

## Requirements

- **Magento**: 2.4.4 or higher (Magento 2.3.7+ / 2.4.0–2.4.3 → use [v3.1.0 ZIP](https://github.com/comfino/Magento-2.3/releases) instead)
- **PHP**: 8.1 or higher
- **PHP extensions**: ctype, json, sodium, zlib

## Installation

### Via Composer (recommended)

```bash
composer require comfino/magento2
bin/magento module:enable Comfino_ComfinoGateway Comfino_SdkForMagento2
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Hyvä Checkout support (optional)

```bash
composer require comfino/magento2-hyva-checkout
bin/magento module:enable Comfino_ComfinoGatewayHyvaCheckout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

> **Note for Hyvä Checkout clients:** `hyva-themes/magento2-hyva-checkout` is distributed via the
> private Hyvä repository. Add `{"type":"composer","url":"https://hyva.io/packages/"}` to your
> project's `repositories` before running `composer require`.

### Manual installation (legacy — Magento 2.3.x / 2.4.0–2.4.3 only)

See [Installation Guide (English)](docs/comfino.en.md) or [Installation Guide (Polish)](docs/comfino.pl.md).

## Configuration

1. Navigate to: **Stores → Configuration → Sales → Payment Methods → Comfino**.
2. Enable the module.
3. Enter your API key (provided by your Comfino representative).
4. Configure widget and payment options.
5. Save configuration.

## Migration from ZIP installation

If you previously installed via ZIP, see [Migration Guide](MIGRATION.md) for upgrade instructions.

## Hyvä Theme support

Version 4.0.0+ includes full support for Hyvä themes using an iframe-based architecture.

## Development

### Local setup

```bash
# Install dependencies
./bin/composer install

# Run tests
./bin/phpunit

# Run with coverage
XDEBUG_MODE=coverage ./bin/phpunit --coverage-html coverage
```

Tests run without a Magento installation — `tests/bootstrap.php` provides Magento stubs.

## Support

- **E-mail:** pomoc@comfino.pl
- **Documentation (English):** [comfino.pl/plugins/Magento/en](https://comfino.pl/plugins/Magento/en)
- **Documentation (Polish):** [comfino.pl/plugins/Magento/pl](https://comfino.pl/plugins/Magento/pl)
- **Issues:** [GitHub Issues](https://github.com/comfino/Magento-2.3/issues)

## License

[Open Software License (OSL 3.0)](LICENSE)
