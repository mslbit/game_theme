<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model;

use GuzzleHttp\Client as GuzzleClient;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MslPay\LianLian\Api\PaymentQueryInterface;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;
use MslPay\LianLian\Gateway\Service\PaymentSuccessService;
use Psr\Log\LoggerInterface;

/**
 * 连连支付查询实现
 *
 * GET /v3/merchants/<merchant_id>/payments/<merchant_transaction_id>
 *
 * 查询流程：
 * 1. 前置校验：订单是否存在
 * 2. 调连连 API 查询支付状态
 * 3. 如果 payment_status=PS，调 PaymentSuccessService 执行 capture
 * 4. 返回查询结果供调用方判断
 *
 * 可通过 DI 注入：
 *   public function __construct(PaymentQueryInterface $paymentQuery) { ... }
 *   $result = $this->paymentQuery->query('GM000000284');
 */
class PaymentQuery implements PaymentQueryInterface
{
    private GuzzleClient $httpClient;
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private PaymentSuccessService $paymentSuccessService;
    private LoggerInterface $logger;

    public function __construct(
        GuzzleClient $httpClient,
        Config $config,
        LianLianSignatureHelper $signatureHelper,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        PaymentSuccessService $paymentSuccessService,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->paymentSuccessService = $paymentSuccessService;
        $this->logger = $logger;
    }

    public function query(string $merchantTransactionId): array
    {
        /* 前置校验：订单必须存在 */
        $order = $this->findOrderByIncrementId($merchantTransactionId);
        if (!$order) {
            return [
                'return_code' => 'FAIL',
                'return_message' => 'Order not found: ' . $merchantTransactionId,
            ];
        }

        /* 调连连 API 查询 */
        $result = $this->doQuery($merchantTransactionId);

        /* PS 状态：调 PaymentSuccessService 执行 capture */
        $paymentStatus = $result['order']['payment_data']['payment_status'] ?? '';
        if ($paymentStatus === 'PS') {
            try {
                $this->paymentSuccessService->execute($order, $result['order'] ?? []);
            } catch (\Exception $e) {
                $this->logger->error('[LianLian] PaymentQuery: PaymentSuccessService failed', [
                    'order' => $merchantTransactionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /* 把订单对象放进返回结果，调用方可直接使用，无需再查一遍 */
        $result['_order'] = $order;

        return $result;
    }

    /**
     * 调连连 API 查询支付状态
     */
    private function doQuery(string $merchantTransactionId): array
    {
        $merchantId = $this->config->getMerchantId();

        $url = $this->config->getApiBaseUrl()
            . '/v3/merchants/' . $merchantId
            . '/payments/' . $merchantTransactionId;

        $signParams = [
            'merchant_id' => $merchantId,
            'merchant_transaction_id' => $merchantTransactionId,
        ];

        $headers = $this->signatureHelper->buildRequestHeaders(
            $signParams,
            $this->config->getPrivateKey(),
            $this->config->getTimezone()
        );

        $this->logger->info('[LianLian] PaymentQuery: querying', [
            'merchant_transaction_id' => $merchantTransactionId,
        ]);

        try {
            $response = $this->httpClient->get($url, [
                'headers' => $headers,
                'timeout' => 15,
            ]);

            $result = json_decode((string) $response->getBody(), true);

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid response from LianLian payment query API');
            }

            /* 验签响应：失败时中断处理，防止使用被篡改的响应数据 */
            $responseSignature = $response->getHeaderLine('signature');
            if (!empty($responseSignature)) {
                if (!$this->signatureHelper->verify($result, $responseSignature, $this->config->getLianLianPublicKey())) {
                    $this->logger->error('[LianLian] PaymentQuery: response signature verification failed', [
                        'merchant_transaction_id' => $merchantTransactionId,
                    ]);
                    throw new \RuntimeException('LianLian payment query response signature verification failed');
                }
            }

            $this->logger->info('[LianLian] PaymentQuery: result', [
                'merchant_transaction_id' => $merchantTransactionId,
                'return_code' => $result['return_code'] ?? '',
                'payment_status' => $result['order']['payment_data']['payment_status'] ?? '',
            ]);

            return $result;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[LianLian] PaymentQuery: request failed', [
                'merchant_transaction_id' => $merchantTransactionId,
                'error' => $e->getMessage(),
            ]);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('LianLian payment query failed: %1', $e->getMessage())
            );
        }
    }

    /**
     * 按订单号查找订单
     */
    private function findOrderByIncrementId(string $incrementId)
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->create();
            $orders = $this->orderRepository->getList($searchCriteria)->getItems();
            return !empty($orders) ? reset($orders) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
