<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Oceanpayment 订单入库后观察者
 *
 * pay_url 缓存逻辑已移至 SendTradeCommand::cachePayUrl()，
 * 此 Observer 保留空壳以兼容 events.xml 注册。
 */
class PlcaceOrderObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑（空壳，兼容 events.xml 注册）
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        /* pay_url 缓存已移至 SendTradeCommand::cachePayUrl() */
    }
}