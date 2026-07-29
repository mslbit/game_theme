<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Creditmemo;

use Magento\Sales\Model\ResourceModel\Order\Creditmemo as CreditmemoResource;
use MslPay\LianLian\Api\RefundNotifyInterface;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * 连连退款异步通知 REST API 处理器
 *
 * 端点路由：POST /rest/V1/lianlian/refund-notify
 * 权限：anonymous
 *
 * 连连仅在 refund_status=RS（退款成功）时发送退款结果通知。
 * 商户必须验签后更新 creditmemo 状态，并返回 {"code":"200","message":"success"}。
 *
 * merchant_transaction_id 格式：T-{invoice_increment_id}-{invoice_entity_id}
 * 退款通知时用 '-' 分割取最后一段作为 invoice_id，
 * 查 sales_creditmemo 表 invoice_id 字段定位 creditmemo。
 */
class RefundNotify implements RefundNotifyInterface
{
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;
    private ResourceConnection $resourceConnection;
    private CreditmemoResource $creditmemoResource;
    private HttpRequest $request;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        LianLianSignatureHelper $signatureHelper,
        ResourceConnection $resourceConnection,
        CreditmemoResource $creditmemoResource,
        HttpRequest $request,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->resourceConnection = $resourceConnection;
        $this->creditmemoResource = $creditmemoResource;
        $this->request = $request;
        $this->logger = $logger;
    }

    public function handle(): string
    {
        try {
            $rawBody = $this->request->getContent();
            $params = json_decode($rawBody, true);

            $this->logger->info('[LianLian] RefundNotify received', ['body' => $rawBody]);

            if (!is_array($params)) {
                $this->logger->error('[LianLian] RefundNotify invalid JSON body');
                return $this->jsonResponse('400', 'Invalid request');
            }

            $merchantTransactionId = $params['merchant_transaction_id'] ?? '';
            $refundData = $params['refund_data'] ?? [];
            $refundStatus = $refundData['refund_status'] ?? '';

            /* 连连异步通知签名在请求 Header 中 */
            $signature = $this->request->getHeader('signature') ?: '';

            if (empty($merchantTransactionId) || empty($signature)) {
                $this->logger->error('[LianLian] RefundNotify missing required params');
                return $this->jsonResponse('400', 'Missing required parameters');
            }

            /* 用连连公钥验签 */
            if (!$this->signatureHelper->verify($params, $signature, $this->config->getLianLianPublicKey())) {
                $this->logger->error('[LianLian] RefundNotify signature verification failed', [
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
                return $this->jsonResponse('400', 'Signature verification failed');
            }

            if ($refundStatus === 'RS') {
                $this->processRefundSuccess($params);
            } else {
                $this->logger->warning('[LianLian] RefundNotify unexpected refund_status', [
                    'refund_status' => $refundStatus,
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
            }

            return $this->jsonResponse('200', 'success');

        } catch (\Exception $e) {
            $this->logger->error('[LianLian] RefundNotify exception: {message}', ['message' => $e->getMessage()]);
            return $this->jsonResponse('500', 'Internal error');
        }
    }

    /**
     * 退款成功：通过 merchant_transaction_id 定位 creditmemo 并更新为 REFUNDED
     *
     * merchant_transaction_id 格式：T-{invoice_increment_id}-{invoice_entity_id}
     * 用 '-' 分割取最后一段作为 invoice_id，
     * 查 sales_creditmemo 表 invoice_id 字段找到对应 creditmemo
     */
    private function processRefundSuccess(array $params): void
    {
        $llTransactionId = $params['ll_transaction_id'] ?? '';
        $merchantTransactionId = $params['merchant_transaction_id'] ?? '';
        $refundData = $params['refund_data'] ?? [];
        $refundAmount = $refundData['refund_amount'] ?? 0;

        /* 从 merchant_transaction_id 提取 invoice_id：用 '-' 分割取最后一段 */
        $invoiceId = 0;
        if (!empty($merchantTransactionId) && str_contains($merchantTransactionId, '-')) {
            $parts = explode('-', $merchantTransactionId);
            $invoiceId = (int) end($parts);
        }

        if ($invoiceId <= 0) {
            $this->logger->error('[LianLian] RefundNotify cannot extract invoice_id', [
                'merchant_transaction_id' => $merchantTransactionId,
            ]);
            return;
        }

        /* 查 sales_creditmemo 表 invoice_id 字段获取 entity_id */
        $connection = $this->resourceConnection->getConnection();
        $entityId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('sales_creditmemo'), 'entity_id')
                ->where('invoice_id = ?', $invoiceId)
                ->limit(1)
        );

        if ($entityId <= 0) {
            $this->logger->error('[LianLian] RefundNotify creditmemo not found by invoice_id', [
                'invoice_id' => $invoiceId,
            ]);
            return;
        }

        /* load creditmemo 并更新状态 */
        $creditmemoFactory = \Magento\Framework\App\ObjectManager::getInstance()->create(Creditmemo::class);
        $creditmemo = $this->creditmemoResource->load($creditmemoFactory, $entityId);
        /**
         * @var Creditmemo $creditmemo
         */
        $creditmemo->setState(Creditmemo::STATE_REFUNDED);
        $this->creditmemoResource->save($creditmemo);

        $this->logger->info('[LianLian] RefundNotify: creditmemo updated to REFUNDED', [
            'invoice_id' => $invoiceId,
            'entity_id' => $entityId,
            'll_transaction_id' => $llTransactionId,
        ]);
    }

    private function jsonResponse(string $code, string $message): string
    {
        return json_encode(['code' => $code, 'message' => $message]);
    }
}
