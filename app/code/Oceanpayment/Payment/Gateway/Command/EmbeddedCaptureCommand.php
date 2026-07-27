<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 嵌入式支付 capture 命令
 *
 * 用于 CC/ApplePay/GooglePay 嵌入式支付场景。
 * 前端 SDK.checkout() 已将支付数据提交给网关，capture 命令的作用是：
 * 1. 查询网关订单状态（/service/check/normal）
 * 2. 校验金额是否一致
 * 3. chargeBack_status=1 且金额一致 → 正常 capture
 * 4. chargeBack_status≠1 或金额不一致 → 标记为审查，等异步通知
 *
 * 查询响应结构（关键字段）：
 * - chargeBack_status: 交易结果（1=成功）
 * - payment_id: 网关支付ID
 * - order_amount: 网关订单金额
 * - payment_risk: 风控信息（预留）
 */
class EmbeddedCaptureCommand implements CommandInterface
{
    /**
     * chargeBack_status 成功值
     */
    private const PAYMENT_RESULTS_SUCCESS = 1;

    /**
     * @var BuilderInterface 请求构建器
     */
    private BuilderInterface $requestBuilder;

    /**
     * @var TransferFactoryInterface 传输工厂
     */
    private TransferFactoryInterface $transferFactory;

    /**
     * @var ClientInterface HTTP 客户端
     */
    private ClientInterface $client;

    /**
     * @var LoggerInterface 日志
     */
    private LoggerInterface $logger;

    /**
     * @param BuilderInterface $requestBuilder
     * @param TransferFactoryInterface $transferFactory
     * @param ClientInterface $client
     * @param LoggerInterface $logger
     */
    public function __construct(
        BuilderInterface $requestBuilder,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * 执行 capture 命令
     *
     * 查询网关订单状态，根据结果标记 capture 成功或审查
     *
     * @param array $commandSubject 命令参数
     */
    public function execute(array $commandSubject): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();
        $amount = SubjectReader::readAmount($commandSubject);
        $orderNumber = $order->getOrderIncrementId();
        $orderAmount = number_format((float) $amount, 2, '.', '');

        /* 通过 requestBuilder 构建查询参数（含签名） */
        $requestData = $this->requestBuilder->build($commandSubject);
file_put_contents(BP.'/var/pay.log','embedd',FILE_APPEND);
        $this->logger->info('[Oceanpayment] EmbeddedCaptureCommand querying order', [
            'order_number' => $orderNumber,
        ]);

        /* 通过 TransferFactory + Client 发送查询请求 */
        try {
            $transfer = $this->transferFactory->create($requestData);
            $response = $this->client->placeRequest($transfer);
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] EmbeddedCaptureCommand query failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            $this->markAsReview($payment, $orderNumber, 'Order query failed: ' . $e->getMessage());
            return;
        }

        /* 响应为空，标记审查 */
        if (empty($response)) {
            $this->markAsReview($payment, $orderNumber, 'Empty query response');
            return;
        }

        /* 从查询结果中提取关键字段 */
        $chargeBackStatus = (string) ($response['chargeBack_status'] ?? '');
        $gatewayAmount = (string) ($response['order_amount'] ?? '');
        $paymentId = (string) ($response['payment_id'] ?? '');
        $paymentRisk = (string) ($response['payment_risk'] ?? '');
        $refundStatus = (string) ($response['refund_status'] ?? '');
        $authStatus = (string) ($response['auth_status'] ?? '');
        $paymentDetails = (string) ($response['payment_details'] ?? '');

        /*
         * 只有明确成功 + 金额一致才标记 capture 成功
         * 其他所有情况（不明确、失败、金额不一致）都标记审查
         */
        $isSuccess = ($chargeBackStatus === '1');
        $amountMatch = $this->verifyAmount($orderAmount, $gatewayAmount);

