/**
 * Oceanpayment 支付方式注册组件
 *
 * 仅注册嵌入式支付方式：
 * - 嵌入式 CC：使用 credit-card-embedded-render（iframe 模式）
 * - 嵌入式 ApplePay：使用 applepay-embedded-render（SDK 按钮模式）
 * - 嵌入式 GooglePay：使用 googlepay-embedded-render（SDK 按钮模式）
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

        var ccComponent = 'Oceanpayment_Payment/js/view/payment/method-renderer/credit-card-embedded-render';
        var applePayComponent = 'Oceanpayment_Payment/js/view/payment/method-renderer/applepay-embedded-render';
        var googlePayComponent = 'Oceanpayment_Payment/js/view/payment/method-renderer/googlepay-embedded-render';

        rendererList.push(
            {
                type: 'oceanpayment_creditcard',
                component: ccComponent
            },
            {
                type: 'oceanpayment_applepay',
                component: applePayComponent
            },
            {
                type: 'oceanpayment_googlepay',
                component: googlePayComponent
            }
        );

        return Component.extend({});
    }
);
