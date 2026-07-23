/**
 * Oceanpayment 嵌入式支付交易数据持久模型
 *
 * 使用 ko.observable 存储卡信息和交易数据，
 * 支付成功但下单失败时可直接重试，无需重新发起支付。
 *
 * 数据流：iframe回调 → creditCardData.set() → getData() → additional_data
 */
define([
    'ko'
], function (ko) {
    'use strict';

    var cardData = ko.observable({});

    return {
        /**
         * 获取数据
         * @returns {Object}
         */
        get: function () {
            return cardData();
        },

        /**
         * 设置数据
         * @param {Object} data
         */
        set: function (data) {
            cardData(data);
        },

        /**
         * 是否已有卡数据（支付已成功，可直接下单）
         * @returns {Boolean}
         */
        hasCard: function () {
            var data = cardData();
            return data && data.card_data && data.card_data.length > 0;
        },

        /**
         * 清除数据（下单成功后调用）
         */
        clear: function () {
            cardData({});
        }
    };
});
