define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'llpay_custompaymentoption',
                component: 'LianLian_LLPay/js/view/payment/method-renderer/custompayments-method'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
