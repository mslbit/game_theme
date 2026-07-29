<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MslPay\LianLian\Api\NotificationInterface;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;
use MslPay\LianLian\Gateway\Service\PaymentSuccessService;
use Psr\Log\LoggerInterface;

/**
 * 连连支付异步通知 REST API 处理器
 *
 * 端点路由：POST /rest/V1/lianlian/notify
 * 权限：anonymous
 *
 * 连连只在 payment_status=PS. 时才发送异步通知。
 * 商户必须验签后处理订单，并返回 {"code":"200","message":"success"}。
 *
 * 使用 response->setBody() + sendResponse() + exit 直接输出，
 * 避免 Magento Webapi 框架二次渲染（与 Oceanpayment 一致）
 */
class Notification implements NotificationInterface
{
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private PaymentSuccessService $paymentSuccessService;
    private HttpRequest $request;
    private HttpResponse $response;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        LianLianSignatureHelper $signatureHelper,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        PaymentSuccessService $paymentSuccessService,
        HttpRequest $request,
        HttpResponse $response,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->paymentSuccessService = $paymentSuccessService;
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
    }

    public function handle(): void
    {
        try {
            $rawBody = $this->request->getContent();
            $params = json_decode($rawBody, true);

            $this->logger->info('[LianLian] Notification received', ['body' => $rawBody]);

            if (!is_array($params)) {
                $this->logger->error('[LianLian] Notification invalid JSON body');
                throw new \Exception('Invalid request');
            }

            $merchantTransactionId = $params['merchant_transaction_id'] ?? '';
            $paymentData = $params['payment_data'] ?? [];
            $paymentStatus = $paymentData['payment_status'] ?? '';

            $this->logger->info('[LianLian] Notification parsed', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payment_status' => $paymentStatus,
            ]);

            /* 连连异步通知签名在请求 Header 中，不是 body 中 */
            $signature = $this->request->getHeader('signature') ?: '';

            if (empty($merchantTransactionId) || empty($signature)) {
                $this->logger->error('[LianLian] Notification missing required params', [
                    'has_merchant_transaction_id' => !empty($merchantTransactionId),
                    'has_signature' => !empty($signature),
                ]);
                throw new \Exception('Missing required parameters');
            }

            /* 用连连公钥验签 */
            if (!$this->signatureHelper->verify($params, $signature, $this->config->getLianLianPublicKey())) {
                $this->logger->error('[LianLian] Notification signature verification failed', [
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
                throw new \Exception('Signature verification failed');
            }

            $order = $this->findOrderByIncrementId($merchantTransactionId);

            if (!$order) {
                $this->logger->error('[LianLian] Notification order not found', [
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
                throw new \Exception('Order not found');
            }

            if ($paymentStatus === 'PS') {
                $this->paymentSuccessService->execute($order, $params);
            } else {
                $this->logger->warning('[LianLian] Notification unexpected payment_status', [
                    'payment_status' => $paymentStatus,
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
            }

            $body = json_encode(['code' => '200', 'message' => 'success']);

        } catch (\Exception $e) {
            $this->logger->error('[LianLian] Notification exception: {message}', ['message' => $e->getMessage()]);
            $body = json_encode(['code' => '500', 'message' => $e->getMessage()]);
        }

        /* 直接输出 JSON 并中断 Magento Webapi 渲染 */
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->response->setBody($body);
        $this->response->sendResponse();
        exit;
    }

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