        if ($isSuccess && $amountMatch) {
            $this->captureSuccess($payment, $paymentId, $paymentRisk, $refundStatus, $authStatus, $chargeBackStatus, $response);
        } else {
            $reason = !$isSuccess
                ? sprintf('chargeBack_status=%s', $chargeBackStatus)
                : sprintf('Amount mismatch: order=%s gateway=%s', $orderAmount, $gatewayAmount);
            $this->markAsReview($payment, $orderNumber, $reason, $paymentRisk, $refundStatus, $authStatus, $chargeBackStatus, $paymentDetails);
        }
    }

    /**
     * 校验订单金额与网关金额是否一致
     *
     * @param string $orderAmount 订单金额
     * @param string $gatewayAmount 网关返回金额
     * @return bool
     */
    private function verifyAmount(string $orderAmount, string $gatewayAmount): bool
    {
        if (empty($gatewayAmount)) {
            return false;
        }

        return abs((float) $orderAmount - (float) $gatewayAmount) < 0.01;
    }

    /**
     * 标记 capture 成功
     *
     * @param mixed $payment
     * @param string $paymentId
     * @param string $paymentRisk
     * @param string $refundStatus
     * @param string $authStatus
     * @param string $chargeBackStatus
     * @param array $response
     */
    private function captureSuccess($payment, string $paymentId, string $paymentRisk, string $refundStatus, string $authStatus, string $chargeBackStatus, array $response): void
    {
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->setLastTransId($paymentId);
        }

        $payment->setIsTransactionClosed(false);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation('oceanpayment_capture_source', 'gateway_query');
        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_chargeback_status', $chargeBackStatus);

        if (!empty($paymentRisk)) {
            $payment->setAdditionalInformation('oceanpayment_payment_risk', $paymentRisk);
        }
        if (!empty($refundStatus)) {
            $payment->setAdditionalInformation('oceanpayment_refund_status', $refundStatus);
        }
        if (!empty($authStatus)) {
            $payment->setAdditionalInformation('oceanpayment_auth_status', $authStatus);
        }

        $payment->setAdditionalInformation('oceanpayment_query_result', json_encode($response));

        $this->logger->info('[Oceanpayment] EmbeddedCaptureCommand capture success', [
            'payment_id'        => $paymentId,
            'payment_risk'      => $paymentRisk,
            'chargeback_status' => $chargeBackStatus,
        ]);
    }

    /**
     * 标记为审查状态
     *
     * @param mixed $payment
     * @param string $orderNumber
     * @param string $reason
     * @param string $paymentRisk
     * @param string $refundStatus
     * @param string $authStatus
     * @param string $chargeBackStatus
     * @param string $paymentDetails
     */
    private function markAsReview($payment, string $orderNumber, string $reason, string $paymentRisk = '', string $refundStatus = '', string $authStatus = '', string $chargeBackStatus = '', string $paymentDetails = ''): void
    {
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('oceanpayment_capture_source', 'gateway_query_review');
        $payment->setAdditionalInformation('oceanpayment_review_reason', $reason);
        $payment->setAdditionalInformation('oceanpayment_chargeback_status', $chargeBackStatus);

        if (!empty($paymentRisk)) {
            $payment->setAdditionalInformation('oceanpayment_payment_risk', $paymentRisk);
        }
        if (!empty($refundStatus)) {
            $payment->setAdditionalInformation('oceanpayment_refund_status', $refundStatus);
        }
        if (!empty($authStatus)) {
            $payment->setAdditionalInformation('oceanpayment_auth_status', $authStatus);
        }
        if (!empty($paymentDetails)) {
            $payment->setAdditionalInformation('oceanpayment_payment_details', $paymentDetails);
        }

        $this->logger->warning('[Oceanpayment] EmbeddedCaptureCommand marked as review', [
            'order_number'      => $orderNumber,
            'reason'            => $reason,
            'payment_risk'      => $paymentRisk,
            'chargeback_status' => $chargeBackStatus,
        ]);
    }
}