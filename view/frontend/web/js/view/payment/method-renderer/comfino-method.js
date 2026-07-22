/**
 * Comfino payment method renderer for Luma checkout theme
 *
 * Loads the Comfino SDK via native dynamic import() and passes paywall data to bootstrapPaywall().
 * The SDK handles all paywall lifecycle logic (init, iframe, offer selection) via MagentoPaywallController.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'mage/storage',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/error-processor',
    'mage/url',
    'Magento_Customer/js/customer-data'
], function (Component, quote, storage, fullScreenLoader, errorProcessor, url, customerData) {
    'use strict';

    /* Cache the SDK-load promise on a window, so repeated KO re-mounts share a single fetch. */
    let cachedSdk = null;

    function loadComfinoSdk(cfg)
    {
        if (window.__comfinoSdkPromise) {
            return window.__comfinoSdkPromise;
        }

        window.__comfinoSdkPromise = import(cfg.sdkScriptUrl)
            .then(function (ns) {
                cachedSdk = ns;
                return ns;
            })
            .catch(function (error) {
                window.__comfinoSdkPromise = null;
                return Promise.reject(error);
            });

        return window.__comfinoSdkPromise;
    }

    function resolveComfinoSdk()
    {
        return cachedSdk;
    }

    return Component.extend({
        defaults: {
            template: 'Comfino_ComfinoGateway/payment/comfino',
            redirectAfterPlaceOrder: false
        },

        initialize: function () {
            this._super();

            // Comfino payment method configuration
            const config = (window.checkoutConfig.payment || {}).comfino || {};

            /* Standard-rendering Comfino logo placeholder: a static CDN SVG the KO template binds via getDefaultLogoUrl(),
               marked data-comfino-logo so the SDK's DefaultPaymentMethodItemRenderer adopts this same <img> and swaps its
               src to the auth API logo. Plain component method (not an observable) — the value never changes after mount,
               matching getTitle()/getCode(). */
            this.defaultLogoUrl = config.defaultLogoUrl || '';

            // allowedProductTypes: null = no filter active, [] = all filtered (don't load SDK).
            if (Array.isArray(config.allowedProductTypes) && config.allowedProductTypes.length === 0) {
                return this;
            }

            /* Read live totals from the quote model — these include shipping chosen on the previous step, unlike
               window.checkoutConfig, which is frozen at page load time (before shipping is selected). Patch the
               server-built cart object so the paywall receives current totals/delivery values. */
            const totals = quote.totals();
            const loanAmount = totals ? Math.round((totals.grand_total || 0) * 100) : (config.loanAmount || 0);
            const cart = Object.assign({}, config.cart);

            cart.totalAmount = loanAmount;

            if (totals) {
                const shippingTaxAmount = Math.round((totals.shipping_tax_amount || 0) * 100);

                cart.deliveryCost = Math.round((totals.shipping_incl_tax || 0) * 100);
                cart.deliveryNetCost = Math.round((totals.shipping_amount || 0) * 100);
                cart.deliveryCostVatAmount = shippingTaxAmount;
                cart.deliveryCostVatRate = cart.deliveryNetCost > 0
                    ? Math.round(shippingTaxAmount / cart.deliveryNetCost * 100)
                    : 0;
            }

            /* All paywall bootstrap options assigned directly from checkoutConfig.payment.comfino — Magento's
               CompositeConfigProvider uses json_encode which preserves scalar types and the insertion order of
               associative arrays (creditors map ordering MUST survive end-to-end because the paywall renderer
               uses it literally). */
            const comfinoPaywallData = {
                authToken: config.authToken,
                loggingToken: config.loggingToken,
                trackId: config.trackId,
                loanAmount: loanAmount,
                platform: 'magento',
                environment: config.environment,
                loanAmountInputId: 'comfino-loan-amount',
                productTypes: config.allowedProductTypes,
                allowedProductsConfig: config.allowedProductsConfig,
                cart: cart,
                paywallSettings: config.paywallSettings,
                shopEnvironment: config.shopEnvironment,
                directRedirect: config.directRedirect,
                creditors: config.creditors,
                productTypeNames: config.productTypeNames,
                paymentMethodItem: { auth: config.paymentMethodAuth || '', label: config.paymentMethodLabel }
            };

            /* Keep the hidden #comfino-loan-amount input in sync with quote.totals and notify the SDK on every change.
               quote.totals fires after shipping selection, coupon application, and any other action that mutates the
               cart total — Magento's Knockout-based equivalent of WooCommerce's `updated_checkout` jQuery event.

               Contract with the SDK (MagentoPaywallController): the plugin writes the new grosze amount to the input
               value and dispatches a `comfino:loan-amount-changed` CustomEvent (bubbles, with `detail.amount`). The SDK
               listens at document level, so the subscription survives Knockout re-renders that replace the input node;
               it calls reload() only if the iframe is still attached. We never call ComfinoPaywallInit.reload()
               directly — keeping the reload trigger inside the SDK so the same input-event contract works for any
               future Magento theme (Hyvä, Magewire, headless) without plugin-side branching. */
            quote.totals.subscribe(function (totals) {
                if (!totals) {
                    return;
                }

                const amount = Math.round((totals.grand_total || 0) * 100);
                const input = document.getElementById('comfino-loan-amount');

                if (!input || amount <= 0) {
                    return;
                }

                input.value = String(amount);

                input.dispatchEvent(new CustomEvent('comfino:loan-amount-changed', {
                    bubbles: true,
                    detail: { amount: amount }
                }));
            });

            /* Load the Comfino SDK (ESM, type="module"). The resolved SDK reference is passed into
               bootstrapPaywall(). MagentoPaywallController uses waitForContainer to defer paywall creation
               until #comfino-paywall-container appears in the DOM (after KO renders the template). */

            /* Load the SDK eagerly on mount — the click-driven paywall lifecycle is now owned by the SDK
               (MagentoAdapter.subscribePaymentMethodSelection subscribes to quote.paymentMethod and routes
               selection/deselection into PaywallManager.activate()/deactivate()). Eager loading is required for
               the two-stage item-render gate to work: DefaultPaymentMethodItemRenderer.setReady() adds the
               `comfino-payment-method-item--ready` class during PaywallManager.initialize(), which the plugin's
               hide-by-default CSS gate keys on. If we deferred the SDK load to selection, the tile would never
               become visible for the shopper to click in the first place.

               bootstrapPaywall() returns immediately; iframe creation is deferred inside the SDK until either
               the adapter reports Comfino as pre-selected or the shopper picks it. */
            if (config.sdkScriptUrl) {
                loadComfinoSdk(config).then(function (sdk) {
                    if (sdk && typeof sdk.bootstrapPaywall === 'function') {
                        sdk.bootstrapPaywall(comfinoPaywallData);
                    }
                }).catch(function () {
                    /* script-load failed — leave checkout unaffected, gate stays closed */
                });
            }

            return this;
        },

        /** Static CDN URL of the default Comfino logo placeholder, bound by the KO template's <img data-bind>. */
        getDefaultLogoUrl: function () {
            return this.defaultLogoUrl || '';
        },

        /** Called by Magento on order placement to collect payment data.
         *
         * Source of truth is the SDK's BasePaywallController.latestLoanParams: if Knockout remounts the payment
         * template during checkout state transitions after the last iframe payment-state message, the freshly rendered
         * hidden inputs carry no `value` attribute and DOM reads would return empty strings, forcing the backend to
         * fall back to the default product. DOM inputs remain as fallback when the SDK accessor is unavailable. */
        getData: function () {
            const sdk = resolveComfinoSdk();
            const sdkParams = sdk?.BasePaywallController?.getLatestLoanParams?.() || null;
            const loanType = (sdkParams && sdkParams.loanType) || (document.getElementById('comfino-loan-type') || {}).value || '';
            const loanTerm = (sdkParams && sdkParams.loanTerm) || (document.getElementById('comfino-loan-term') || {}).value || '';

            return {
                method: this.item.method,
                additional_data: {
                    loanType: loanType,
                    loanTerm: String(loanTerm || '')
                }
            };
        },

        /**
         * Called after Magento order is placed successfully.
         * Sends the order to Comfino API and redirects to the Comfino application URL.
         */
        afterPlaceOrder: function () {
            const self = this;

            fullScreenLoader.startLoader();

            storage.post(url.build('rest/V1/comfino/payment'))
                .done(function (response) {
                    fullScreenLoader.stopLoader();

                    const data = response && response[0];

                    if (data && data.redirectUrl) {
                        window.location.replace(data.redirectUrl);

                        return;
                    }

                    /* Validation failure: backend canceled the orphaned order and restored the quote. Refresh the cart
                       section, show the error inline, and re-enable Place Order so the customer can correct the data
                       and retry without landing on the generic failure page. */
                    if (data && data.error) {
                        customerData.reload(['cart'], true);
                        self.messageContainer.addErrorMessage({ message: data.error });
                    }

                    self.isPlaceOrderActionAllowed(true);
                }).fail(function (response) {
                    fullScreenLoader.stopLoader();
                    errorProcessor.process(response, self.messageContainer);
                    self.isPlaceOrderActionAllowed(true);
                });
        }
    });
});
