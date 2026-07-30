<?php
declare(strict_types=1);

namespace MslPay\LianLian\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * 连连支付同步跳转控制器
 *
 * 用户在连连收银台/3DS 完成后，连连将浏览器重定向到此地址。
 *
 * 处理逻辑：
 * 1. 无参数（GET 重定向无数据）→ 直接跳转成功页
 * 2. 有参数 → 验签（缺签名或验不过 → 失败页）
 * 3. 验签通过 → 查本地订单设置 checkout session → 跳转成功页
 *
 * 不再请求连连查询 API，capture/开票完全由异步通知（Notification）完成
 */
class Back implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    private RequestInterface $request;
    private ResultFactory $resultFactory;
    private CheckoutSession $checkoutSession;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        ResultFactory $resultFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Config $config,
        LianLianSignatureHelper $signatureHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->logger = $logger;
    }

    public function execute(): ResultInterface
    {
        $params = $this->request->getParams();

        $this->logger->info('[LianLian] Back controller received', ['params' => $params]);

        $merchantTransactionId = $params['merchant_transaction_id'] ?? '';
        $signature = $params['signature'] ?? '';

        /* 无参数 → 直接跳转成功页 */
        if (empty($merchantTransactionId)) {
            return $this->redirectSuccess();
        }

        /* 有参数必须验签：缺签名或验不过 → 失败页 */
        if (empty($signature)) {
            $this->logger->error('[LianLian] Back: missing signature');
            return $this->redirectFailure('Missing signature');
        }

        $paramsForVerify = $params;
        unset($paramsForVerify['signature']);
        if (!$this->signatureHelper->verify($paramsForVerify, $signature, $this->config->getLianLianPublicKey())) {
            $this->logger->error('[LianLian] Back signature verification failed');
            return $this->redirectFailure('Signature verification failed');
        }

        /* 验签通过 → 查本地订单（不请求连连 API，capture 由异步通知完成） */
        $order = $this->findOrderByIncrementId($merchantTransactionId);
        if (!$order) {
            $this->logger->error('[LianLian] Back: order not found', [
                'merchant_transaction_id' => $merchantTransactionId,
            ]);
            return $this->redirectFailure('Order not found: ' . $merchantTransactionId);
        }

        $this->checkoutSession
            ->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getEntityId())
            ->setLastRealOrderId($order->getIncrementId());

        return $this->redirectSuccess();
    }

    /**
     * 按订单号查找本地订单
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|null
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
            $this->logger->error('[LianLian] Back: order lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function redirectSuccess(): ResultInterface
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/onepage/success');
        return $resultRedirect;
    }

    private function redirectFailure(string $reason): ResultInterface
    {
        $this->logger->warning('[LianLian] Back redirect failure', ['reason' => $reason]);
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/onepage/failure');
        return $resultRedirect;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
