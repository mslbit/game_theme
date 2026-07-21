/**
 * Oceanpayment 支付方式注册组件
 *
 * 将5种 Oceanpayment 支付方式注册到 Magento Checkout 的渲染器列表中，
 * 全部使用 op-renderer 通用渲染器。
 *
 * 两种托管结账模式（merchant_controlled / auto_redirect）共享同一套支付方式，
 * mode 仅影响跳转方式（由 op-renderer.js 处理），不影响支付方式注册。
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

        var component = 'Oceanpayment_Payment/js/view/payment/method-renderer/op-renderer';

        rendererList.push(
            {
                type: 'oceanpayment_creditcard',
                component: component
            },
            {
                type: 'oceanpayment_applepay',
                component: component
            },
            {
                type: 'oceanpayment_googlepay',
                component: component
            },
            {
                type: 'oceanpayment_wechatpay',
                component: component
            },
            {
                type: 'oceanpayment_alipay',
                component: component
            }
        );

        return Component.extend({});
    }
);
