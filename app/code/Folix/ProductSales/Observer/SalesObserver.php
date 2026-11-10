<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Observer;

use Folix\ProductSales\Model\ProductSalesFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * 订单完成后自动累加产品成交量
 *
 * 监听 checkout_submit_all_after 事件（订单完全提交后触发），
 * 遍历订单中每个产品，累加 display_volume。
 */
class SalesObserver implements ObserverInterface
{
    /**
     * @var ProductSalesFactory
     */
    private $productSalesFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ProductSalesFactory $productSalesFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductSalesFactory $productSalesFactory,
        LoggerInterface $logger
    ) {
        $this->productSalesFactory = $productSalesFactory;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }

        foreach ($order->getAllVisibleItems() as $item) {
            $productId = (int) $item->getProductId();
            if ($productId <= 0) {
                continue;
            }

            try {
                /** @var \Folix\ProductSales\Model\ProductSales $sales */
                $sales = $this->productSalesFactory->create();
                $sales->loadByProductId($productId);

                if (!$sales->getId()) {
                    // 没有记录则跳过（未配置成交量的产品不自动创建）
                    continue;
                }

                $sales->incrementVolume(1);
                $sales->save();
            } catch (\Exception $e) {
                $this->logger->error('Folix_ProductSales: ' . $e->getMessage(), [
                    'product_id' => $productId,
                    'order_id' => $order->getId()
                ]);
            }
        }
    }
}
