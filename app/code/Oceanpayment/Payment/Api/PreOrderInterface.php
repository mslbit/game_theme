<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Api;

/**
 * Oceanpayment 嵌入式支付预下单 REST API 接口
 *
 * 嵌入式支付模式：
 * 1. iframe 收集卡信息 → 前端获得 card_data
 * 2. 前端调用此 API，传入 card_data + cartId
 * 3. 后端组装签名参数 + cURL 到 Oceanpayment /gateway/direct/pay
 * 4. 返回支付结果（pay_url / payment_id / payment_status）
 *
 * 端点路由：
 * - 登录用户：POST /rest/V1/oceanpayment/pre-order
 * - 访客用户：POST /rest/V1/guest-oceanpayment/:cartId/pre-order
 */
interface PreOrderInterface
{
    /**
     * 预下单（登录用户）
     *
     * @param int $cartId 购物车 ID
     * @param string $cardData 加密的卡数据（iframe 阶段1回调返回）
     * @return array 包含 payment_id、pay_url、payment_status 等
     */
    public function preOrder(int $cartId, string $cardData = ''): array;

    /**
     * 预下单（访客用户）
     *
     * @param string $cartId 购物车 ID（masked）
     * @param string $email 访客邮箱
     * @param string $cardData 加密的卡数据
     * @return array 包含 payment_id、pay_url、payment_status 等
     */
    public function guestPreOrder(string $cartId, string $email, string $cardData = ''): array;
}
