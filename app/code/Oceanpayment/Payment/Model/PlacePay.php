<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model;

use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Oceanpayment\Payment\Api\PlacePayInterface;
use Override;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Oceanpayment 获取支付数据实现
 *
 * 嵌入式支付场景：返回缓存的 checkout data JSON 或 pay_url 字符串。
 *
 * 使用 quoteId（而非 orderId）作为缓存键，避免暴露订单ID。
 *
 * 如果缓存为空（支付数据组装失败），自动取消订单并恢复购物车，
 * 抛出异常让前端知道需要重新下单。
 */
class PlacePay implements PlacePayInterface
{
    /**
     * pay_url 缓存键前缀
     */
    private const KEY_PAY_URL = 'oceanpayment_pay_url';

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var QuoteIdMaskFactory
     */
    private QuoteIdMaskFactory $quoteIdMaskFactory;

    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param ResourceConnection $resource
     */
    public function __construct(
        CacheInterface $cache,
        LoggerInterface $logger,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $quoteRepository,
        ResourceConnection $resource
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteRepository = $quoteRepository;
        $this->resource = $resource;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function getPay(string $quoteId): ?string
    {
        $realQuoteId = $this->resolveQuoteId($quoteId);

        $cachedValue = $this->cache->load(sprintf('%s_%s', self::KEY_PAY_URL, $realQuoteId));

        if ($cachedValue) {
            $this->logger->info('[Oceanpayment] PlacePay: cached data found', [
                'quote_id' => $realQuoteId,
            ]);
            return $cachedValue;
        }

        /*
         * 缓存为空时，先查数据库 sales_order_payment.additional_information
         * pay_url 可能已保存到 additional_information 中，
         * 但缓存写入因竞态/过期等原因失败。
         */
        $payUrlFromDb = $this->loadPayUrlFromDb($realQuoteId);
        if ($payUrlFromDb !== null) {
            $this->logger->info('[Oceanpayment] PlacePay: pay_url found in DB, restoring cache', [
                'quote_id' => $realQuoteId,
            ]);
            /* 回写缓存，避免后续请求再查DB */
            $this->cache->save(
                $payUrlFromDb,
                sprintf('%s_%s', self::KEY_PAY_URL, $realQuoteId),
                [],
                3600
            );
            return $payUrlFromDb;
        }

        /*
         * 缓存和数据库都没有 pay_url = 支付数据组装失败
         * 订单已创建但无法继续支付，必须回滚：取消订单 + 恢复购物车
         */
        $this->logger->error('[Oceanpayment] PlacePay: pay_url not found in cache or DB, rolling back order', [
            'quote_id' => $realQuoteId,
        ]);

        $this->cancelOrderAndRestoreQuote($realQuoteId);

        throw new LocalizedException(__('Payment setup failed. Your cart has been restored, please try again.'));
    }

    /**
     * 取消订单并恢复购物车
     *
     * 嵌入式支付没有数据库事务保护，placeOrder 成功后如果支付数据丢失，
     * 订单处于 pending_payment 但无法完成支付。必须：
     * 1. 取消订单（状态改为 canceled）
     * 2. 恢复 quote（is_active=1），让用户可以重新下单
     *
     * @param string $quoteId 真实 quoteId
     */
    private function cancelOrderAndRestoreQuote(string $quoteId): void
    {
        try {
            /* 恢复 quote 为活跃状态 */
            $quote = $this->quoteRepository->get((int) $quoteId);
            if ($quote->getId()) {
                $quote->setIsActive(true);
                $this->quoteRepository->save($quote);
                $this->logger->info('[Oceanpayment] PlacePay: quote reactivated', [
                    'quote_id' => $quoteId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] PlacePay: failed to reactivate quote', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            /* 直接用 SQL 取消订单，避免加载完整 Order 模型 */
            $connection = $this->resource->getConnection();
            $salesOrderTable = $this->resource->getTableName('sales_order');

            $bind = [
                'state'  => 'canceled',
                'status' => 'canceled',
            ];
            $where = ['quote_id = ?' => (int) $quoteId, 'state = ?' => 'pending_payment'];
            $affected = $connection->update($salesOrderTable, $bind, $where);

            $this->logger->info('[Oceanpayment] PlacePay: order cancelled', [
                'quote_id' => $quoteId,
                'affected' => $affected,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] PlacePay: failed to cancel order', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 从数据库 sales_order_payment.additional_information 中读取 pay_url
     *
     * 此方法作为缓存未命中时的兜底查询。
     *
     * @param string $quoteId 真实 quoteId
     * @return string|null pay_url 字符串，不存在则返回 null
     */
    private function loadPayUrlFromDb(string $quoteId): ?string
    {
        try {
            $connection = $this->resource->getConnection();

            /* 先找 order_id */
            $orderTable = $this->resource->getTableName('sales_order');
            $orderId = $connection->fetchOne(
                $connection->select()
                    ->from($orderTable, ['entity_id'])
                    ->where('quote_id = ?', (int) $quoteId)
                    ->where('state = ?', 'pending_payment')
                    ->order('entity_id DESC')
                    ->limit(1)
            );

            if (!$orderId) {
                $this->logger->warning('[Oceanpayment] PlacePay: no pending_payment order found for quote', [
                    'quote_id' => $quoteId,
                ]);
                return null;
            }

            /* 读取 additional_information */
            $paymentTable = $this->resource->getTableName('sales_order_payment');
            $additionalInfo = $connection->fetchOne(
                $connection->select()
                    ->from($paymentTable, ['additional_information'])
                    ->where('parent_id = ?', (int) $orderId)
                    ->limit(1)
            );

            if (empty($additionalInfo)) {
                return null;
            }

            /* additional_information 是序列化存储的，反序列化后查找 pay_url */
            $data = unserialize($additionalInfo);
            if (!is_array($data)) {
                return null;
            }

            $payUrl = $data[self::KEY_PAY_URL] ?? null;
            if (!empty($payUrl) && is_string($payUrl)) {
                return $payUrl;
            }
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] PlacePay: failed to load pay_url from DB', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 解析 quoteId：masked ID → 真实 ID
     *
     * @param string $quoteId 前端传入的 quoteId（可能是 masked 或真实 ID）
     * @return string 真实 quoteId
     */
    private function resolveQuoteId(string $quoteId): string
    {
        if (ctype_digit($quoteId)) {
            return $quoteId;
        }

        try {
            $mask = $this->quoteIdMaskFactory->create();
            $mask->load($quoteId, 'masked_id');
            $realId = $mask->getQuoteId();

            if ($realId) {
                return (string) $realId;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Oceanpayment] PlacePay: Failed to resolve masked quote ID', [
                'masked_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
        }

        return $quoteId;
    }
}
