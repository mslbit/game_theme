<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 授权响应处理器
 *
 * 处理 Oceanpayment authorize 命令的响应：
 * 1. 提取 pay_url（支付跳转地址），存入 payment 的 additional_information
 * 2. 提取 payment_id（Oceanpayment 交易标识），存入 additional_information
 * 3. 设置 isTransactionClosed = false，交易未关闭
 *
 * 订单状态由 InitializeCommand 通过 stateObject 设为 pending_payment，
 * 不再通过 isTransactionPending 控制。
 */
class AuthorizeHandler implements HandlerInterface
{
    /**
     * additional_information 中存储 pay_url 的键名
     */
    private const KEY_PAY_URL = 'oceanpayment_pay_url';

    /**
     * additional_information 中存储 payment_id 的键名
     */
    private const KEY_PAYMENT_ID = 'oceanpayment_payment_id';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * 处理授权响应
     *
     * @param array $handlingSubject 处理参数
     * @param array $response API 响应数据
     * @return void
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->readPayment($handlingSubject);
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        /* 提取 pay_url：Oceanpayment 托管收银页面的跳转地址 */
        $payUrl = $response['pay_url'] ?? '';
        if (!empty($payUrl)) {
            $payment->setAdditionalInformation(self::KEY_PAY_URL, $payUrl);
        }

        /* 提取 payment_id：Oceanpayment 分配的交易标识 */
        $paymentId = $response['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setAdditionalInformation(self::KEY_PAYMENT_ID, $paymentId);
            $payment->setTransactionId($paymentId);
            $payment->setLastTransId($paymentId);
        }

        /*
         * 标记交易未关闭：
         * - isTransactionClosed = false：交易未关闭，回调中会创建 capture 交易
         *
         * 注意：不再设置 isTransactionPending=true，
         * 订单状态由 InitializeCommand 通过 stateObject 设为 pending_payment
         */
        $payment->setIsTransactionClosed(false);

        $this->logger->info('[Oceanpayment] AuthorizeHandler processed', [
            'pay_url'    => $payUrl ? '(set)' : '(empty)',
            'payment_id' => $paymentId ?: '(empty)',
        ]);
    }

    /**
     * @param array $handlingSubject
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