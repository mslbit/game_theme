/**
 * Oceanpayment 嵌入式支付 checkout 数据获取
 *
 * 调用后端 REST API 获取 SDK.checkout() 所需的表单数据，
 * 敏感字段（account, terminal, signValue, key）由后端组装。
 *
 * 前端传递当前 quote 的 billingAddress 和 shippingAddress，
 * 因为用户可能在结账页修改过地址，后端需要最新数据。
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'Magento_Checkout/js/model/error-processor',
    'mage/storage'
], function ($, quote, urlBuilder, errorProcessor, storage) {

    'use strict';

    /**
     * 从 quote address 对象提取纯数据
     */
    function extractAddressData(address) {
        if (!address) {
            return {};
        }

        var addr = typeof address === 'function' ? address() : address;

        if (!addr) {
            return {};
        }

        return {
            firstname: addr.firstname || '',
            lastname: addr.lastname || '',
            email: addr.email || '',
            telephone: addr.telephone || '',
            countryId: addr.countryId || '',
            regionCode: addr.regionCode || (addr.region ? addr.region.region_code : '') || '',
            region: addr.region || '',
            city: addr.city || '',
            postcode: addr.postcode || '',
            street: addr.street || [],
            company: addr.company || ''
        };
    }

    return function (methodCode, messageContainer) {
        var quoteId = quote.getQuoteId();
        var deferred = $.Deferred();

        /* 收集当前 quote 的地址数据（用户可能修改过） */
        var addressData = {
            billingAddress: extractAddressData(quote.billingAddress()),
            shippingAddress: extractAddressData(quote.shippingAddress()),
            isVirtual: quote.isVirtual ? quote.isVirtual() : false
        };

        /* API 路由：/V1/oceanpayment/:quoteId/:methodCode/checkout-data */
        var serviceUrl = urlBuilder.createUrl('/oceanpayment/:quoteId/:methodCode/checkout-data', {
            quoteId: quoteId,
            methodCode: methodCode
        });

        storage.post(
            serviceUrl,
            JSON.stringify({addresses: JSON.stringify(addressData)})
        ).done(function (data) {
            /* 后端返回 JSON 字符串，可能已被自动解析 */
            var formData = (typeof data === 'string') ? JSON.parse(data) : data;
            deferred.resolve(formData);
        }).fail(function (response) {
            errorProcessor.process(response, messageContainer);
            deferred.reject(response);
        });

        return deferred.promise();
    };
});