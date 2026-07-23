<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Callback;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;

use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 回调统一处理器
 *
 * 将 Back Controller（同步回调）和 Notification REST API（异步通知）中的
 * 共享逻辑集中到一处，消除代码重复：
 *
 * - 签名验证（SHA256）
 * - secureCode 按 terminal 匹配 sandbox/production
 * - 订单加载（按 increment_id）
 * - 订单状态更新（成功/失败/待处理/高风险）
 * - 发票创建
 * - 订单邮件发送
 * - 订单备注添加
 *
 * 签名计算和字符过滤由 SignatureHelper 统一管理，本类仅定义回调签名字段顺序。
 */
class CallbackProcessor
{
    /**
     * 支付成功状态码
     */
    public const PAYMENT_STATUS_SUCCESS = 1;

    /**
     * 支付失败状态码
     */
    public const PAYMENT_STATUS_FAILED = 0;

    /**
     * 支付待处理状态码（pre-auth）
     */
    public const PAYMENT_STATUS_PENDING = -1;

    /**
     * 高风险状态码
     */
    public const PAYMENT_STATUS_HIGH_RISK = 10000;

    /**
     * 回调签名验证字段顺序（严格按 Oceanpayment 文档）
     */
    public const SIGN_FIELDS = [
        'account',
        'terminal',
        'order_number',
        'order_currency',
        'order_amount',
        'order_notes',
        'card_number',
        'payment_id',
        'payment_authType',
        'payment_status',
        'payment_details',
        'payment_risk',
    ];

    /**
     * 已处理的订单状态列表
     */
    private const PROCESSED_STATES = [
       // Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
    ];

    /**
     * 共享网关配置的 XML 路径前缀
     */
    private const SHARED_CONFIG_PREFIX = 'payment/oceanpayment_payment';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var InvoiceService
     */
    private InvoiceService $invoiceService;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var DbTransaction
     */
    private DbTransaction $dbTransaction;

    /**
     * @var SignatureHelper 签名与 URL 集中管理
     */
    private SignatureHelper $signatureHelper;

    /**
     * @var EventManager
     */
    private EventManager $eventManager;

