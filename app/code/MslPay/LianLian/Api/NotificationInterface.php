<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api;

/**
 * 连连支付异步通知 REST API 接口
 *
 * 连连只在 payment_status=PS（支付成功）时才发送异步通知。
 * 商户必须验签后处理订单，并返回 {"code":"200","message":"success"}。
 *
 * 端点路由：POST /rest/V1/lianlian/notify
 * 权限：anonymous（连连服务端无 Magento 认证）
 */
interface NotificationInterface
{
    /**
     * 处理连连支付异步通知
     *
     * 接收并解析连连服务端推送的 JSON 通知，
     * 验证 RSA 签名后更新订单状态，返回 {"code":"200","message":"success"}
     *
     * @return string 处理结果，JSON 格式
     */
    public function handle(): string;
}