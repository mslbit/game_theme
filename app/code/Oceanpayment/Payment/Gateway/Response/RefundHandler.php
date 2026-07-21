<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Response;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 退款响应处理器
 *
 * 处理 Oceanpayment Order Management API 的退款响应：
 * 1. 解析 XML 响应（已由 Client 解析为数组）
 * 2. 检查 refund_results 字段：1=退款成功，0=退款失败
 * 3. 退款成功时添加退款交易记录到 payment
 * 4. 添加订单状态历史备注
 *
 * 响应 XML 结构示例：
 * <xml>
 *   <refund_results>1</refund_results>
 *   <refund_amount>10.00</refund_amount>
 *   <order_number>100000001</order_number>
 *   <payment_id>OP202607200001</payment_id>
 *   <signValue>...</signValue>
 * </xml>
 */
class RefundHandler implements HandlerInterface
{
    /**
     * 退款成功状态码
     */
    private const REFUND_RESULT_SUCCESS = 1;

    /**
     * 退款失败状态码
     */
    private const REFUND_RESULT_FAILED = 0;

    /**
     * @var LoggerInterface PSR-3 日志记录器
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * 处理退款响应
     *
     * 从 Oceanpayment 退款 API 响应中提取退款结果，
     * 根据结果添加退款交易记录和订单备注
     *
     * @param array $handlingSubject 处理参数，包含 payment 数据对象
     * @param array $response API 响应数据（已由 Client 从 XML 解析为数组）
     * @return void
     */
    public function handle(array $handlingSubject, array $response): void
    {
        /* 从处理参数中获取 PaymentDataObject */
        $paymentDO = $this->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        /* 提取退款结果：1=成功，0=失败 */
        $refundResult = (int) ($response['refund_results'] ?? self::REFUND_RESULT_FAILED);
        $refundAmount = $response['refund_amount'] ?? '0.00';
        $orderNumber = $response['order_number'] ?? '';
        $paymentId = $response['payment_id'] ?? '';

        if ($refundResult === self::REFUND_RESULT_SUCCESS) {
            /* 退款成功：添加退款交易记录 */
            $this->processRefundSuccess($payment, $paymentId, $refundAmount, $orderNumber);
        } else {
            /* 退款失败：记录错误并添加备注 */
            $this->processRefundFailure($payment, $refundAmount, $orderNumber, $response);
        }
    }

    /**
     * 处理退款成功
     *
     * 添加 TYPE_REFUND 交易记录，设置 isTransactionClosed 为 true，
     * 添加订单状态历史备注
     *
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment 支付对象
     * @param string $paymentId Oceanpayment 交易 ID
     * @param string $refundAmount 退款金额
     * @param string $orderNumber 订单号
     */
    private function processRefundSuccess(
        $payment,
        string $paymentId,
        string $refundAmount,
        string $orderNumber
    ): void {
        /* 设置退款交易 ID（附加 "-refund" 后缀避免与原支付交易冲突） */
        $transactionId = !empty($paymentId) ? $paymentId . '-refund' : '';

        if (!empty($transactionId)) {
            /* 添加 TYPE_REFUND 交易记录 */
            $payment->setTransactionId($transactionId);

            /* 创建退款交易并设置 isTransactionClosed 为 true（退款交易不可再操作） */
            $payment->addTransaction(
                PaymentTransaction::TYPE_REFUND,
                null,
                true,
                __('Refunded %1 via Oceanpayment', $refundAmount)
            );
        }

        /* 添加订单状态历史备注 */
        $payment->getOrder()->addCommentToStatusHistory(
            sprintf(
                'Oceanpayment Refund SUCCESS | Amount: %s | Payment ID: %s | Order: %s',
                $refundAmount,
                $paymentId ?: 'N/A',
                $orderNumber
            )
        );

        $this->logger->info('[Oceanpayment] RefundHandler: Refund SUCCESS', [
            'order_number'  => $orderNumber,
            'refund_amount' => $refundAmount,
            'payment_id'    => $paymentId,
        ]);
    }

    /**
     * 处理退款失败
     *
     * 记录错误日志，添加订单状态历史备注标记退款失败
     *
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment 支付对象
     * @param string $refundAmount 退款金额
     * @param string $orderNumber 订单号
     * @param array $response 完整的 API 响应
     */
    private function processRefundFailure(
        $payment,
        string $refundAmount,
        string $orderNumber,
        array $response
    ): void {
        $payment->getOrder()->addCommentToStatusHistory(
            sprintf(
                'Oceanpayment Refund FAILED | Amount: %s | Order: %s | Response: %s',
                $refundAmount,
                $orderNumber,
                json_encode($response)
            )
        );

        $this->logger->error('[Oceanpayment] RefundHandler: Refund FAILED', [
            'order_number'  => $orderNumber,
            'refund_amount' => $refundAmount,
            'response'      => $response,
        ]);

        throw new CommandException(
            __('Oceanpayment refund failed for order %1. Amount: %2', $orderNumber, $refundAmount)
        );
    }

    /**
     * 从处理参数中读取 PaymentDataObject
     *
     * @param array $handlingSubject 处理参数
     * @return PaymentDataObjectInterface
     * @throws \InvalidArgumentException
     */
    private function readPayment(array $handlingSubject): PaymentDataObjectInterface
    {
        if (!isset($handlingSubject['payment']) || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        return $handlingSubject['payment'];
    }
}