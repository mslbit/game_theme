/**
 * Oceanpayment 嵌入式支付响应处理器
 *
 * 仅负责检查 iframe 回调中的错误信息，
 * 实际的 placeOrder 逻辑由 credit-card-embedded-render.js 的 handlePaymentCallback 处理
 */
define([
    'Magento_Ui/js/model/messageList',
    'mage/translate'
], function (globalMessageList, $t) {
    'use strict';

    return {
        /**
         * 检查回调中是否有错误
         *
         * @param {Object} response - iframe 回调的原始数据
         * @param {Object} messageContainer - 消息容器
         * @returns {Boolean} 是否有错误
         */
        checkError: function (response, messageContainer) {
            messageContainer = messageContainer || globalMessageList;

            if (response.msg) {
                messageContainer.addErrorMessage({
                    message: $t(response.msg)
                });
                return true;
            }

            return false;
        }
    };
});
