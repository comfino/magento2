# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.0.1] - 2026-07-26

### Security
- **Orders re-checked before a Comfino application is opened** — The payment endpoint now rejects orders not paid with Comfino, orders that already have an application, and orders past the payment stage. Prevents an order placed with another payment method being turned into a Comfino application, and prevents duplicate applications for one order.
- **Checkout tracking cookie now uses Magento's cookie API** — Keeps its `Secure`, `HttpOnly`, `SameSite=Lax` attributes and 15-minute lifetime; a cookie that cannot be sent is logged instead of silently skipped.
- **Admin warning messages are HTML-escaped** — Warnings in the Comfino system-information panel are now escaped like the success and error entries beside them.

### Fixed
- **Scheduled tasks did nothing** — Both Comfino cron jobs failed silently on start-up. The outbound request queue is now actually drained on schedule, and the daily shop-environment report is actually sent.
- **Slow storefront during a Comfino API outage** — An unreachable API added its full connection-retry time to every page load until it recovered. The affected request now backs off for 15 minutes after a failure and no longer runs on shopper-facing pages at all.
- **Duplicate update checks** — Concurrent admin page loads could each fire their own release-version check. Only one now runs at a time.
- **Update checks clustered across installations** — The version-check interval is now randomised to 20-28 hours (was a fixed 24), so shops drift apart instead of all polling at once.
- **Queued order cancellations lost on multistore installations** — When a website or store view used its own Comfino API key, a cancellation that had to be retried was resent with the default store's key, rejected, and discarded without reaching Comfino. Cancellations now remember which store they belong to and are retried with that store's credentials. Only retried cancellations were affected; ones that succeeded immediately were always sent correctly.

## [4.0.0] - 2026-07-22

### Breaking Changes
- **Minimum PHP version raised to 8.1** — Magento 2.4.4+ now required.
- **Dependency migration** — Switched from `comfino/shop-plugins-shared` to dedicated SDKs (`comfino/php-sdk`, `comfino/sdk-for-magento2`).
- **Namespace consolidation** — All plugin classes moved to `Comfino\ComfinoGateway\*` namespace; `src/` directory and old `Comfino\*` bridges removed.
- **Bundled vendor libraries removed** — Guzzle, Monolog, and Symfony components previously shipped inside the plugin are now provided by the `comfino/php-sdk` and `comfino/sdk-for-magento2` Composer packages.

### Added
- **Outbound request queue** — API calls that fail transiently (order cancellations, error reports) are now enqueued in the `comfino_request_queue` DB table and retried automatically instead of being lost. The queue is drained every 5 minutes by a new cron job (`comfino_process_request_queue`) and opportunistically after each incoming webhook. Configure via `retry_queue_enabled`, `retry_queue_max_attempts` (default: 10), `retry_queue_batch_size` (default: 20), `retry_queue_cooldown` (default: 300 s), `api_queue_connect_timeout` (default: 1 s), and `api_queue_timeout` (default: 2 s) in `payment/comfino/*`.
- **Composer package distribution** — Published to Packagist as `comfino/magento2` for easier installation and updates.
- **Iframe-based payment architecture** — Universal theme compatibility (Luma, Hyvä, custom themes).
- **Hyvä Checkout integration** — Separate `comfino/magento2-hyva-checkout` sub-module with automatic GitHub Actions subtree sync.
- **PostMessage API** — Secure parent-iframe communication during checkout.
- **SDK integration** — Uses native PHP 8.1 enums, readonly properties, and Magento's native HTTP client, cache, and logging.
- **Shop environment auto-reporting** — On every admin config save the module sends the shop's platform, theme family, locale, and page-context data to the Comfino API. The API uses this to build a per-theme selector knowledge base and return automatic widget placement recommendations.
- **Widget appearance settings** — New admin options: show/hide provider logos (`COMFINO_WIDGET_SHOW_PROVIDER_LOGOS`), custom banner CSS URL (`COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL`), custom calculator CSS URL (`COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL`).
- **Structured shop environment in paywall and widget** — The paywall iframe and product-page widget now receive an identical, structured shop environment object (platform, theme, locale, page context) so the CDN-side widget SDK can apply a consistent selector profile.
- **Creditors configuration** — The admin panel now exposes a per-product-type creditor/lender list fetched from the Comfino API. The data is passed to the paywall frontend so the checkout widget can display provider logos and names alongside each financing offer.
- **Allowed Products Config** — Per-product-type term limits (min/max instalment count) are now configurable via the admin panel and injected into the paywall frontend, allowing the widget SDK to filter and pre-select offers according to merchant preferences.
- **Direct redirect mode** — New `COMFINO_PAYWALL_DIRECT_REDIRECT` admin toggle. When enabled, clicking "Pay with Comfino" skips the embedded paywall and redirects the customer straight to the Comfino payment page.
- **Custom paywall CSS URL** — New `COMFINO_PAYWALL_CUSTOM_CSS_URL` admin field allows injecting a merchant-supplied stylesheet into the paywall iframe for branding customization (separate from the product-widget CSS options).
- **Admin field server-side validation** — Configuration fields in the Comfino admin section are now validated on save: required fields reject empty values, numeric fields reject non-numeric input, and URL fields reject malformed absolute URLs. Invalid values are reported with a user-friendly error message instead of being silently persisted.

