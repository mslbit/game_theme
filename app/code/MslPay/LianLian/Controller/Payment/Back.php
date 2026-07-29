<?php
declare(strict_types=1);

namespace MslPay\LianLian\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use MslPay\LianLian\Api\PaymentQueryInterface;
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
 * 2. 有参数 → 验签 → 调 PaymentQuery 查询支付状态
 *    - PS → 跳转成功页
 *    - 非 PS → 跳转失败页
 *
 * PaymentQuery 内部：如果查询结果为 PS，会调 PaymentSuccessService 执行 capture
 */
class Back implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    private RequestInterface $request;
    private ResultFactory $resultFactory;
    private CheckoutSession $checkoutSession;
    private PaymentQueryInterface $paymentQuery;
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        ResultFactory $resultFactory,
        CheckoutSession $checkoutSession,
        PaymentQueryInterface $paymentQuery,
        Config $config,
        LianLianSignatureHelper $signatureHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->checkoutSession = $checkoutSession;
        $this->paymentQuery = $paymentQuery;
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

        /* 验签 */
        if (!empty($signature)) {
            $paramsForVerify = $params;
            unset($paramsForVerify['signature']);
            if (!$this->signatureHelper->verify($paramsForVerify, $signature, $this->config->getLianLianPublicKey())) {
                $this->logger->error('[LianLian] Back signature verification failed');
                return $this->redirectFailure('Signature verification failed');
            }
        }

        /* 调 PaymentQuery 查询支付状态（内部会验订单存在、PS 时调 PaymentSuccessService） */
        try {
            $result = $this->paymentQuery->query($merchantTransactionId);
        } catch (\Exception $e) {
            $this->logger->error('[LianLian] Back query failed', ['error' => $e->getMessage()]);
            return $this->redirectFailure('Payment query failed');
        }

        $returnCode = $result['return_code'] ?? '';
        if ($returnCode !== 'SUCCESS') {
            return $this->redirectFailure('Query failed: ' . ($result['return_message'] ?? ''));
        }

        $paymentStatus = $result['order']['payment_data']['payment_status'] ?? '';

        /* 从 PaymentQuery 返回结果中取订单对象，设置完整 checkout session */
        $order = $result['_order'] ?? null;
        if ($order) {
            $this->checkoutSession
                ->setLastQuoteId($order->getQuoteId())
                ->setLastOrderId($order->getEntityId())
                ->setLastRealOrderId($order->getIncrementId());
        }

        if ($paymentStatus === 'PS') {
            if ($order) {
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            }
            return $this->redirectSuccess();
        }

        /* 非 PS：恢复 quote 允许重试 */
        $this->checkoutSession->restoreQuote();
        $this->checkoutSession->setErrorMessage(
            (string) __('Payment failed or is pending. Status: %1', $paymentStatus)
        );
        return $this->redirectFailure('Payment status: ' . $paymentStatus);
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
