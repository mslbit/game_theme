/**
 * Oceanpayment 嵌入式支付 SDK
 *
 * 基于官方 onepage-carddata.js 改写为 UMD 闭包模式，兼容多平台。
 * handleMessage 逻辑与官方完全一致（使用 == 松散比较）。
 *
 * API:
 * - Oceanpayment.init(isSandbox, cssUrl, language, publicKey, backUrl)

 * - Oceanpayment.validateResult()
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.Oceanpayment = factory();
    }
}(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    var gatewayUrl = 'https://secure.oceanpayment.com';

    function reinitIframe(heightData) {
        var iframe = document.getElementById('oceanpayment-iframe-region');
        try {
            if (heightData != undefined && heightData != '') {
                iframe.height = heightData;
            }
        } catch (ex) {
            console.log(ex);
            iframe.height = 200;
        }
    }

    window.addEventListener('message', function (e) {
        if (e.origin == 'https://secure.oceanpayment.com' || e.origin == 'https://test-secure.oceanpayment.com') {
            var code = e.data.code;
            reinitIframe(e.data.height);

            try {
                var cardData = e.data.card_data;
                var terminalIndex = e.data.toString().indexOf('terminal');
                if (terminalIndex != -1 || cardData != undefined || code != undefined) {
                    if (code != 1) {
                        delete e.data.height;
                        if (typeof window.oceanpaymentCallBack === 'function') {
                            window.oceanpaymentCallBack(e.data);
                        }
                    }
                }
            } catch (ex) {
                if (code != 1) {
                    delete e.data.height;
                    if (typeof window.oceanpaymentCallBack === 'function') {
                        window.oceanpaymentCallBack(e.data);
                    }
                }
            }
        }
    });

    return {
        init: function (isSandbox, cssUrl, language, publicKey, backUrl) {
            gatewayUrl = isSandbox
                ? 'https://test-secure.oceanpayment.com'
                : 'https://secure.oceanpayment.com';

            var container = document.getElementById('oceanpayment-element');
            if (!container) {
                return;
            }

            container.innerHTML = '<iframe id="oceanpayment-iframe-region"'
                + ' name="oceanpayment-iframe-region"'
                + ' width="100%"'
                + ' style="overflow-x:hidden;overflow-y:hidden;"'
                + ' src="' + gatewayUrl + '/gateway/direct/checkpage?quickPay=3&language=' + language + '"'
                + ' height="131" frameborder="0" seamless></iframe>';

            var iframe = document.getElementById('oceanpayment-iframe-region');

            iframe.onload = function () {
                try {
                    iframe.contentWindow.postMessage({
                        methodType: 'init',
                        cssUrl: cssUrl,
                        language: language,
                        publicKey: publicKey,
                        backUrl: backUrl || window.parent.location.href
                    }, gatewayUrl);
                } catch (ex) {
                    // iframe 尚未加载远程页面时 origin 不匹配，忽略
                }
            };
        },

	checkout : function($data){
        //获取iframe元素
        var iframe = document.getElementById("oceanpayment-iframe-region");
        //iframe网页IP:PORT
      
        console.log($data);
        //发送消息到iframe网页
        iframe.contentWindow.postMessage($data, gatewayUrl);
	},

        validateResult: function () {
            var iframe = document.getElementById('oceanpayment-iframe-region');
            if (!iframe) {
                return;
            }
            iframe.contentWindow.postMessage({
                methodType: 'check'
            }, gatewayUrl);
        }
    };
}));
