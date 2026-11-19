/**
 * ComfinoPay payment method registrar.
 *
 * Adds the 'comfino' renderer to Magento's payment renderer list so the checkout discovers it.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'comfino',
        component: 'Comfino_ComfinoGateway/js/view/payment/method-renderer/comfino-method'
    });

    return Component.extend({});
});
