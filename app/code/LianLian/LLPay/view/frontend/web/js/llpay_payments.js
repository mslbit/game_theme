// Global Namespace
let llpay =
    {
        llpayJs: null,
        token: null,
        urlBuilder: null,
        storage: null,
        // Methods
        initLLPayJs: function (token, locale, callback, LLPayJs) {
            'use strict';
            let message = null;

            if (!llpay.llpayJs) {
                llpay.llpayJs = LLPayJs;
            }

            if (!token) {
                callback('连连token有问题');
            }

            if (callback) {
                callback(message);
            }
        },
        initStripeElements: function () {
            'use strict';

            if (document.getElementById('llpay-card-element') === null) {
                return;
            }

            if (!llpay.llpayJs) {
                return;
            }

            let elements = llpay.llpayJs.elements(), card,
                style = {
                    base: {
                        backgroundColor: '#f8f8f8',
                        bolderColor: '#f1f1f1',
                        color: '#bcbcbc',
                        fontWeight: '400',
                        fontFamily: 'Roboto, Open Sans, Segoe UI, sans-serif',
                        fontSize: '14px',
                        fontSmoothing: 'antialiased',
                        floatLabelSize: '12px',
                        floatLabelColor: '#333333',
                        floatLabelWeight: '100'
                    }
            };

            card = elements.create('card', {
                token: llpay.token,
                style: style,
                apiType: '',
                merchantUrl: window.location.href
            });

            card.mount('#llpay-card-element');
        }
    };

/* eslint-disable */
    initLLPay = function (params, callback) {
        'use strict';

        if (typeof callback == 'undefined') {
            callback = null;
        }

        require(
            ['LLPayJs', 'LLPayJsPro', 'domReady!', 'mage/mage', 'mage/url', 'mage/storage'],
            function (LLPayJs, LLPayJsPro, domReady, mage, urlBuilder, storage) {
                let env = window.checkoutConfig.payment['llpay_custompaymentoption'].env,
                    jsComponents = '';
                if (env) {
                    jsComponents = LLPayJs;
                } else {
                    jsComponents = LLPayJsPro;
                }
                llpay.urlBuilder = urlBuilder;
                llpay.storage = storage;
                llpay.token = params.token;
                llpay.initLLPayJs(params.token, params.locale, callback, jsComponents);
            }
        );
    };
/* eslint-enable */
