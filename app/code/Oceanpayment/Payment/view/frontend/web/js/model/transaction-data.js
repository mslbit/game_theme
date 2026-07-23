/**
 * Oceanpayment 嵌入式支付交易数据持久模型
 *
 * 使用 ko.observable 存储交易数据，支付成功但下单失败时，
 * 重试可直接下单，无需重新发起支付。
 *
 * 数据流：iframe回调 → setTransactionData → getData() → additional_data
 */
define([
    'ko'
], function (ko) {
    'use strict';

    var transactionData = ko.observable({});

    return {
        /**
         * 获取交易数据
         * @returns {Object}
         */
        get: function () {
            return transactionData();
        },

        /**
         * 设置交易数据
         * @param {Object} data
         */
        set: function (data) {
            transactionData(data);
        },

        /**
         * 是否已有交易数据（支付已成功，可直接下单）
         * @returns {Boolean}
         */
        hasData: function () {
            var data = transactionData();
            return data && data.payment_id && data.payment_id.length > 0;
        },

        /**
         * 清除交易数据（下单成功后调用）
         */
        clear: function () {
            transactionData({});
        }
    };
});