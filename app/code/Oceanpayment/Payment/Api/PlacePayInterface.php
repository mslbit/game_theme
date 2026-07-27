<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Api;

/**
 * Oceanpayment 获取嵌入式支付 pay_url 接口
 *
 * 嵌入式支付场景：前端 SDK.checkout() 提交后，3D 验证需要跳转 pay_url，
 * 此 API 返回缓存的 pay_url 供前端跳转。
 */
interface PlacePayInterface
{
    /**
     * 获取嵌入式支付 pay_url
     *
     * @param string $quoteId 购物车ID（登录用户为数字ID，guest为masked字符串）
     * @return string|null pay_url 或 null
     */
    public function getPay(string $quoteId): ?string;
}
