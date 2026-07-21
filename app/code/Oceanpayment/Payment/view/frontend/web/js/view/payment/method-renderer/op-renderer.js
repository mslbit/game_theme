/**
 * Oceanpayment 通用支付方式渲染器
 *
 * 5种支付方式共用（Credit Card, Apple Pay, Google Pay, WeChat Pay, Alipay）。
 * 托管收银模式下，重写 getPlaceOrderDeferredObject：
 * 1. 调用 placePay action（一步完成 placeOrder + 获取 pay_url）
 * 2. 拿到 pay_url 后整页跳转到 Oceanpayment 支付页
 *
 * 订单已落库，购物车已清空，统一使用 window.location.replace 整页跳转。
 */
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Checkout/js/model/full-screen-loader',
        'Oceanpayment_Payment/js/action/place-pay'
    ],
    function (
        $,
        Component,
        errorProcessor,
        fullScreenLoader,
        placePayAction
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Oceanpayment_Payment/payment/op-payment'
            },

            _isProcessing: false,

            initialize: function () {
                this._super();
                this.redirectAfterPlaceOrder = false;
                return this;
            },

            getPlaceOrderDeferredObject: function () {
                var self = this;

                if (this._isProcessing) {
                    return $.Deferred().reject();
                }

                this._isProcessing = true;
                fullScreenLoader.startLoader();

                return $.when(
                    placePayAction(this.getData())
                ).done(
                    function (payUrl) {
                        fullScreenLoader.stopLoader();

                        if (payUrl) {
                            window.location.replace(payUrl);
                        } else {
                            self._isProcessing = false;
                            self.isPlaceOrderActionAllowed(true);
                        }
                    }
                ).fail(
                    function (response) {
                        fullScreenLoader.stopLoader();
                        self._isProcessing = false;
                        errorProcessor.process(response, self.messageContainer);
                    }
                );
            },

            getLogoUrl: function () {
                var methods = window.checkoutConfig.payment.oceanpayment_payment.methods || {};
                var methodConfig = methods[this.getCode()] || {};
                return methodConfig.logo_url || '';
            },

            getTitle: function () {
                var methods = window.checkoutConfig.payment.oceanpayment_payment.methods || {};
                var methodConfig = methods[this.getCode()] || {};
                return methodConfig.title || this._super();
            }
        });
    }
);
