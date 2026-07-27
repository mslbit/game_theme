/**
 * Oceanpayment 获取 pay_url Action
 *
 * 调用 REST API 根据 quoteId 获取 pay_url（3D验证跳转地址）。
 * 返回 jQuery Deferred，resolve 时传入 pay_url 字符串（可能为 null）。
 * 跳转逻辑由调用方（renderer）负责。
 */
define(
    [
        'jquery',
        'Magento_Checkout/js/model/url-builder',
        'Magento_Checkout/js/model/error-processor',
        'mage/storage',
        'Magento_Checkout/js/model/full-screen-loader',
    ],
    function (
        $,
        urlBuilder,
        errorProcessor,
        storage,
        fullScreenLoader
    ) {
        'use strict';

        return function (quoteId, messageContainer) {
            /* API 路由：/V1/oceanpayment/:quoteId/place-pay */
            var serviceUrl = urlBuilder.createUrl('/oceanpayment/:quoteId/place-pay', {
                quoteId: quoteId
            });

            var deferred = $.Deferred();

            fullScreenLoader.startLoader();
            storage.get(serviceUrl)
                .done(function (payUrl) {
                    deferred.resolve(payUrl || null);
                })
                .fail(function (response) {
                    errorProcessor.process(response, messageContainer);
                    deferred.reject(response);
                });

            return deferred.promise();
        };
    }
);
