<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment Sale 命令
 *
 * 嵌入式支付模式下，支付已在前端 iframe 内完成，此命令：
 * 1. 从 payment.getAdditionalInformation() 读取交易信息
 *    （前端通过 additional_data 传递，AssignDataObserver 转存到 additional_information，
 *     Quote→Order 转换时 additional_information 会被复制）
 * 2. 设置 transactionId，标记交易已关闭（isTransactionClosed=true）
 * 3. Magento 自动执行 capture 并创建 invoice
 */
class SaleCommand implements CommandInterface
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    public function execute(array $commandSubject)
    {
        return;
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $commandSubject['payment'];
        $payment = $paymentDO->getPayment();

        // 从 additional_information 读取（由 AssignDataObserver 从 additional_data 转存）
        $paymentId = $payment->getAdditionalInformation('oceanpayment_payment_id');

        if (empty($paymentId)) {
            $this->logger->warning('[Oceanpayment] SaleCommand - No payment_id in additional_information, skipping',[$payment->getAdditionalData()]);
            return;
        }

        $payment->setTransactionId($paymentId);
        $payment->setLastTransId($paymentId);
        $payment->setIsTransactionClosed(true);

        // 将交易信息同步到 additional_information，供订单详情展示
        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_card_number', $payment->getAdditionalInformation('oceanpayment_card_number') ?? '');
        $payment->setAdditionalInformation('oceanpayment_auth_type', $payment->getAdditionalInformation('oceanpayment_auth_type') ?? '');

        $this->logger->info('[Oceanpayment] SaleCommand - Transaction data set', [
            'payment_id' => $paymentId,
            'order_id' => $paymentDO->getOrder()->getOrderIncrementId(),
        ]);
    }
}
