<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Response\Handler;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

/**
 * 支付详情处理器
 *
 * 从连连响应中提取交易信息，设置到 Payment 对象：
 * - transactionId / lastTransId: 连连交易号
 * - additionalInformation: 保存交易详情供后续查询
 * - isTransactionClosed: false（授权未完成，等异步通知确认）
 *
 * 订单状态由 PaymentPlaceEndObserver（sales_order_payment_place_end）处理：
 * - PS → registerCaptureNotification → PROCESSING
 * - 非 PS → pending_payment
 */
class PaymentDetailsHandler implements HandlerInterface
{
    /**
     * 处理连连支付响应，设置交易信息到 Payment
     *
     * @param array $commandSubject 命令参数
     * @param array $response 连连响应 ['body' => ..., 'signature' => ...]
     */
    public function handle(array $commandSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();

        $responseBody = $response['body'] ?? [];
        $orderData = $responseBody['order'] ?? [];
        $paymentData = $orderData['payment_data'] ?? [];

        /* 提取关键字段 */
        $llTransactionId = $orderData['ll_transaction_id'] ?? '';
        $paymentUrl = $orderData['payment_url'] ?? '';
        $threeDsStatus = $orderData['3ds_status'] ?? '';
        $paymentStatus = $paymentData['payment_status'] ?? '';

        /* 设置交易 ID */
        if (!empty($llTransactionId)) {
            $payment->setTransactionId($llTransactionId);
            $payment->setLastTransId($llTransactionId);
        }

        /* 授权未完成，不关闭交易（等异步通知确认） */
        $payment->setIsTransactionClosed(false);

        /* 保存交易信息到 additional_information，供后续查询和退款使用 */
        $payment->setAdditionalInformation('lianlian_transaction_id', (string) $llTransactionId);
        $payment->setAdditionalInformation('lianlian_payment_url', (string) $paymentUrl);
        $payment->setAdditionalInformation('lianlian_3ds_status', (string) $threeDsStatus);
        $payment->setAdditionalInformation('lianlian_payment_status', (string) $paymentStatus);

    }
}