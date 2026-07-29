/**
 * Oceanpayment Google Pay 嵌入式支付渲染器
 *
 * 混合模式流程：
 * 1. onePageGooglePay.init() 渲染 Google Pay 按钮
 * 2. code==2 → 按钮加载成功，调后端 API 获取 checkout 数据 → checkout() 提交网关
 * 3. code==3 → 用户关闭弹窗
 * 4. 回调返回支付结果：
 *    - pay_url 存在（3D）→ 先 placeOrder → 再跳转3D验证
 *    - pay_url 为空（非3D）→ 正常 placeOrder
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
            template: 'Oceanpayment_Payment/embedded/google-pay-payment'
        },

        /**
         * 初始化 Google Pay SDK 和全局回调
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

                /* 注册 Google Pay 全局回调 */
                window.oceanpaymentGooglePayCallBack = function (data) {
                    self.handlePaymentCallback(data);
                };

                /* 动态加载 Google Pay SDK */
                var script = document.createElement('script');
                script.src = sdkUrl;
                script.async = true;
                script.onload = function () {
                    var retryCount = 0;
                    var tryInit = function () {
                        if (typeof onePageGooglePay !== 'undefined') {
                            onePageGooglePay.init(embeddedConfig.is_sandbox || '', {
                                cssUrl: '',
                                language: 'en_US',
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
         * Google Pay SDK 回调处理
         */
        handlePaymentCallback: function (data) {
            /* code==2：按钮加载成功，调后端 API 获取数据后 checkout */
            if (data.code === 2) {
                this._doGooglePayCheckout();
                return;
            }

            /* code==3：用户关闭了 Google Pay 弹窗 */
            if (data.code === 3) {
                this.isPlaceOrderActionAllowed(true);
                return;
            }

            /* 支付结果处理 */
            this._callbackData = data;
            this.isPlaceOrderActionAllowed(false);//禁用下单按钮

            if (data.pay_url) {
                this._placeOrderAndRedirect(data.pay_url);
            } else {
                this._placeOrderNormal();
            }
        },

        /**
         * 调后端 API 获取 checkout 数据，然后提交网关
         */
        _doGooglePayCheckout: function () {
            var self = this;

            if (typeof onePageGooglePay === 'undefined') {
                return;
            }

            getCheckoutData(this.getCode(), this.messageContainer)
                .done(function (formData) {
                    onePageGooglePay.checkout(formData);
                })
                .fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                });
        },

        /**
         * 3D 验证：placeOrder 后跳转 pay_url
         */
        _placeOrderAndRedirect: function (payUrl) {
            var self = this;

            fullScreenLoader.startLoader();

            $.when(placeOrderAction(this.getData(), this.messageContainer))
                .done(function () {
                    fullScreenLoader.stopLoader();
                    window.location.replace(payUrl);
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(true);
                });
        },

        /**
         * 非3D：placeOrder 后跳转成功页
         */
        _placeOrderNormal: function () {
            var self = this;

            fullScreenLoader.startLoader();

            $.when(placeOrderAction(this.getData(), this.messageContainer))
                .done(function () {
                    fullScreenLoader.stopLoader();
                    self.afterPlaceOrder();
                    window.location.replace(
                        window.checkoutConfig.defaultSuccessPageUrl
                        || window.location.origin + '/checkout/onepage/success'
                    );
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(true);
                });
        },

        /**
         * Google Pay 由 SDK 按钮触发，不需要默认 placeOrder 流程
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