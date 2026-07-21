/**
 * Oceanpayment 发起支付 Action
 *
 * 调用自定义 REST API 一步完成 placeOrder + 获取 pay_url。
 * 返回 jQuery Deferred 对象，由 renderer 的 getPlaceOrderDeferredObject 调用。
 *
 * 区分登录用户和访客用户，调用不同的 API 端点。
 */
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'Magento_Customer/js/model/customer',
        'mage/storage'
    ],
    function (
        $,
        quote,
        urlBuilder,
        customer,
        storage
    ) {
        'use strict';

        return function (paymentData) {
            var serviceUrl,
                payload;

            payload = {
                cartId: quote.getQuoteId(),
                paymentMethod: paymentData,
                billingAddress: quote.billingAddress()
            };

            if (customer.isLoggedIn()) {
                serviceUrl = urlBuilder.createUrl('/oceanpayment/place-pay', {});
            } else {
                serviceUrl = urlBuilder.createUrl('/guest-oceanpayment/:cartId/place-pay', {
                    cartId: quote.getQuoteId()
                });
                payload.email = quote.guestEmail;
            }

            return storage.post(
                serviceUrl,
                JSON.stringify(payload),
                true
            );
        };
    }
);
