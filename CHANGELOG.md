# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.1.0] - 2026-11-19

Requires `comfino/sdk-for-magento2` 1.1 (`comfino/php-sdk` 3.5, `comfino/php-api-client` 4.1). Security hardening and
the ComfinoPay rebrand; read **Upgrade notes** before deploying.

### Added

- **Promotion at checkout.** The ComfinoPay payment method can show the current shop promotion and its amount.
- **Promotional code.** A code entered by the customer in the ComfinoPay payment method is sent with the order; a blank
  or unusable code is ignored.
- **Direct redirect mode is passed with the order**, so ComfinoPay knows the paywall was skipped.
- **View statistics setting** (*Allow collecting ComfinoPay plugin view statistics*, off by default). When on, the
  widget and the paywall report their views and the shopper's interactions to ComfinoPay.
- **Masked, encrypted API keys.** The key fields show `******` and the last characters of the saved key; keys are stored
  encrypted. A new key that ComfinoPay rejects is replaced by the previous one. A new switch removes the saved test
  environment key.
- **Admin warnings for the request queue.** A message appears when queued order cancellations have waited more than 30
  minutes (Magento cron not running) or when a store view's requests are paused (usually a rejected API key).
- **More plugin diagnostics:** API connection state, queued and dropped requests, notifications with an invalid
  signature, and the last settings ComfinoPay changed remotely.
- **Developer settings:** *Accept notifications only from ComfinoPay servers* (off by default; when off, other senders
  are only logged) and *Allow ComfinoPay support to read the debug log* (on by default).

### Changed

- **"Comfino" is now "ComfinoPay":** admin labels, messages, order status labels and the default payment method title
  and text use the new name. Titles and labels you changed yourself are kept. Your settings, orders and integration
  are unchanged.
- **A missing or unavailable financing offer no longer blocks the order.** The first offer ComfinoPay returns for the
  cart is used (never instant payments or BLIK), and the customer can still change it on the ComfinoPay page.
- **Clearer checkout messages:** the customer is told when ComfinoPay is temporarily unavailable, and data ComfinoPay
  rejects is named field by field.
- **Order status updates from ComfinoPay change only orders paid with ComfinoPay**, and a repeated update adds no second
  history entry. "Cancelled by the shop" is no longer applied back to the order (added to stored lists that still have
  the previous default).
- **The request queue works per store view:** queued cancellations and error reports are sent with their store view's
  API key, only by Magento cron (no longer during payment notifications), and dropped after 7 days.
- **Fewer and more accurate error reports:** temporary outages are reported as warnings, rejected requests stay in the
  local log, and long error pages are shortened. API calls pause briefly after repeated failures.
- **ComfinoPay logs are written to daily files** (`var/log/comfino_debug-*.log`, `comfino_errors-*.log`, the last 30
  kept) with customer data masked; request bodies are logged only in debug mode.
- **Custom stylesheets must be `https://` addresses on your shop's domain.**
- **Uninstalling with `--remove-data` removes all ComfinoPay settings, queued requests, cached data and logs.** Orders
  are kept.
- **Cached ComfinoPay data (product types, creditors, promotions) is kept per store view.**

### Fixed

- **Widget and paywall not loading on multistore installations** where a website or store view uses its own API key;
  the key and API host of the saved scope are now used, and the diagnostics panel follows the selected scope.
- **Queued cancellations and error reports of a store view using a developer API host** are now sent to that store
  view's API host, and the logo in the admin settings follows the API host of the selected scope. Both used the host of
  the default store view.
- **Visitors of a cached product page no longer share one widget tracking ID.**
- **The error log panel stayed empty**, because errors were written to the debug log.
- **A cancellation is no longer sent back to ComfinoPay for an order ComfinoPay has already closed** (it caused
  timeouts).
- **Hyvä Theme checkout:** paywall statistics belong to the right checkout, and the custom paywall stylesheet is
  applied.
- **The *Service mode* description** now says what the setting does (it limits debug logging).

### Security

- **Webhook authentication hardened** (`ApiService`, used by the payment status, configuration and cache endpoints).
  Severity: high. Affects versions before 4.1.0.
- **Order status update authorization hardened** (`StatusAdapter`, used by payment status notifications). Severity:
  medium. Affects versions before 4.1.0.
- **Remote configuration validation hardened** (`RemoteValueConstraints`, used by the configuration endpoint).
  Severity: medium. Affects versions before 4.1.0.
- **Financing offer validation hardened** (`LoanSelection`, used by the checkout). Severity: medium. Affects versions
  before 4.1.0.
