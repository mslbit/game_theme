<?php
declare(strict_types=1);

namespace FolixCode\ThirdPartyOrder\Observer;

use FolixCode\ThirdPartyOrder\Model\MessageQueue\Publisher;
use FolixCode\ThirdPartyOrder\Model\ResourceModel\ThirdPartyOrderDbResource as ThirdPartyOrderResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Order Payment Success Observer
 * 
 * 监听订单支付成功事件,发布MQ消息异步同步到第三方
 * 
 * 监听事件: sales_order_payment_place_end 或 checkout_submit_all_after
 */
class OrderPaymentSuccess implements ObserverInterface
{
    private Publisher $publisher;
    private ThirdPartyOrderResource $resource;
    private TimezoneInterface $timezone;
    private LoggerInterface $logger;

    public function __construct(
        Publisher $publisher,
        ThirdPartyOrderResource $resource,
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->resource = $resource;
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Order\Payment|null $payment */
            $payment = $observer->getEvent()->getData('payment');
           
            if (!$payment || !$payment instanceof Order\Payment) {
                return;
            }

            /** @var Order $order */
            $order = $payment->getOrder();

            // 检查订单状态
            if (!$this->isOrderPayed($order)) {
                return;
            }

            if($this->resource->loadByMagentoOrderId((int)$order->getId())) return;

            $this->logger->info('Order payment success, pre-initializing third party orders', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId()
            ]);

           
            // 发布MQ消息,异步处理
            $this->publisher->publishOrderSync([
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId()
            ]);

             // 【优化】预初始化所有 Items 到数据库
            $this->preInitializeOrderItems($order);


        } catch (\Exception $e) {
            // 不阻塞主流程,只记录错误
            $this->logger->error('Failed to publish order sync message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 预初始化订单的所有 Items 到第三方订单表
     *
     * @param Order $order
     * @return void
     */
    private function preInitializeOrderItems(Order $order): void
    {
        $items = [];
        $now = $this->timezone->date()->format('Y-m-d H:i:s');

        $configureInfo = [];

        foreach ($order->getItems() as $item) {
            // 跳过虚拟商品或非需要同步的商品类型
            // 这里可以根据实际业务需求添加过滤条件
             $this->logger->info('add order item', [
               'item_id' => $item->getItemId(),
               'order_type' => $item->getProductType(),
               'is_virtual' => $item->getIsVirtual(),
               'magento_order_id' => $order->getId(),
           ]);
            if($item->getProductType() === 'configurable'){
                $configureInfo[$item->getItemId()] =  $item->getRowTotal();
                 continue;
                
                 }
            
            $items[] = [
                'entity_id' => $item->getItemId(),
                'magento_order_id' => $order->getId(),
                'customer_id' => $order->getCustomerId(),
                'increment_id' => $order->getIncrementId(),
                'customer_email' => $order->getCustomerEmail(),
                'customer_name' => $order->getCustomerName(),
                'product_name' => $item->getName(),
                'row_total' => isset($configureInfo[$item->getItemId()]) ? $configureInfo[$item->getItemId()] : $item->getRowTotal(),  // Item 金额（含所有费用）
                'sync_status' => 'pending',  // 初始状态：待同步
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        if (!empty($items)) {
            $this->resource->batchInsert($items);
            
            $this->logger->info('Pre-initialized order items', [
                'order_id' => $order->getId(),
                'items_count' => count($items)
            ]);
        }
    }

    /**
     * 检查订单是否已支付
     *
     * @param Order $order
     * @return bool
     */
    private function isOrderPayed(Order $order): bool
    {
           file_put_contents(BP.'/var/order.log',$order->getState(),FILE_APPEND);
        // 检查订单状态是否为已支付
        if ($order->getState() === Order::STATE_PROCESSING || $order->getState() === 'complete') {
            return true;
        }
        
        // 安全地获取默认状态
        $config = $order->getConfig();
        if ($config) {
            $defaultStatus = $config->getStateDefaultStatus(Order::STATE_PROCESSING);
            if ($defaultStatus && $order->getStatus() === $defaultStatus) {
                return true;
            }
        }
        
        return false;
    }
}
