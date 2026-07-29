<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Store\Model\StoreManagerInterface;
use MslPay\LianLian\Gateway\Config\Config;

/**
 * 连连支付退款请求构建器
 *
 * 构建退款 API 的请求参数：
 * POST /v3/merchants/<merchant_id>/payments/<original_transaction_id>/refunds
 *
 * 退款规则：
 * - 支持单笔交易分多次退款，每次需不同的 merchant_transaction_id
 * - 累计退款金额不能超过原始交易总金额
 * - 当天的交易只允许全额退款
 * - 退款失败重试时，merchant_transaction_id 不能变更
 *
 * 签名构建因子为入参列表中所有参数
 */
class RefundBuilder implements BuilderInterface
{
    private Config $config;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function build(array $buildSubject): array
    {
        if (!isset($buildSubject['amount']) || (float) $buildSubject['amount'] <= 0) {
            throw new \InvalidArgumentException('Refund amount should be provided and greater than 0');
        }

        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();

        /*
         * original_transaction_id: 原商户支付交易ID
         *
         * 连连退款 API 的 original_transaction_id 指的是创单时传入的
         * merchant_transaction_id（即订单号），不是连连返回的 ll_transaction_id。
         */
        $originalTransactionId = $order->getOrderIncrementId();
        if (empty($originalTransactionId)) {
            throw new \InvalidArgumentException(
                'Order increment ID is required for refund as original_transaction_id'
            );
        }

        $refundAmount = number_format((float) $buildSubject['amount'], 2, '.', '');

        /*
         * merchant_transaction_id: 退款请求号，保证唯一
         *
         * 格式：T-{invoice_increment_id}-{invoice_entity_id}
         * 通过 invoice 关联定位 creditmemo，退款通知时用 '-' 分割取最后一段作为 invoice_id
         * 查 sales_creditmemo 表 invoice_id 字段找到对应 creditmemo
         * 退款失败重试时 merchant_transaction_id 不能变更
         */
        $creditmemo = $payment->getCreditmemo();
        if (!$creditmemo) {
            throw new \InvalidArgumentException('Creditmemo is required for refund');
        }

        $invoice = $creditmemo->getInvoice();
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice is required for refund merchant_transaction_id');
        }

        $merchantTransactionId = sprintf('T-%s-%d', $invoice->getIncrementId(), $invoice->getId());

        /* 使用 StoreManagerInterface 获取 baseUrl（OrderAdapterInterface 没有 getStore()） */
        $storeId = $order->getStoreId();
        $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl();

        return [
            'merchant_transaction_id' => $merchantTransactionId,
            'merchant_id' => $this->config->getMerchantId(),
            'sub_merchant_id' => $this->config->getSubMerchantId(),
            'merchant_refund_time' => date('YmdHis'),
            'original_transaction_id' => $originalTransactionId,
            'notification_url' => $baseUrl . 'rest/V1/lianlian/refund-notify',
            'refund_data' => [
                'refund_currency_code' => $order->getCurrencyCode(),
                'refund_amount' => $refundAmount,
            ],
        ];
    }
}