- **Log redaction hardened** (ComfinoPay debug and error logs). Severity: medium. Affects versions before 4.1.0.
- **API key storage hardened** (admin configuration). Severity: medium. Affects versions before 4.1.0.
- **Checkout redirect, admin links, storefront content security policy and checkout script output hardened**, and
  incoming notifications are rate-limited. Severity: low. Affects versions before 4.1.0.

### Upgrade notes

- **Run `bin/magento setup:upgrade` and `setup:di:compile`.** The upgrade encrypts the stored API keys, renames the
  default title and status labels, and renames the old single log files to `*-legacy.log`.
- **Change the Magento encryption key only with `bin/magento encryption:key:change`**; replacing it by hand makes the
  stored API keys unreadable.
- **Make sure Magento cron is running**; queued requests are sent only by cron. Let the queue drain before upgrading a
  busy shop.
- **If debug mode was ever on in 4.0.x, clear both ComfinoPay logs and replace your API key.**
- **Move custom stylesheets hosted elsewhere to your shop's domain.**
- **Multistore: re-save the ComfinoPay configuration once in Default Config** to refresh every store view's widget key.
- **Do not let a Varnish or CDN rule cache `comfino/widget/session`.**
- **Behind a reverse proxy, keep *Accept notifications only from ComfinoPay servers* off** unless the proxy passes the
  caller's address.
- **The promotion on Hyvä Checkout needs the matching update of the Hyvä Checkout compatibility extension.**

## [4.0.1] - 2026-07-26

### Security

- **Orders re-checked before a Comfino application is opened** — The payment endpoint now rejects orders not paid with Comfino, orders that already have an application, and orders past the payment stage. Prevents an order placed with another payment method being turned into a Comfino application, and prevents duplicate applications for one order.
- **Checkout tracking cookie now uses Magento's cookie API** — Keeps its `Secure`, `HttpOnly`, `SameSite=Lax` attributes and 15-minute lifetime; a cookie that cannot be sent is logged instead of silently skipped.
- **Admin warning messages are HTML-escaped** — Warnings in the Comfino system-information panel are now escaped like the success and error entries beside them.

### Fixed

- **Scheduled tasks did nothing** — Both ComfinoPay cron jobs failed silently on start-up. The outbound request queue is now actually drained on schedule, and the daily shop-environment report is actually sent.
- **Slow storefront during a ComfinoPay API outage** — An unreachable API added its full connection-retry time to every page load until it recovered. The affected request now backs off for 15 minutes after a failure and no longer runs on shopper-facing pages at all.
- **Duplicate update checks** — Concurrent admin page loads could each fire their own release-version check. Only one now runs at a time.
- **Update checks clustered across installations** — The version-check interval is now randomised to 20-28 hours (was a fixed 24), so shops drift apart instead of all polling at once.
- **Queued order cancellations lost on multistore installations** — When a website or store view used its own ComfinoPay API key, a cancellation that had to be retried was resent with the default store's key, rejected, and discarded without reaching ComfinoPay. Cancellations now remember which store they belong to and are retried with that store's credentials. Only retried cancellations were affected; ones that succeeded immediately were always sent correctly.

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
- **Hyvä Checkout integration** — Separate `comfino/magento2-hyva-checkout` sub-module.
- **PostMessage API** — Secure parent-iframe communication during checkout.
- **SDK integration** — Uses native PHP 8.1 enums, readonly properties, and Magento's native HTTP client, cache, and logging.
- **Shop environment auto-reporting** — On every admin config save the module sends the shop's platform, theme family, locale, and page-context data to the ComfinoPay API. The API uses this to build a per-theme selector knowledge base and return automatic widget placement recommendations.
- **Widget appearance settings** — New admin options: show/hide provider logos (`COMFINO_WIDGET_SHOW_PROVIDER_LOGOS`), custom banner CSS URL (`COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL`), custom calculator CSS URL (`COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL`).
- **Structured shop environment in paywall and widget** — The paywall iframe and product-page widget now receive an identical, structured shop environment object (platform, theme, locale, page context) so the CDN-side widget SDK can apply a consistent selector profile.
- **Creditors configuration** — The admin panel now exposes a per-product-type creditor/lender list fetched from the ComfinoPay API. The data is passed to the paywall frontend so the checkout widget can display provider logos and names alongside each financing offer.
- **Allowed Products Config** — Per-product-type term limits (min/max instalment count) are now configurable via the admin panel and injected into the paywall frontend, allowing the widget SDK to filter and pre-select offers according to merchant preferences.
- **Direct redirect mode** — New `COMFINO_PAYWALL_DIRECT_REDIRECT` admin toggle. When enabled, clicking "Pay with ComfinoPay" skips the embedded paywall and redirects the customer straight to the ComfinoPay payment page.
- **Custom paywall CSS URL** — New `COMFINO_PAYWALL_CUSTOM_CSS_URL` admin field allows injecting a merchant-supplied stylesheet into the paywall iframe for branding customization (separate from the product-widget CSS options).
- **Admin field server-side validation** — Configuration fields in the ComfinoPay admin section are now validated on save: required fields reject empty values, numeric fields reject non-numeric input, and URL fields reject malformed absolute URLs. Invalid values are reported with a user-friendly error message instead of being silently persisted.

