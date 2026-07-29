/**
 * Oceanpayment 嵌入式信用卡支付渲染器
 *
 * 混合模式流程：
 * 1. Oceanpayment.init() 渲染 iframe，用户输入卡号
 * 2. 用户点击 Place Order → getPlaceOrderDeferredObject() 被调用
 *    → 调后端 API 获取 checkout 表单数据 → Oceanpayment.checkout(formData) 提交网关
 *    → 返回不 resolve 的 deferred，阻止 Magento 默认 placeOrder 流程
 * 3. 回调 oceanpaymentCallBack 返回结果：
 *    - data.msg 存在 → 卡号错误，提示用户
 *    - pay_url 存在（3D）→ 调 placeOrderAction 创建订单 → 跳转 pay_url
 *    - pay_url 为空（非3D）→ 调 placeOrderAction 创建订单 → 跳转成功页
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
            template: 'Oceanpayment_Payment/embedded/credit-card-payment'
        },

        /**
         * 初始化 Oceanpayment iframe 和全局回调
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

                /* 注册全局回调 */
                window.oceanpaymentCallBack = function (data) {
                    self.handlePaymentCallback(data);
                };

                /* 动态加载官方 CC SDK */
                var script = document.createElement('script');
                script.src = sdkUrl;
                script.async = true;
                script.onload = function () {
                    var retryCount = 0;
                    var tryInit = function () {
                        if (typeof Oceanpayment !== 'undefined') {
                            Oceanpayment.init(
                                embeddedConfig.is_sandbox || false,
                                '',
                                embeddedConfig.language || 'en_US'
                            );
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
         * SDK 回调处理
         */
        handlePaymentCallback: function (rawData) {
            var self = this;

            /* 解析回调数据：SDK 可能返回 XML 字符串或已解析的对象 */
            var data = this._parseCallbackData(rawData);

            if (!data) {
                this.messageContainer.addErrorMessage({message: 'Payment callback data invalid'});
                this.isPlaceOrderActionAllowed(true);
                return;
            }

            /* 卡号校验错误 */
            if (data.msg) {
                this.messageContainer.addErrorMessage({message: data.msg});
                this.isPlaceOrderActionAllowed(true);
                return;
            }

            /* 支付失败判断：
             * - payment_status=0 且 payment_details 含 "20061:Duplicate order"：
             *   网关已处理过此支付（上次提交了但后端没创建订单），直接走 placeOrder 创建订单
             * - payment_status!=1 且无 pay_url：真正的支付失败，提示用户
             */
            var isDuplicateOrder = data.payment_details
                && data.payment_details.indexOf('20061:Duplicate order') !== -1;

            if (parseInt(data.payment_status) !== 1 && !data.pay_url && !isDuplicateOrder) {
                var failMsg = data.payment_details || 'Payment failed';
                this.messageContainer.addErrorMessage({message: failMsg});
                this.isPlaceOrderActionAllowed(true);
                return;
            }

            /* 网关已处理，现在需要创建 Magento 订单 */
            fullScreenLoader.startLoader();
              this.isPlaceOrderActionAllowed(false);//禁用下单按钮

            $.when(placeOrderAction(this.getData(), this.messageContainer))
                .done(function () {
                    if (data.pay_url) {
                        /* 3D 验证：跳转 pay_url */
                        fullScreenLoader.stopLoader();
                        window.location.replace(data.pay_url);
                    } else {
                        /* 非3D：跳转成功页 */
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
         * 解析回调数据
         */
        _parseCallbackData: function (rawData) {
            if (!rawData) {
                return null;
            }

            /* 已经是对象 */
            if (typeof rawData === 'object') {
                return rawData;
            }

            /* XML 字符串解析 */
            if (typeof rawData === 'string') {
                try {
                    var parser = new DOMParser();
                    var xmlDoc = parser.parseFromString(rawData, 'text/xml');
                    var result = {};
                    var children = xmlDoc.documentElement.children;

                    for (var i = 0; i < children.length; i++) {
                        result[children[i].tagName] = children[i].textContent;
                    }

                    return result;
                } catch (e) {
                    return null;
                }
            }

            return null;
        },

        /**
         * Place Order 入口
         *
         * 返回不 resolve 的 deferred，等待回调处理
         */
        getPlaceOrderDeferredObject: function () {
            var self = this;

            if (typeof Oceanpayment === 'undefined') {
                this.messageContainer.addErrorMessage({
                    message: 'Payment system is loading, please wait...'
                });
                return $.Deferred().reject().promise();
            }

            fullScreenLoader.startLoader();
             this.isPlaceOrderActionAllowed(false);//禁用下单按钮

            /* 从后端获取 checkout 表单数据（含签名、key 等敏感字段） */
            getCheckoutData(this.getCode(), this.messageContainer)
                .done(function (formData) {
                    fullScreenLoader.stopLoader();
                    /* 调用 SDK.checkout()，由 iframe 提交到网关 */
                    Oceanpayment.checkout(formData);
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(true);
                });

            /* 返回不 resolve 的 deferred，等待回调处理 */
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