### Changed
- **`comfino/sdk-for-magento2` bumped to `^1.0.0-beta4`** — required for `RequestValidationError` support and other SDK-side fixes included in this release.
- **Order cancellation suppression moved to the SDK** — When the Comfino API reports an order as cancelled, the module no longer echoes a redundant cancel request back to the API. The suppression now relies on the SDK's `StatusApplicationContext` (entered automatically by `AbstractStatusAdapter::setStatus()`): `OrderObserver` skips the cancel request while `StatusApplicationContext::isActive()` is true. This replaces the previous module-local static flag on `ShopStatusManager`, which is now a pure constants holder again.
- **`OrderManager` delegated to SDK builders** — Cart and customer construction fully moved to `MagentoCartBuilder` and `MagentoCustomerBuilder` in `sdk-for-magento2`; `OrderManager` is now a thin DI coordinator with no business logic.
- **Magento Marketplace EQP compliance** — Module code validated against Magento Marketplace Extension Quality Program coding standards (PHP_CodeSniffer + PHPStan).

### Fixed
- Product category filter category ID persistence and admin panel rendering.
- Widget type admin dropdown stuck on `error` value after a failed API call — the widget script endpoint now guards against stale `core_config_data` rows that would otherwise serve a broken JS file.
- PHP 8.1 compatibility: explicit `int` cast added in monetary calculations (PLN → grosze conversion).
- **Loan amount accuracy** — The paywall now reads live totals directly from the Magento quote model instead of using cached values, so the displayed instalment amount and shipping cost are always consistent with the current cart state (applied discounts, selected shipping method, etc.).
- **Checkout validation error recovery** — When the Comfino API rejects an order creation request (HTTP 400, e.g., invalid phone number format) or a local validation check fails, the module now automatically cancels the orphaned Magento order, restores the customer's cart, and displays the real error message inline at the payment step. Previously these errors were caught by the generic handler, the API message was replaced with a non-descriptive fallback string, the customer was redirected to the failure page, and the cart contents were lost.
- **Inline payment error messages not rendered in Luma checkout** — Fixed an incorrect Knockout.js binding (`$parent.getRegion('messages')` → `getRegion('messages')`) in the Luma payment template. The message region was resolving against the parent payment-list component instead of the Comfino payment-method component, so errors reported via `messageContainer` (including validation failures) were never displayed to the customer.
- **Spurious cancel API calls for orders never submitted to Comfino** — `OrderObserver` no longer sends a cancellation request to the Comfino API for orders that were cancelled locally before a Comfino application was ever created (e.g. cleaned up after a validation failure). Such requests produced unnecessary `404` errors on the Comfino side. The guard uses the new `comfino_order_created` payment flag on orders created with this release; for older orders it falls back to checking whether the order previously held a `comfino_*` status.

### Security
- Enhanced iframe sandboxing and origin validation via PostMessage API.
- CSP policy configuration for external widget scripts.
- XSS vulnerabilities fixed in admin config field templates and frontend output — all user-controlled values are now properly escaped.
- **Log clearing CSRF protection** — The admin "Clear log" action now requires a valid `form_key` token, preventing the log from being wiped via a forged cross-site request.

## [3.1.0] - 2026-06-19

Improved 3.x version (ZIP-based legacy module, GitHub releases only).

## [3.0.0] - 2026-04-07

Initial public release (ZIP-based legacy module, GitHub releases only).

[Unreleased]: https://github.com/comfino/magento2/compare/4.0.0-beta3...HEAD
[4.0.0-beta3]: https://github.com/comfino/magento2/releases/tag/4.0.0-beta3
[3.1.0]: https://github.com/comfino/Magento-2.3/releases/tag/3.1.0
[3.0.0]: https://github.com/comfino/Magento-2.3/releases/tag/3.0.0