### Changed

- **`comfino/sdk-for-magento2` bumped to `^1.0.0-beta4`** — required for `RequestValidationError` support and other SDK-side fixes included in this release.
- **Order cancellation suppression moved to the SDK** — When the ComfinoPay API reports an order as cancelled, the module no longer echoes a redundant cancel request back to the API. The suppression now relies on the SDK's `StatusApplicationContext` (entered automatically by `AbstractStatusAdapter::setStatus()`): `OrderObserver` skips the cancel request while `StatusApplicationContext::isActive()` is true. This replaces the previous module-local static flag on `ShopStatusManager`, which is now a pure constants holder again.
- **`OrderManager` delegated to SDK builders** — Cart and customer construction fully moved to `MagentoCartBuilder` and `MagentoCustomerBuilder` in `sdk-for-magento2`; `OrderManager` is now a thin DI coordinator with no business logic.
- **Magento Marketplace EQP compliance** — Module code validated against Magento Marketplace Extension Quality Program coding standards (PHP_CodeSniffer + PHPStan).

### Fixed

- Product category filter category ID persistence and admin panel rendering.
- Widget type admin dropdown stuck on `error` value after a failed API call — the widget script endpoint now guards against stale `core_config_data` rows that would otherwise serve a broken JS file.
- PHP 8.1 compatibility: explicit `int` cast added in monetary calculations (PLN → grosze conversion).
- **Loan amount accuracy** — The paywall now reads live totals directly from the Magento quote model instead of using cached values, so the displayed instalment amount and shipping cost are always consistent with the current cart state (applied discounts, selected shipping method, etc.).
- **Checkout validation error recovery** — When the ComfinoPay API rejects an order creation request (HTTP 400, e.g., invalid phone number format) or a local validation check fails, the module now automatically cancels the orphaned Magento order, restores the customer's cart, and displays the real error message inline at the payment step. Previously these errors were caught by the generic handler, the API message was replaced with a non-descriptive fallback string, the customer was redirected to the failure page, and the cart contents were lost.
- **Inline payment error messages not rendered in Luma checkout** — Fixed an incorrect Knockout.js binding (`$parent.getRegion('messages')` → `getRegion('messages')`) in the Luma payment template. The message region was resolving against the parent payment-list component instead of the ComfinoPay payment-method component, so errors reported via `messageContainer` (including validation failures) were never displayed to the customer.
- **Spurious cancel API calls for orders never submitted to ComfinoPay** — `OrderObserver` no longer sends a cancellation request to the ComfinoPay API for orders that were cancelled locally before a ComfinoPay application was ever created (e.g. cleaned up after a validation failure). Such requests produced unnecessary `404` errors on the ComfinoPay side. The guard uses the new `comfino_order_created` payment flag on orders created with this release; for older orders it falls back to checking whether the order previously held a `comfino_*` status.

### Security

- Enhanced iframe sandboxing and origin validation via PostMessage API.
- CSP policy configuration for external widget scripts.
- XSS vulnerabilities fixed in admin config field templates and frontend output — all user-controlled values are now properly escaped.
- **Log clearing CSRF protection** — The admin "Clear log" action now requires a valid `form_key` token, preventing the log from being wiped via a forged cross-site request.

## [3.1.0] - 2026-06-19

Improved 3.x version (ZIP-based legacy module, GitHub releases only).

## [3.0.0] - 2026-04-07

Initial public release (ZIP-based legacy module, GitHub releases only).

[Unreleased]: https://github.com/comfino/magento2/compare/4.1.0...HEAD
[4.1.0]: https://github.com/comfino/magento2/compare/4.0.1...4.1.0
[4.0.1]: https://github.com/comfino/magento2/compare/4.0.0...4.0.1
[4.0.0]: https://github.com/comfino/magento2/releases/tag/4.0.0
[3.1.0]: https://github.com/comfino/Magento-2.3/releases/tag/3.1.0
[3.0.0]: https://github.com/comfino/Magento-2.3/releases/tag/3.0.0
