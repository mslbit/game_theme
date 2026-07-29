/**
 * 连连支付收银台渲染器
 *
 * 收银台模式（原生下单流程）：
 * 1. 用户点击 Place Order → 原生 placeOrder() → placeOrderAction 创建订单
 *    → 后端 authorize command 调连连 API 获取 payment_url（收银台地址）
 * 2. afterPlaceOrder() 调 get-pay-url API 获取 payment_url：
 *    - 有 payment_url → 跳转连连收银台
 *    - 无 payment_url → 跳转成功页（PS 场景）
 * 3. 用户在连连收银台完成支付后：
 *    - 同步跳转回 /lianlian/payment/back
 *    - 异步通知 /rest/V1/lianlian/notify → PaymentSuccessService → capture
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'MslPay_LianLian/js/action/get-pay-url'
], function (Component, fullScreenLoader, getPayUrl) {

    'use strict';

    return Component.extend({
        defaults: {
            template: 'MslPay_LianLian/payment/ll-payment'
        },

        redirectAfterPlaceOrder: false,

        /**
         * 下单成功后获取 payment_url 并跳转
         *
         * 原生 placeOrder() 在 .done() 时自动调用此方法
         * fullScreenLoader 阻止用户在 getPayUrl 期间重复操作
         */
        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();

            getPayUrl(this.getCode(), this.messageContainer)
                .done(function (payUrl) {
                    fullScreenLoader.stopLoader();

                    if (payUrl) {
                        window.location.replace(payUrl);
                    } else {
                        window.location.replace(
                            window.checkoutConfig.defaultSuccessPageUrl
                            || window.location.origin + '/checkout/onepage/success'
                        );
                    }
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                });
        },

        getLogoUrl: function () {
            var llConfig = window.checkoutConfig.payment.mslpay_lianlian || {};
            var methods = llConfig.methods || {};
            var methodConfig = methods[this.getCode()] || {};
            return methodConfig.logo_url || '';
        },

        getTitle: function () {
            var llConfig = window.checkoutConfig.payment.mslpay_lianlian || {};
            var methods = llConfig.methods || {};
            var methodConfig = methods[this.getCode()] || {};
            return methodConfig.title || this._super();
        }
    });
});
