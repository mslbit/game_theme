<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Api;

/**
 * Oceanpayment 嵌入式支付 checkout 数据接口
 *
 * 前端调用此 API 获取 SDK.checkout() 所需的表单数据，
 * 敏感字段（account, terminal, signValue, key 等）由后端组装，不暴露给前端。
 *
 * 前端需传递当前 quote 的 billingAddress 和 shippingAddress，
 * 因为用户可能在结账页修改过地址，后端需要最新数据。
 */
interface CheckoutDataInterface
{
    /**
     * 获取嵌入式支付 checkout 表单数据
     *
     * @param string $quoteId 购物车ID（登录用户为数字ID，guest为masked字符串）
     * @param string $methodCode 支付方式代码（如 oceanpayment_creditcard）
     * @param mixed $addresses 地址数据（JSON字符串，包含 billingAddress/shippingAddress/isVirtual）
     * @return string $data     SDK.checkout() 所需的表单数据
     */
    public function getCheckoutData(string $quoteId, string $methodCode, $addresses = null): string;
}