    /**
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param InvoiceService $invoiceService
     * @param OrderSender $orderSender
     * @param DbTransaction $dbTransaction
     * @param SignatureHelper $signatureHelper
     * @param EventManager $eventManager
     */
    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ScopeConfigInterface $scopeConfig,
        InvoiceService $invoiceService,
        OrderSender $orderSender,
        DbTransaction $dbTransaction,
        SignatureHelper $signatureHelper,
        EventManager $eventManager
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->invoiceService = $invoiceService;
        $this->orderSender = $orderSender;
        $this->dbTransaction = $dbTransaction;
        $this->signatureHelper = $signatureHelper;
        $this->eventManager = $eventManager;
    }

    /**
     * 验证回调签名
     *
     * 由 SignatureHelper 计算签名，secureCode 根据回调中的 terminal 匹配 sandbox/production
     *
     * @param array $params 回调参数
     * @return bool 签名是否有效
     */
    public function verifySignature(array $params): bool
    {
        $returnedSign = $params['signValue'] ?? '';
        $secureCode = $this->resolveSecureCode($params['terminal'] ?? '');
        $expectedSign = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $params,
            $secureCode
        );

        if (strcasecmp($returnedSign, $expectedSign) === 0) {
            return true;
        }

        $this->logger->warning('[Oceanpayment] Signature mismatch', [
            'expected' => $expectedSign,
            'returned' => $returnedSign,
        ]);

        return false;
    }

    /**
     * 通过 increment_id 加载订单
     *
     * @param string $incrementId 订单增量 ID（即 Oceanpayment 的 order_number）
     * @return OrderInterface|null
     */
    public function loadOrder(string $incrementId): ?OrderInterface
    {
        if (empty($incrementId)) {
            return null;
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId, 'eq')
                ->create();

            $orders = $this->orderRepository->getList($searchCriteria)->getItems();

            if (!empty($orders)) {
                return reset($orders);
            }
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Failed to load order #{incrementId}: {message}', [
                'incrementId' => $incrementId,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 检查订单是否已处理
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isOrderAlreadyProcessed(OrderInterface $order): bool
    {
        return in_array($order->getState(), self::PROCESSED_STATES, true);
    }

    /**
     * 处理回调：更新订单状态、创建发票、发送邮件、添加备注
     *
     * @param OrderInterface $order 订单对象
     * @param array $params 回调参数
     * @param string $source 回调来源标识（如 'Back'、'Notice'），用于日志区分
     */
    public function processCallback(OrderInterface $order, array $params, string $source): void
    {
        $paymentStatus = (int) ($params['payment_status'] ?? self::PAYMENT_STATUS_PENDING);
        $payment = $order->getPayment();
        file_put_contents(BP.'/var/testf.log',$paymentStatus."\n",FILE_APPEND);
        switch ($paymentStatus) {
            case self::PAYMENT_STATUS_SUCCESS:
                $this->processPaymentSuccess($order, $payment, $params, $source);
                break;

            case self::PAYMENT_STATUS_FAILED:
                 file_put_contents(BP.'/var/testf.log','FAL'."\n",FILE_APPEND);
                $this->processPaymentFailure($order, $payment, $params, $source);
                break;

            case self::PAYMENT_STATUS_HIGH_RISK:
                $this->processHighRisk($order, $payment, $params, $source);
                break;

            case self::PAYMENT_STATUS_PENDING:
            default:
                $this->processPaymentPending($order, $payment, $params, $source);
                break;
        }

        $this->addPaymentComment($order, $params, $paymentStatus, $source);

       
        $this->orderRepository->save($order);
        
      
    }

    /**
     * 根据回调中的 terminal 匹配对应的 secureCode
     *
     * Magento 框架在 ScopeConfig 层已自动解密加密字段，无需手动处理
     *
     * @param string $callbackTerminal 回调中的 terminal 值
     * @return string
     */
    private function resolveSecureCode(string $callbackTerminal): string
    {
        if (empty($callbackTerminal)) {
            return $this->getSecureCodeByEnvironment();
        }

        $sandboxTerminal = (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/sandbox_terminal',
            ScopeInterface::SCOPE_STORE
        );
        $productionTerminal = (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/production_terminal',
            ScopeInterface::SCOPE_STORE
        );

        if ($callbackTerminal === $sandboxTerminal) {
            $this->logger->debug('[Oceanpayment] Matched sandbox terminal');
            return (string) $this->scopeConfig->getValue(
                self::SHARED_CONFIG_PREFIX . '/sandbox_securecode',
                ScopeInterface::SCOPE_STORE
            );
        }

        if ($callbackTerminal === $productionTerminal) {
            $this->logger->debug('[Oceanpayment] Matched production terminal');
            return (string) $this->scopeConfig->getValue(
                self::SHARED_CONFIG_PREFIX . '/production_securecode',
                ScopeInterface::SCOPE_STORE
            );
        }

        $this->logger->warning('[Oceanpayment] Terminal {terminal} did not match config, falling back to current environment', [
            'terminal' => $callbackTerminal,
        ]);

        return $this->getSecureCodeByEnvironment();
    }

    /**
     * 根据当前环境配置获取 secureCode
     *
     * Magento 框架在 ScopeConfig 层已自动解密加密字段，无需手动处理
     *
     * @return string
     */
    private function getSecureCodeByEnvironment(): string
    {
        $environment = (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/environment',
            ScopeInterface::SCOPE_STORE
        );

        $pathKey = ($environment === 'production')
            ? 'production_securecode'
            : 'sandbox_securecode';

        return (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/' . $pathKey,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * 处理支付成功
     *
     * @param OrderInterface $order
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param array $params
     * @param string $source
     */
    private function processPaymentSuccess(OrderInterface $order, $payment, array $params, string $source): void
    {
        $paymentId = $params['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->setLastTransId($paymentId);
        }

        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_card_number', $params['card_number'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_auth_type', $params['payment_authType'] ?? '');

        $payment->registerCaptureNotification($order->getTotalDue());

        $this->sendOrderEmail($order);

        $this->logger->info('[Oceanpayment] [{source}] Order #{incrementId} payment SUCCESS', [
            'source' => $source,
            'incrementId' => $order->getIncrementId(),
        ]);
     
           /* 分发回调事件，供其他模块通过观察者模式监听 */
        $this->eventManager->dispatch('oceanpayment_callback_after', [
            'payment'        => $order->getPayment(),
            'order'          => $order,
            'callback_params' => $params,
        ]);
    }

    /**
     * 处理支付失败
     *
     * @param OrderInterface $order
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param array $params
     * @param string $source
     */
    private function processPaymentFailure(OrderInterface $order, $payment, array $params, string $source): void
    {
        $order->setState(Order::STATE_CANCELED);
        $order->setStatus(Order::STATE_CANCELED);

        $paymentId = $params['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->addTransaction(PaymentTransaction::TYPE_VOID);
        }

        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_card_number', $params['card_number'] ?? '');

        $this->logger->info('[Oceanpayment] [{source}] Order #{incrementId} payment FAILED', [
            'source' => $source,
            'incrementId' => $order->getIncrementId(),
        ]);
    }

    /**
     * 处理支付待处理（pre-auth）
     *
     * @param OrderInterface $order
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param array $params
     * @param string $source
     */
    private function processPaymentPending(OrderInterface $order, $payment, array $params, string $source): void
    {
        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);

        $paymentId = $params['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->addTransaction(PaymentTransaction::TYPE_AUTH);
        }

        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_card_number', $params['card_number'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_auth_type', $params['payment_authType'] ?? '');

        $this->logger->info('[Oceanpayment] [{source}] Order #{incrementId} payment PENDING (pre-auth)', [
            'source' => $source,
            'incrementId' => $order->getIncrementId(),
        ]);
    }

    /**
     * 处理高风险交易
     *
     * @param OrderInterface $order
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param array $params
     * @param string $source
     */
    private function processHighRisk(OrderInterface $order, $payment, array $params, string $source): void
    {
        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);

        $paymentId = $params['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->addTransaction(PaymentTransaction::TYPE_AUTH);
        }

        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_card_number', $params['card_number'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_auth_type', $params['payment_authType'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_high_risk', '1');

        $order->addCommentToStatusHistory(
            'Oceanpayment High Risk Transaction | Payment ID: ' . ($paymentId ?: 'N/A')
            . ' | Risk: ' . ($params['payment_risk'] ?? 'N/A')
            . ' | Details: ' . ($params['payment_details'] ?? 'N/A')
        );

        $this->logger->warning('[Oceanpayment] [{source}] Order #{incrementId} HIGH RISK transaction', [
            'source' => $source,
            'incrementId' => $order->getIncrementId(),
        ]);
    }

    /**
     * 发送订单确认邮件
     *
     * @param OrderInterface $order
     */
    private function sendOrderEmail(OrderInterface $order): void
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
                $this->logger->info('[Oceanpayment] Order confirmation email sent for order #{incrementId}', [
                    'incrementId' => $order->getIncrementId(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Failed to send order email for #{incrementId}: {message}', [
                'incrementId' => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建发票
     *
     * @param OrderInterface $order
     */
    private function createInvoice(OrderInterface $order): void
    {
        try {
            if (!$order->canInvoice()) {
                $this->logger->debug('[Oceanpayment] Order #{incrementId} cannot be invoiced', [
                    'incrementId' => $order->getIncrementId(),
                ]);
                return;
            }

            $payment = $order->getPayment();
            if (!$payment || !$payment->getTransactionId()) {
                $this->logger->error('[Oceanpayment] Cannot create invoice - payment transaction ID is missing', [
                    'incrementId' => $order->getIncrementId(),
                ]);
                return;
            }

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->setTransactionId($payment->getTransactionId());
            $invoice->register();

            $invoice->getOrder()->setIsInProcess(true);

            $this->dbTransaction
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();

            $this->logger->info('[Oceanpayment] Invoice #{invoiceIncrementId} created for order #{incrementId}', [
                'invoiceIncrementId' => $invoice->getIncrementId(),
                'incrementId' => $order->getIncrementId(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Failed to create invoice for order #{incrementId}: {message}', [
                'incrementId' => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 将支付详情添加到订单备注
     *
     * @param OrderInterface $order
     * @param array $params
     * @param int $paymentStatus
     * @param string $source
     */
    private function addPaymentComment(OrderInterface $order, array $params, int $paymentStatus, string $source): void
    {
        $statusLabels = [
            self::PAYMENT_STATUS_SUCCESS   => 'SUCCESS',
            self::PAYMENT_STATUS_FAILED    => 'FAILED',
            self::PAYMENT_STATUS_PENDING   => 'PENDING',
            self::PAYMENT_STATUS_HIGH_RISK => 'HIGH RISK',
        ];

        $statusLabel = $statusLabels[$paymentStatus] ?? 'UNKNOWN';

        $comment = sprintf(
            '[%s] Oceanpayment Payment %s | Payment ID: %s | Card: %s | AuthType: %s | Details: %s',
            $source,
            $statusLabel,
            $params['payment_id'] ?? 'N/A',
            $params['card_number'] ?? 'N/A',
            $params['payment_authType'] ?? 'N/A',
            $params['payment_details'] ?? 'N/A'
        );

        // 失败时附加 payment_solutions（Oceanpayment 返回的解决建议）
        if ($paymentStatus === self::PAYMENT_STATUS_FAILED && !empty($params['payment_solutions'])) {
            $comment .= ' | Solutions: ' . $params['payment_solutions'];
        }

        $order->addCommentToStatusHistory($comment);
    }
}
