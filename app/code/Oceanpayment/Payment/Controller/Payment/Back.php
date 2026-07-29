<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\Data\OrderInterface;
use Oceanpayment\Payment\Gateway\Callback\CallbackProcessor;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 同步回调控制器（backUrl）
 *
 * 用户在 Oceanpayment 支付页面完成支付后，浏览器被重定向到此控制器。
 * 核心业务逻辑委托给 CallbackProcessor 处理，本控制器只负责：
 * 1. 收集回调参数
 * 2. 调用 CallbackProcessor 验签 + 处理订单
 * 3. 根据支付结果重定向到成功/失败页面
 *
 * 实现 HttpPostActionInterface 标记此控制器处理 POST 请求
 * 实现 CsrfAwareActionInterface 跳过 CSRF 校验（外部回调无 form_key）
 *
 * 路由：oceanpayment/payment/back
 */
class Back extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var CallbackProcessor 回调统一处理器
     */
    private CallbackProcessor $callbackProcessor;

    /**
     * @var CheckoutSession 结账会话
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var CustomerSession
     */
    private CustomerSession $customerSession;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * 登录态缓存前缀
     */
    private const CACHE_LOGIN_PREFIX = 'oceanpayment_login_';

    /**
     * Oceanpayment 回调可能包含的参数键名
     */
    private const CALLBACK_PARAM_KEYS = [
        'account', 'terminal', 'signValue', 'backUrl',
        'order_number', 'order_currency', 'order_amount', 'order_notes',
        'card_number', 'payment_id', 'payment_authType', 'payment_status',
        'payment_details', 'payment_risk', 'payment_solutions', 'methods',
        'billing_firstName', 'billing_lastName', 'billing_email',
        'billing_phone', 'billing_country', 'billing_state',
        'billing_city', 'billing_address', 'billing_zip',
        'shipping_firstName', 'shipping_lastName', 'shipping_country',
        'shipping_state', 'shipping_city', 'shipping_address', 'shipping_zip',
    ];

    /**
     * @param Context $context
     * @param CallbackProcessor $callbackProcessor
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CallbackProcessor $callbackProcessor,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->callbackProcessor = $callbackProcessor;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * 执行同步回调处理
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $params = $this->collectCallbackParams();

            $orderNumber = $params['order_number'] ?? '';
            $order = $this->callbackProcessor->loadOrder($orderNumber);

            if (!$order || !$order->getEntityId()) {
                $this->messageManager->addErrorMessage(__('Order not found.'));
                return $this->redirectToFailure();
            }
        
            /* 订单已处理过，直接跳转成功页 */
            if ($this->callbackProcessor->isOrderAlreadyProcessed($order)) {
                $this->setCheckoutSessionOrder($order);
                return $this->redirectToSuccess();
            }

            /* 验证签名：从订单获取支付方式，用于获取正确的 secureCode */
            $methodCode = (string) ($order->getPayment() ? $order->getPayment()->getMethod() : '');
            if (!$this->callbackProcessor->verifySignature($params, $methodCode)) {
                $this->messageManager->addErrorMessage(__('Payment verification failed. Please contact support.'));
                return $this->redirectToFailure();
            }

            /* 签名验证通过后，恢复登录态（3D 验证跨站 POST 回来时 session 丢失） */
            $this->restoreLoginState();

            /* 委托 CallbackProcessor 处理订单状态更新 */
            $this->callbackProcessor->processCallback($order, $params, 'Back');

            /* 根据支付结果重定向 */
            $paymentStatus = (int) ($params['payment_status'] ?? CallbackProcessor::PAYMENT_STATUS_PENDING);
            return $this->redirectByPaymentStatus($order, $paymentStatus, $params);

        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Back controller error: {message}', [
                'message' => $e->getMessage(),
                'params' => $params
            ]);
            $this->messageManager->addErrorMessage(__('An error occurred while processing your payment.'));
            return $this->redirectToFailure();
        }
    }

    /**
     * 禁用 CSRF 验证
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * 跳过 CSRF 校验
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * 收集回调参数
     *
     * @return array
     */
    private function collectCallbackParams(): array
    {
        $params = [];

        foreach (self::CALLBACK_PARAM_KEYS as $key) {
            $value = $this->getRequest()->getParam($key);
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * 根据支付结果重定向到对应页面
     *
     * @param OrderInterface $order
     * @param int $paymentStatus
     * @return ResultInterface
     */
    private function redirectByPaymentStatus(OrderInterface $order, int $paymentStatus, array $params): ResultInterface
    {
        if ($paymentStatus === CallbackProcessor::PAYMENT_STATUS_SUCCESS) {
            $this->setCheckoutSessionOrder($order);
            return $this->redirectToSuccess();
        }

        if ($paymentStatus === CallbackProcessor::PAYMENT_STATUS_FAILED) {
            /* 设置 checkout session 数据，failure 页面依赖 lastQuoteId + lastOrderId */
            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getEntityId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->restoreQuote();

            /* 恢复登录态：3D 验证跨站 POST 回来时 session 丢失 */
            if ($order->getCustomerId() && !$this->customerSession->isLoggedIn()) {
                $this->checkoutSession->setQuoteId(null);
                $this->customerSession->loginById($order->getCustomerId());
            }

            $solutions = $params['payment_solutions'] ?? '';
            $details = $params['payment_details'] ?? '';
            $failMsg = __('Your payment was declined.');
            if ($solutions) {
                $failMsg = __('Your payment was declined. %1', $solutions);
            }
            if ($details) {
                $failMsg = __('Your payment was declined. %1 (%2)', $solutions ?: __('Failed'), $details);
            }
            /* 设置 errorMessage 供 failure 页面 Block 读取 */
            $this->checkoutSession->setErrorMessage((string) $failMsg);
            $this->messageManager->addErrorMessage($failMsg);
            return $this->redirectToFailure();
        }

        /* 待处理或高风险 */
        $this->setCheckoutSessionOrder($order);

        if ($paymentStatus === CallbackProcessor::PAYMENT_STATUS_HIGH_RISK) {
            $this->messageManager->addNoticeMessage(__('Your payment is under review. We will notify you once confirmed.'));
        } else {
            $this->messageManager->addNoticeMessage(__('Your payment is being processed. We will notify you once confirmed.'));
        }

        return $this->redirectToSuccess();
    }

    /**
     * 将订单信息写入 Checkout Session
     *
     * @param OrderInterface $order
     */
    private function setCheckoutSessionOrder(OrderInterface $order): void
    {
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getEntityId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());

        /* 恢复客户登录状态：3D 验证跨站 POST 回来时 session cookie 不携带 */
        if ($order->getCustomerId() && !$this->customerSession->isLoggedIn()) {
            /* 先清空 quoteId，防止 loginById 触发 loadCustomerQuote 重新激活已下单的 quote */
            $this->checkoutSession->setQuoteId(null);
            $this->customerSession->loginById($order->getCustomerId());
        }
    }

    /**
     * 从 cache 恢复登录状态
     *
     * 3D 验证跨站 POST 回来时 session 完全重建，登录态丢失。
     * 从 POST 参数拿到 order_number，查 cache 获取 customer_id，loginById() 恢复。
     */
    private function restoreLoginState(): void
    {
        try {
            if ($this->customerSession->isLoggedIn()) {
                return;
            }

            $orderNumber = $this->getRequest()->getParam('order_number', '');
            if (empty($orderNumber)) {
                return;
            }

            $cacheKey = self::CACHE_LOGIN_PREFIX . $orderNumber;
            $customerId = $this->cache->load($cacheKey);

            if (empty($customerId)) {
                return;
            }

            /* 先清空 quoteId，防止 loginById 触发 loadCustomerQuote 重新激活已下单的 quote */
            $this->checkoutSession->setQuoteId(null);
            $this->customerSession->loginById((int) $customerId);
            $this->cache->remove($cacheKey);

            $this->logger->info('[Oceanpayment] Login state restored from cache', [
                'order_number' => $orderNumber,
                'customer_id'  => $customerId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Failed to restore login state: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 重定向到结账成功页面
     *
     * @return ResultInterface
     */
    private function redirectToSuccess(): ResultInterface
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/onepage/success');
        return $resultRedirect;
    }

    /**
     * 重定向到结账失败页面
     *
     * @return ResultInterface
     */
    private function redirectToFailure(): ResultInterface
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/onepage/failure');
        return $resultRedirect;
    }
}
