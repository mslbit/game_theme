define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        $t,
        url,
        $,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction
    ) {
        'use strict';
        return Component.extend({

            defaults: {
                template: 'LianLian_LLPay/payment/lianlianpay'
            },

            redirectAfterPlaceOrder: false,
            isPlaceOrderActionAllowed: ko.observable(quote.billingAddress() != null),
            ll_token: ko.observable(''),
            ll_iframe_token: ko.observable(''),


            initialize: function () {
                this._super();
                let _this = this;

                if (window.checkoutConfig?.payment[this.getCode()]?.token === 'undefined') {
                    _this.ll_iframe_toke = null;
                } else {
                    _this.ll_iframe_token = window.checkoutConfig.payment[this.getCode()].token;
                }


                _this.ll_token.subscribe(function (value) {
                    if (value) {
                        _this.placeOrder();
                    }
                });

                return this;
            },

            getPlaceOrderDeferredObject: function () {
                return $.when(
                    placeOrderAction(this.getData())
                );
            },


            /**
             * Place order.
             */
            placeOrder: function (data, event) {
                var self = this;

                if (event) {
                    event.preventDefault();
                }

                if (this.validate() &&
                    additionalValidators.validate()
                ) {
                    this.isPlaceOrderActionAllowed(false);
                    this.getPlaceOrderDeferredObject()
                        .done(
                            function () {
                                self.afterPlaceOrder();
                            }
                        ).always(
                            function () {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        );

                    return true;
                }
                return false;
            },

            onCheckoutFormRendered: function () {
                const params = window.checkoutConfig.payment[this.getCode()];
                /* eslint-disable */
                initLLPay(params, function (err) {
                    if (err) {
                        console.log(err);
                    } else {
                        llpay.initStripeElements(params.locale);
                    }
                });
                /* eslint-enable */
            },
            getCode: function () {
                return 'llpay_custompaymentoption';
            },
            validate: function () {
                return !!this.ll_token();
            },

            buildJumpUrl: function (data) {
                return url.build(
                    `llpay/index/jump?message=
                    ${data.message}&order_id=${data.order_id}&increment_id=${data.increment_id}`
                );
            },

            handleLLCallBack: function (data) {
                //3ds
                if (data.tds === 'CHALLENGE') {
                    window.location.href = data.payment_url;
                    return true;
                }

                //payment success
                if (data.ll_order_status === 'PS') {
                    redirectOnSuccessAction.execute();
                    return true;
                }
                window.location.href = this.buildJumpUrl(data);
                $('body').trigger('processStop');
            },

            /**
             * After place order callback
             */
            afterPlaceOrder: function () {
                let _this = this;

                // Override this function and put after place order logic here
                $.ajax({
                    showLoader: true,
                    url: url.build('llpay/index/index'),
                    type: 'GET',
                    dataType: 'json'
                }).done(function (data) {
                    if (data.status !== 'SUCCESS') {
                        $('body').trigger('processStop');
                        window.location.href = _this.buildJumpUrl(data);
                    }
                    if (data.status === 'SUCCESS') {
                        _this.handleLLCallBack(data);
                    }
                });
                return true;
            },
            /* eslint-disable */

            createLLToken: function () {
                let _this = this;

                _this.isPlaceOrderActionAllowed(false);
                $('body').trigger('processStart');
                //连连支付基本验证
                llpay.llpayJs.getValidateResult().then((res) => {
                    if (res && !res.validateResult) {
                        console.log('校验不通过，支付取消');
                    } else {
                        llpay.llpayJs.confirmPay().then(function (result) {
                            if (result && result.data) {
                                _this.ll_token(result.data);
                            }
                        });
                    }
                });
            },
            /* eslint-enable */


            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'card_token': this.ll_token()
                    }
                };
            }

        });
    }
);
