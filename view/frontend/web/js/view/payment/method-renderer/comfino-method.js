/**
 * ComfinoPay payment method renderer for the Luma checkout.
 *
 * Loads the ComfinoPay SDK via dynamic import() and hands the paywall data to bootstrapPaywall().
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

    /* The SDK load promise is kept on window, so repeated KO re-mounts share a single fetch. */
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

    /**
     * Reads the promotion hint data ({flags, flagAttributes}) from the #comfino-remote-flags JSON element.
     */
    function readRemoteFlags() {
        const element = document.getElementById('comfino-remote-flags');

        if (!element) {
            return {};
        }

        try {
            const data = JSON.parse(element.textContent || '{}');

            return data && typeof data === 'object' ? data : {};
        } catch (e) {
            return {};
        }
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

            const config = (window.checkoutConfig.payment || {}).comfino || {};

            /* Static CDN placeholder logo bound by the KO template. The SDK adopts the <img data-comfino-logo> and
               swaps its src to the logo served by the API. Not an observable - it never changes after mount. */
            this.defaultLogoUrl = config.defaultLogoUrl || '';

            // allowedProductTypes: null = no filter, [] = everything filtered out (the SDK is not loaded)
            if (Array.isArray(config.allowedProductTypes) && config.allowedProductTypes.length === 0) {
                return this;
            }

            /* Live quote totals include the shipping chosen on the previous step, unlike window.checkoutConfig, which
               is frozen at page load. They override the totals and delivery values of the server-built cart. */
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

            /* Promotion hint data: written by the module into its own JSON element (escaped for HTML), not into
               window.checkoutConfig. Missing or unreadable data leaves the hint off. */
            const remoteFlags = readRemoteFlags();

            /* Bootstrap options taken from checkoutConfig.payment.comfino. The creditors map order must be kept as
               received - the paywall renderer uses it literally. */
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
                trackingConsent: config.trackingConsent === true,
                creditors: config.creditors,
                productTypeNames: config.productTypeNames,
                paymentMethodItem: { auth: config.paymentMethodAuth || '', label: config.paymentMethodLabel },
                flags: remoteFlags.flags || null,
                flagAttributes: remoteFlags.flagAttributes || null
            };

            /* On every quote.totals change write the amount in grosze to #comfino-loan-amount and dispatch a bubbling
               `comfino:loan-amount-changed` event; the SDK listens on document, so it survives KO re-renders. */
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

            /* The SDK is loaded eagerly on mount: it adds the `comfino-payment-method-item--ready` class that reveals
               the payment tile (hidden by default in CSS), so a lazy load would leave the tile unclickable. The SDK
               creates the iframe only when ComfinoPay is pre-selected or picked by the shopper. */
            if (config.sdkScriptUrl) {
                loadComfinoSdk(config).then(function (sdk) {
                    if (sdk && typeof sdk.bootstrapPaywall === 'function') {
                        sdk.bootstrapPaywall(comfinoPaywallData);
                    }
                }).catch(function () {
                    /* SDK load failed - the checkout stays unaffected and the tile stays hidden. */
                });
            }

            return this;
        },

        /** Static CDN URL of the default ComfinoPay logo placeholder, bound by the KO template's <img data-bind>. */
        getDefaultLogoUrl: function () {
            return this.defaultLogoUrl || '';
        },

        /**
         * Called by placeOrder() after all validations passed: records the payment start (click_go_to_payment) in the
         * SDK activity tracker. Not done in getData(), which Magento also calls on payment method selection.
         */
        getPlaceOrderDeferredObject: function () {
            const sdk = resolveComfinoSdk();

            if (sdk && sdk.BasePaywallController && typeof sdk.BasePaywallController.trackPaymentStart === 'function') {
                sdk.BasePaywallController.trackPaymentStart();
            }

            return this._super();
        },

        /**
         * Collects the payment data on order placement. The SDK's latest loan params win: after a KO remount the hidden
         * inputs are empty and serve only as a fallback.
         */
        getData: function () {
            const sdk = resolveComfinoSdk();
            const sdkParams = sdk?.BasePaywallController?.getLatestLoanParams?.() || null;
            const loanType = (sdkParams && sdkParams.loanType) || (document.getElementById('comfino-loan-type') || {}).value || '';
            const loanTerm = (sdkParams && sdkParams.loanTerm) || (document.getElementById('comfino-loan-term') || {}).value || '';

            // Optional promo code entered in the paywall iframe; omitted when empty.
            const promoCode = String(sdkParams?.promoCode || (document.getElementById('comfino-promo-code') || {}).value || '').trim();
            const additionalData = { loanType: loanType, loanTerm: String(loanTerm || '') };

            if (promoCode !== '') {
                additionalData.promoCode = promoCode;
            }

            // Track ID of the paywall events, so the order gets the promotion the shopper saw.
            const trackId = (sdk && sdk.BasePaywallController && typeof sdk.BasePaywallController.getTrackId === 'function'
                ? sdk.BasePaywallController.getTrackId()
                : null) || (document.getElementById('comfino-track-id') || {}).value || '';

            if (trackId !== '') {
                additionalData.trackId = String(trackId);
            }

            return {
                method: this.item.method,
                additional_data: additionalData
            };
        },

        /**
         * Creates the ComfinoPay order after the Magento order is placed and redirects to the ComfinoPay application.
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

                    /* The backend canceled the order and restored the quote: refresh the cart, show the error inline
                       and re-enable Place Order so the customer can retry. */
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
