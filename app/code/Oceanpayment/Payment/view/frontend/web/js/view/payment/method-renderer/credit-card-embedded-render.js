/**
 * Oceanpayment 嵌入式信用卡支付渲染器
 *
 * 流程（先下单再跳 3D，避免支付成功但下单失败无法回滚）：
 * 1. 用户在 iframe 输入卡号 → 回调存入 creditCardData
 * 2. 用户点击 Place Order → getPlaceOrderDeferredObject()
 *    - 无卡数据 → rejected
 *    - 有卡数据 → paycollectDataAction（后端 cURL 到 /gateway/direct/pay）
 *      → 支付成功/有 pay_url → 先 placeOrder 下单
 *        → 下单成功且有 pay_url → 302 跳转 3D
 *      → 支付失败 → rejected
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/place-order',
    'Magento_Checkout/js/model/full-screen-loader',
    'Oceanpayment_Payment/js/action/paycollectData-action',
    'Oceanpayment_Payment/js/oceanpayment',
    'Oceanpayment_Payment/js/model/credit-card-data'
], function ($, Component, placeOrderAction, loader, paycollectDataAction, Oceanpayment, creditCardData) {

    'use strict';

    return Component.extend({
        defaults: {
            template: 'Oceanpayment_Payment/embedded/credit-card-payment'
        },

        opinputInit: function () {
            var self = this;

            setTimeout(function () {
                var embeddedConfig = window.checkoutConfig.payment.oceanpayment_payment.embedded || {};
                if (typeof Oceanpayment !== 'undefined' && typeof Oceanpayment.init === 'function') {
                    Oceanpayment.init(
                        embeddedConfig.is_sandbox || false,
                        '',
                        embeddedConfig.language || 'en',
                        embeddedConfig.public_key || '',
                        embeddedConfig.back_url || window.location.origin
                    );
                }

                window.oceanpaymentCallBack = function (data) {
                    self.handlePaymentCallback(data);
                };
            }, 300);
        },

        handlePaymentCallback: function (data) {
            if (typeof data === 'object' && data.card_data !== undefined) {
                if (data.errorMsg) {
                    this.messageContainer.addErrorMessage({message: data.errorMsg});
                    return;
                }

                creditCardData.set({
                    card_data: data.card_data || '',
                    payment_id: data.payment_id || '',
                    card_number: data.card_number || '',
                    auth_type: data.auth_type || ''
                });
            }
        },

        getPlaceOrderDeferredObject: function () {
            if (!creditCardData.hasCard()) {
                this.messageContainer.addErrorMessage({
                    message: 'Please complete card information first'
                });
                return $.Deferred().reject().promise();
            }

            var self = this;

            // 先调后端 API 发起支付，再下单，最后跳 3D
            return paycollectDataAction(this.messageContainer).then(function (result) {

                // 先 placeOrder 下单，确保订单创建成功
                return $.when(
                    placeOrderAction(self.getData(), self.messageContainer)
                ).then(function () {
                    // 下单成功，有 pay_url → 3D 验证跳转
                    if (result.pay_url) {
                        window.location.replace(result.pay_url);
                        return $.Deferred().promise();
                    }
                    // 非 3D，支付成功，正常走 Magento 成功页跳转
                });

            }, function (error) {
                if (error && typeof error === 'string') {
                    self.messageContainer.addErrorMessage({message: error});
                }
                return $.Deferred().reject().promise();
            });
        },

        getData: function () {
            return {
                method: this.item.method,
                additional_data: creditCardData.get()
            };
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
});
