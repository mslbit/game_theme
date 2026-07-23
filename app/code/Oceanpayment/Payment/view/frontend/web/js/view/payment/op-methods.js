/**
 * Oceanpayment 支付方式注册组件
 *
 * 将5种 Oceanpayment 支付方式注册到 Magento Checkout 的渲染器列表中。
 * Credit Card 在嵌入式模式下使用专用渲染器（credit-card-embedded-render），
 * 其他支付方式及 Credit Card 托管模式使用通用渲染器（op-renderer）。
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

        var defaultComponent = 'Oceanpayment_Payment/js/view/payment/method-renderer/op-renderer';
        var embeddedComponent = 'Oceanpayment_Payment/js/view/payment/method-renderer/credit-card-embedded-render';

        // 嵌入式模式：Credit Card 使用专用渲染器
        var isEmbedded = window.checkoutConfig.payment.oceanpayment_payment
            && window.checkoutConfig.payment.oceanpayment_payment.mode === 'embedded';

        rendererList.push(
            {
                type: 'oceanpayment_creditcard',
                component: isEmbedded ? embeddedComponent : defaultComponent
            },
            {
                type: 'oceanpayment_applepay',
                component: defaultComponent
            },
            {
                type: 'oceanpayment_googlepay',
                component: defaultComponent
            },
            {
                type: 'oceanpayment_wechatpay',
                component: defaultComponent
            },
            {
                type: 'oceanpayment_alipay',
                component: defaultComponent
            }
        );

        return Component.extend({});
    }
);
