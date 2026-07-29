<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api;

/**
 * 连连退款异步通知接口
 *
 * 端点：POST /rest/V1/lianlian/refund-notify
 *
 * 连连在退款状态=RS 时发送退款结果通知。
 * 商户必须返回 {"code":"200","message":"success"} 停止重试。
 */
interface RefundNotifyInterface
{
    /**
     * 处理退款异步通知
     *
     * @return string JSON 响应 {"code":"200","message":"success"}
     */
    public function handle(): string;
}