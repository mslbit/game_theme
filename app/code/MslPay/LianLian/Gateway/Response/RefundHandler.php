<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Response;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

/**
 * 连连支付退款响应处理器
 *
 * 参考 Braintree RefundHandler 模式：
 * - setTransactionId + setIsTransactionClosed + setShouldCloseParentTransaction
 * - creditmemo->setTransactionId()
 * - 不手动调用 addTransaction()，由 Magento 核心退款流程自动创建
 *
 * 退款成功判断：refund_status = RS 为退款成功
 * refund_status = RP 为退款处理中，需通过异步通知确认
 * return_code = SUCCESS 仅代表请求成功，不代表退款成功
 *
 * 注意：GatewayCommand 传入的 response 格式为 ['body' => ..., 'signature' => ...]
 * 本 Handler 从 response['body'] 提取退款数据
 */
class RefundHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 处理退款响应
     *
     * @param array $handlingSubject 命令参数，包含 'payment'
     * @param array $response 连连响应 ['body' => ..., 'signature' => ...]
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        /* GatewayCommand 传入的 response 格式为 ['body' => ..., 'signature' => ...] */
        $responseBody = $response['body'] ?? $response;

        /* 退款响应结构：body.order.refund_data.refund_status */
        $orderData = $responseBody['order'] ?? [];
        $refundData = $orderData['refund_data'] ?? [];

        $refundStatus = $refundData['refund_status'] ?? '';
        $refundId = $orderData['ll_transaction_id'] ?? '';
        $merchantTransactionId = $orderData['merchant_transaction_id'] ?? '';

        if ($refundStatus === 'RS') {
            /* RS: 退款成功（终态） */
            $this->processRefundSuccess($payment, $refundId, $merchantTransactionId);
        } elseif (in_array($refundStatus, ['PC', 'RP'], true)) {
            /* PC: 受理成功（中间状态，等异步通知）, RP: 退款处理中 */
            $this->processRefundPending($payment, $refundId, $merchantTransactionId);
        } else {
            /* RF: 退款失败（终态）, 或未知状态 */
            $this->processRefundFailure($payment, $responseBody);
        }
    }

    /**
     * 退款成功（refund_status = RS）
     */
    private function processRefundSuccess(Payment $payment, string $refundId, string $merchantTransactionId): void
    {
        $transactionId = !empty($refundId) ? $refundId : $merchantTransactionId;
        if (!empty($transactionId)) {
            $payment->setTransactionId($transactionId);
        }

        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(
            $this->shouldCloseParentTransaction($payment)
        );

        $creditmemo = $payment->getCreditmemo();
        if ($creditmemo && !empty($transactionId)) {
            $creditmemo->setTransactionId($transactionId);
        }

        $payment->getOrder()->addCommentToStatusHistory(
            sprintf(
                'LianLian Refund SUCCESS | Refund ID: %s | Merchant TX ID: %s',
                $refundId ?: 'N/A',
                $merchantTransactionId
            )
        );

        $this->logger->info('[LianLian] RefundHandler: Refund SUCCESS', [
            'refund_id' => $refundId,
            'merchant_transaction_id' => $merchantTransactionId,
        ]);
    }

    /**
     * 退款处理中（refund_status = RP/PC）
     *
     * 退款请求已提交但银行尚未确认，等异步通知。
     * 设置 isTransactionPending=true 让 creditmemo 保持 OPEN 状态，
     * 异步通知确认退款成功后再更新为 REFUNDED。
     */
    private function processRefundPending(Payment $payment, string $refundId, string $merchantTransactionId): void
    {
        $transactionId = !empty($refundId) ? $refundId : $merchantTransactionId;
        if (!empty($transactionId)) {
            $payment->setTransactionId($transactionId);
        }

        $payment->setIsTransactionClosed(false);
        $payment->setIsTransactionPending(true);

        $creditmemo = $payment->getCreditmemo();
        if ($creditmemo) {
            $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN);
        }

        $payment->getOrder()->addCommentToStatusHistory(
            sprintf(
                'LianLian Refund PENDING | Refund ID: %s | Merchant TX ID: %s | Waiting for async notification',
                $refundId ?: 'N/A',
                $merchantTransactionId
            )
        );

        $this->logger->info('[LianLian] RefundHandler: Refund PENDING', [
            'refund_id' => $refundId,
            'merchant_transaction_id' => $merchantTransactionId,
        ]);
    }

    /**
     * 退款失败
     */
    private function processRefundFailure(Payment $payment, array $response): void
    {
        $payment->getOrder()->addCommentToStatusHistory(
            sprintf(
                'LianLian Refund FAILED | Response: %s',
                json_encode($response)
            )
        );

        $this->logger->error('[LianLian] RefundHandler: Refund FAILED', [
            'response' => $response,
        ]);

        throw new CommandException(
            __('LianLian refund failed: %1', $response['return_message'] ?? 'Unknown error')
        );
    }

    /**
     * 是否应关闭父交易（与 Braintree 一致）
     */
    private function shouldCloseParentTransaction(Payment $payment): bool
    {
        $creditmemo = $payment->getCreditmemo();
        if (!$creditmemo) {
            return true;
        }

        $invoice = $creditmemo->getInvoice();
        if (!$invoice) {
            return true;
        }

        return !(bool) $invoice->canRefund();
    }

    private function readPayment(array $handlingSubject): PaymentDataObjectInterface
    {
        if (!isset($handlingSubject['payment']) || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        return $handlingSubject['payment'];
    }
}
