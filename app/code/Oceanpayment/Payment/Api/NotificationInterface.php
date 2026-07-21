<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Api;

/**
 * Oceanpayment 异步通知 REST API 接口
 *
 * 定义 Oceanpayment 服务端异步通知（noticeUrl）的处理方法。
 * 当 Oceanpayment 确认支付结果后，会向此 API 端点发送 XML 格式的 POST 通知。
 *
 * 端点路由：POST /rest/V1/oceanpayment/notice
 * 权限：anonymous（Oceanpayment 服务端无 Magento 认证）
 */
interface NotificationInterface
{
    /**
     * 处理 Oceanpayment 异步通知
     *
     * 接收并解析 Oceanpayment 服务端推送的 XML 通知，
     * 验证签名后更新订单状态，返回 "receive-ok" 表示通知已成功接收
     *
     * @return string 处理结果，成功时返回 "receive-ok"
     */
    public function handle(): string;
}