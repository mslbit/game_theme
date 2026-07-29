/**
 * 连连支付方式注册组件（仅收银台模式）
 */
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
                type: 'mslpay_lianlian_checkout',
                component: 'MslPay_LianLian/js/view/payment/method-renderer/ll-renderer'
            }
        );

        return Component.extend({});
    }
);
