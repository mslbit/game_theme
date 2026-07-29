<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api;

/**
 * 连连支付获取 payment_url 接口
 *
 * placeOrder 完成后，前端调用此 API 获取缓存的 payment_url（3DS 跳转地址）。
 * IframeCommand 在 authorize 命令中缓存 payment_url，此 API 从缓存读取返回。
 */
interface PayUrlInterface
{
    /**
     * 登录用户获取 payment_url
     *
     * @param int $quoteId
     * @param string $methodCode
     * @return string payment_url，空字符串表示无需跳转
     */
    public function getPayUrl(int $quoteId, string $methodCode): string;

    /**
     * 访客用户获取 payment_url
     *
     * @param string $quoteId
     * @param string $methodCode
     * @return string payment_url，空字符串表示无需跳转
     */
    public function getGuestPayUrl(string $quoteId, string $methodCode): string;
}