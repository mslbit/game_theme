<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Api\CartRepositoryInterface;
use MslPay\LianLian\Api\PayUrlInterface;
use Psr\Log\LoggerInterface;

/**
 * 连连支付获取 payment_url 实现
 *
 * 从缓存中读取 authorize command 缓存的 payment_url。
 * 缓存 key: lianlian_pay_url_{quoteId}
 *
 * guest 用户前端传的是 masked quote ID，需转换为真实 quote ID 再查缓存
 */
class PayUrl implements PayUrlInterface
{
    private const CACHE_PAY_URL_PREFIX = 'lianlian_pay_url_';

    private CacheInterface $cache;
    private QuoteIdMask $quoteIdMask;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        QuoteIdMask $quoteIdMask,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->quoteIdMask = $quoteIdMask;
        $this->logger = $logger;
    }

    public function getPayUrl(int $quoteId, string $methodCode): string
    {
        return $this->loadPayUrl((string) $quoteId);
    }

    public function getGuestPayUrl(string $quoteId, string $methodCode): string
    {
        /* guest 用户前端传的是 masked quote ID，需转换为真实 quote ID */
        $realQuoteId = $this->resolveRealQuoteId($quoteId);
        return $this->loadPayUrl($realQuoteId);
    }

    /**
     * 将 masked quote ID 转换为真实 quote ID
     *
     * guest 用户结账时，前端 quote.getQuoteId() 返回的是 masked ID（如 kYj3v2），
     * 而缓存 key 使用的是真实 quote ID（数字），因此需要转换
     */
    private function resolveRealQuoteId(string $quoteId): string
    {
        /* 如果已经是纯数字，直接返回 */
        if (ctype_digit($quoteId)) {
            return $quoteId;
        }

        /* masked ID → 真实 quote ID */
        $mask = $this->quoteIdMask->load($quoteId, 'masked_id');
        $realId = $mask->getQuoteId();
        if ($realId) {
            return (string) $realId;
        }

        /* 转换失败，返回原值（缓存查不到会返回空字符串） */
        $this->logger->warning('[LianLian] PayUrl: failed to resolve masked quote ID', [
            'masked_id' => $quoteId,
        ]);
        return $quoteId;
    }

    private function loadPayUrl(string $quoteId): string
    {
        $cacheKey = self::CACHE_PAY_URL_PREFIX . $quoteId;
        $payUrl = (string) $this->cache->load($cacheKey);

        $this->logger->info('[LianLian] PayUrl API: ' . ($payUrl ? '(found)' : '(empty)'), [
            'quote_id' => $quoteId,
        ]);

        return $payUrl;
    }
}