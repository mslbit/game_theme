/**
 * Oceanpayment Apple Pay 嵌入式支付渲染器
 *
 * 混合模式流程：
 * 1. onePageApplePay.init() 渲染 Apple Pay 按钮
 * 2. code==2 → 按钮加载成功，调后端 API 获取 checkout 数据 → checkout() 提交网关
 * 3. code==3 → 用户关闭弹窗
 * 4. 回调返回支付结果：
 *    - pay_url 存在（3D）→ placeOrderAction 创建订单 → 跳转 pay_url
 *    - pay_url 为空（非3D）→ placeOrderAction 创建订单 → 跳转成功页
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/place-order',
    'Oceanpayment_Payment/js/action/get-checkout-data'
], function ($, Component, quote, fullScreenLoader, placeOrderAction, getCheckoutData) {

    'use strict';

    return Component.extend({
        redirectAfterPlaceOrder: false,
        defaults: {
            template: 'Oceanpayment_Payment/embedded/apple-pay-payment'
        },

        /**
         * 初始化 Apple Pay SDK 和全局回调
         */
        opinputInit: function () {
            var self = this;

            setTimeout(function () {
                var methodConfig = (window.checkoutConfig.payment.oceanpayment_payment.methods || {})[self.getCode()] || {};
                var embeddedConfig = methodConfig.embedded || {};
                var sdkUrl = embeddedConfig.sdk_url || '';

                if (!sdkUrl) {
                    return;
                }

                /* 注册 Apple Pay 全局回调 */
                window.oceanpaymentApplePayCallBack = function (data) {
                    self.handlePaymentCallback(data);
                };

                /* 动态加载 Apple Pay SDK */
                var script = document.createElement('script');
                script.src = sdkUrl;
                script.async = true;
                script.onload = function () {
                    var retryCount = 0;
                    var tryInit = function () {
                        if (typeof onePageApplePay !== 'undefined') {
                            onePageApplePay.init(embeddedConfig.is_sandbox || false, {
                                terminal: embeddedConfig.terminal || '',
                                cssUrl: '',
                                transactionInfo: {
                                    orderCurrency: quote.totals().base_currency_code || 'USD',
                                    orderAmount: quote.totals().grand_total
                                        ? parseFloat(quote.totals().grand_total).toFixed(2)
                                        : '0.00',
                                    billCountry: (quote.billingAddress() || {}).countryId || ''
                                },
                                buttonStyle: {}
                            });
                        } else if (retryCount < 10) {
                            retryCount++;
                            setTimeout(tryInit, 200);
                        }
                    };
                    tryInit();
                };
                document.head.appendChild(script);
            }, 300);
        },

        /**
         * Apple Pay SDK 回调处理
         */
        handlePaymentCallback: function (data) {
            var self = this;

            /* code==2：按钮加载成功，调后端 API 获取数据后 checkout */
            if (data.code === 2) {
                this._doApplePayCheckout();
                return;
            }

            /* code==3：用户关闭了 Apple Pay 弹窗 */
            if (data.code === 3) {
                this.isPlaceOrderActionAllowed(true);
                return;
            }

            /* 支付结果：直接调 placeOrderAction 创建订单 */
            fullScreenLoader.startLoader();
            this.isPlaceOrderActionAllowed(false);//禁用下单按钮

            $.when(placeOrderAction(this.getData(), this.messageContainer))
                .done(function () {
                    if (data.pay_url) {
                        fullScreenLoader.stopLoader();
                        window.location.replace(data.pay_url);
                    } else {
                        fullScreenLoader.stopLoader();
                        self.afterPlaceOrder();
                        window.location.replace(
                            window.checkoutConfig.defaultSuccessPageUrl
                            || window.location.origin + '/checkout/onepage/success'
                        );
                    }
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(true);
                });
        },

        /**
         * 调后端 API 获取 checkout 数据，然后提交网关
         */
        _doApplePayCheckout: function () {
            var self = this;

            if (typeof onePageApplePay === 'undefined') {
                return;
            }

            getCheckoutData(this.getCode(), this.messageContainer)
                .done(function (formData) {
                    onePageApplePay.checkout(formData);
                })
                .fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                });
        },

        /**
         * Apple Pay 由 SDK 按钮触发，placeOrder 在回调中通过 placeOrderAction 执行
         */
        getPlaceOrderDeferredObject: function () {
            return $.Deferred().promise();
        },

        getData: function () {
            return {
                method: this.item.method,
                additional_data: {}
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