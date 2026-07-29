<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Service;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Psr\Log\LoggerInterface;

/**
 * 连连支付成功处理服务
 *
 * 统一处理支付成功后的订单状态更新（capture）。
 * 供 Notification（异步通知）、PaymentQuery（主动查询）共用。
 *
 * 并发保护：使用 LockManagerInterface 互斥锁，防止同步回调和异步通知
 * 同时处理同一订单导致重复 capture 或数据不一致。
 */
class PaymentSuccessService
{
    private const LOCK_PREFIX = 'lianlian_payment_success_';

    private LockManagerInterface $lockManager;
    private OrderRepositoryInterface $orderRepository;
    private OrderSender $orderSender;
    private EventManager $eventManager;
    private LoggerInterface $logger;

    public function __construct(
        LockManagerInterface $lockManager,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        EventManager $eventManager,
        LoggerInterface $logger
    ) {
        $this->lockManager = $lockManager;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    /**
     * 处理支付成功
     *
     * 并发安全：通过 LockManagerInterface 互斥锁保证同一订单只有一个进程执行 capture。
     *
     * @param Order $order 已入库的订单
     * @param array $params 回调/查询参数
     */
    public function execute(Order $order, array $params = []): void
    {
        $lockKey = self::LOCK_PREFIX . $order->getEntityId();

        if (!$this->lockManager->lock($lockKey, 5)) {
            $this->logger->warning('[LianLian] PaymentSuccessService lock acquisition failed, another process is handling', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        try {
            $this->doExecute($order, $params);
        } finally {
            $this->lockManager->unlock($lockKey);
        }
    }

    /**
     * 实际处理逻辑（在锁保护内执行）
     */
    private function doExecute(Order $order, array $params = []): void
    {
        /* 重新加载订单，获取最新状态 */
        $order = $this->orderRepository->get($order->getEntityId());

        /** @var OrderPaymentInterface $payment */
        $payment = $order->getPayment();
        if (!$payment instanceof OrderPaymentInterface) {
            return;
        }

        /* 已处理过的订单跳过 */
        if ($order->getState() === Order::STATE_COMPLETE) {
            $this->logger->info('[LianLian] PaymentSuccessService order already processed', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        if (!$payment->canCapture()) {
            return;
        }

        $llTransactionId = $params['ll_transaction_id'] ?? '';

        if (!empty($llTransactionId)) {
            $payment->setTransactionId($llTransactionId);
            $payment->setLastTransId($llTransactionId);
        }

        $payment->setIsTransactionClosed(false);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation('lianlian_payment_status', 'PS');
        $payment->setAdditionalInformation('llpay_callback_data', json_encode($params));
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);

        /* capture：授权已在 Command 中完成，这里只做 capture */
        $payment->registerCaptureNotification($order->getBaseTotalDue(), true);

        $this->sendOrderEmail($order);

        $this->orderRepository->save($order);

        $this->logger->info('[LianLian] PaymentSuccessService processed', [
            'order_id'           => $order->getEntityId(),
            'll_transaction_id'  => $llTransactionId,
        ]);

        /* 分发回调事件，供其他模块通过观察者模式监听 */
        $this->eventManager->dispatch('llpay_callback_after', [
            'payment' => $order->getPayment(),
            'order'   => $order,
        ]);
    }

    /**
     * 发送订单确认邮件
     */
    private function sendOrderEmail(Order $order): void
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('[LianLian] PaymentSuccessService failed to send email: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
