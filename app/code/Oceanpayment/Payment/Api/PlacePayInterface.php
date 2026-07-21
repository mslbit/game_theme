<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Api;

/**
 * Oceanpayment 发起支付 REST API 接口
 *
 * 自定义 REST API 端点，替代 Magento 原生的 placeOrder + 重定向流程：
 * 1. 内部调用 Magento 原生的 savePaymentInformationAndPlaceOrder
 * 2. 从订单 payment 的 additional_information 中读取 pay_url
 * 3. 直接返回 pay_url，前端拿到后跳转到 Oceanpayment 托管收银页面
 *
 * 端点路由：
 * - 登录用户：POST /rest/V1/oceanpayment/place-pay
 * - 访客用户：POST /rest/V1/guest-oceanpayment/place-pay
 */
interface PlacePayInterface
{
    /**
     * 发起支付（登录用户）
     *
     * @param int $cartId 购物车 ID
     * @param \Magento\Quote\Api\Data\PaymentInterface $paymentMethod 支付方式
     * @param \Magento\Quote\Api\Data\AddressInterface|null $billingAddress 账单地址
     * @return string pay_url 支付跳转地址
     */
    public function placePay(
        int $cartId,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        ?\Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ): string;

    /**
     * 发起支付（访客用户）
     *
     * @param string $cartId 购物车 ID（masked）
     * @param string $email 访客邮箱
     * @param \Magento\Quote\Api\Data\PaymentInterface $paymentMethod 支付方式
     * @param \Magento\Quote\Api\Data\AddressInterface|null $billingAddress 账单地址
     * @return string pay_url 支付跳转地址
     */
    public function guestPlacePay(
        string $cartId,
        string $email,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        ?\Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ): string;
}