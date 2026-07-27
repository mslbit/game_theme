<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Service;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Psr\Log\LoggerInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Oceanpayment 支付成功处理服务
 *
 * 统一处理支付成功后的订单状态更新（capture）。
 * 供 CallbackProcessor（回调场景）共用。
 *
 * 并发保护：使用 LockManagerInterface 互斥锁，防止同步回调和异步通知
 * 同时处理同一订单导致重复 capture 或数据不一致。
 */
class PaymentSuccessService
{
    /**
     * 锁前缀
     */
    private const LOCK_PREFIX = 'oceanpayment_payment_success_';

    /**
     * @var LockManagerInterface
     */
    private LockManagerInterface $lockManager;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EventManager
     */
    private EventManager $eventManager;

    /**
     * @param LockManagerInterface $lockManager
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
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
        $this->logger = $logger;
        $this->eventManager = $eventManager;
    }

    /**
     * 处理支付成功
     *
     * 并发安全：通过 LockManagerInterface 互斥锁保证同一订单只有一个进程执行 capture。
     * 同步回调和异步通知可能同时到达，锁确保只有一个成功处理。
     *
     * @param Order $order 已入库的订单
     * @param array $params 回调参数
     */
    public function execute(Order $order, array $params = []): void
    {
        $lockKey = self::LOCK_PREFIX . $order->getEntityId();

        if (!$this->lockManager->lock($lockKey, 5)) {
            $this->logger->warning('[Oceanpayment] PaymentSuccessService lock acquisition failed, another process is handling', [
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
     *
     * @param Order $order
     * @param array $params
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
            $this->logger->info('[Oceanpayment] PaymentSuccessService order already processed', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        if(!$payment->canCapture()) return;

        $paymentId = $params['payment_id'] ?? '';

        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->setLastTransId($paymentId);
        }

        /** @var OrderPaymentInterface $payment */
        $payment->setIsTransactionClosed(false);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation('ocpaysuccess', json_encode($params));
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);

        /* capture：授权已在 Command 中完成，这里只做 capture */
        $payment->registerCaptureNotification($order->getBaseTotalDue(), true);

        $this->sendOrderEmail($order);

        $this->orderRepository->save($order);

        $this->logger->info('[Oceanpayment] PaymentSuccessService processed', [
            'order_id'    => $order->getEntityId(),
            'payment_id'  => $paymentId,
            'params'      => $params
        ]);

        /* 分发回调事件，供其他模块通过观察者模式监听 */
        $this->eventManager->dispatch('oceanpayment_callback_after', [
            'payment'        => $order->getPayment(),
            'order'          => $order
        ]);
    }

    /**
     * 发送订单确认邮件
     *
     * @param Order $order
     */
    private function sendOrderEmail(Order $order): void
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] PaymentSuccessService failed to send email: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
