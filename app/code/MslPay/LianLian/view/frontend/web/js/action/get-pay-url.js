/**
 * 连连支付获取 payment_url Action
 *
 * placeOrder 完成后，调后端 API 获取缓存的 payment_url（3DS 跳转地址）。
 * 与 iframe/收银台模式一致：authorize command 缓存 payment_url，前端取回判断跳转。
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage'
], function ($, quote, urlBuilder, storage) {

    'use strict';

    return function (methodCode, messageContainer) {
        var serviceUrl;

        if (window.checkoutConfig.isCustomerLoggedIn) {
            serviceUrl = urlBuilder.createUrl('/lianlian/:quoteId/:methodCode/pay-url', {
                quoteId: quote.getQuoteId(),
                methodCode: methodCode
            });
        } else {
            serviceUrl = urlBuilder.createUrl('/guest-lianlian/:quoteId/:methodCode/pay-url', {
                quoteId: quote.getQuoteId(),
                methodCode: methodCode
            });
        }

        return storage.get(serviceUrl);
    };
});