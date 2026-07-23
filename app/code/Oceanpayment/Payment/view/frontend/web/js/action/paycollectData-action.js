/**
 * Oceanpayment 嵌入式支付 Action
 *
 * 在 getPlaceOrderDeferredObject 中调用（此时卡数据已就绪）：
 * 1. 将 card_data + cartId 发送到后端 pre-order API
 * 2. 后端组装签名 + cURL 到 Oceanpayment /gateway/direct/pay
 * 3. 后端返回支付结果（pay_url / payment_id / payment_status）
 * 4. 前端根据 pay_url 决定是否 3D 跳转
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'Magento_Customer/js/model/customer',
    'mage/storage',
    'Oceanpayment_Payment/js/model/credit-card-data',
    'Magento_Checkout/js/model/full-screen-loader'
], function ($, quote, urlBuilder, customer, storage, creditCardData, fullScreenLoader) {
    'use strict';

    return function (messageContainer) {
        var serviceUrl,
            payload,
            deferred = $.Deferred();

        var cardInfo = creditCardData.get();

        payload = {
            cartId: quote.getQuoteId(),
            cardData: cardInfo.card_data || ''
        };

        if (customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/oceanpayment/pre-order', {});
        } else {
            serviceUrl = urlBuilder.createUrl('/guest-oceanpayment/:cartId/pre-order', {
                cartId: quote.getQuoteId()
            });
            payload.email = quote.guestEmail;
        }

        fullScreenLoader.startLoader();

        storage.post(
            serviceUrl,
            JSON.stringify(payload),
            true
        ).done(function (response) {
            fullScreenLoader.stopLoader();

            var result = response[0] || response;

            // 更新 creditCardData 中的交易信息
            creditCardData.set({
                card_data: cardInfo.card_data || '',
                payment_id: result.payment_id || '',
                card_number: result.card_number || '',
                auth_type: result.payment_authType || '',
                pay_url: result.pay_url || '',
                payment_status: result.payment_status || ''
            });

            // 有 pay_url 说明需要 3D 验证
            if (result.pay_url) {
                deferred.resolve(result);
            } else if (result.payment_status === '1') {
                // 支付成功（非 3D）
                deferred.resolve(result);
            } else {
                // 支付失败
                var errMsg = result.payment_details || 'Payment failed';
                if (messageContainer) {
                    messageContainer.addErrorMessage({message: errMsg});
                }
                deferred.reject(errMsg);
            }

        }).fail(function (response) {
            fullScreenLoader.stopLoader();
            if (messageContainer) {
                messageContainer.addErrorMessage({
                    message: 'Payment request failed. Please try again.'
                });
            }
            deferred.reject(response);
        });

        return deferred.promise();
    };
});
