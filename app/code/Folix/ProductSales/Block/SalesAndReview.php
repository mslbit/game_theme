<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Block;

use Folix\ProductSales\Model\ProductSalesFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\App\RequestInterface;
use Magento\Review\Model\AppendSummaryDataFactory;
use Magento\Review\Model\Review;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PDP 产品成交量 + 评论组合 Block
 *
 * 将成交量和评论数据组合，前端使用 Flex 布局一行显示：
 * [五星评分] [评论数] [成交量标签]
 */
class SalesAndReview extends Template
{
    /**
     * @var ProductSalesFactory
     */
    private $productSalesFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var AppendSummaryDataFactory
     */
    private $appendSummaryDataFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Folix\ProductSales\Model\ProductSales|null
     */
    private $salesData;

    /**
     * @var \Magento\Catalog\Model\Product|null
     */
    private $product;

    /**
     * @var bool 评论数据是否已加载
     */
    private $reviewDataLoaded = false;

    /**
     * @param Template\Context $context
     * @param ProductSalesFactory $productSalesFactory
     * @param ProductRepositoryInterface $productRepository
     * @param AppendSummaryDataFactory $appendSummaryDataFactory
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        ProductSalesFactory $productSalesFactory,
        ProductRepositoryInterface $productRepository,
        AppendSummaryDataFactory $appendSummaryDataFactory,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->productSalesFactory = $productSalesFactory;
        $this->productRepository = $productRepository;
        $this->request = $context->getRequest();
        $this->appendSummaryDataFactory = $appendSummaryDataFactory;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * 获取当前产品
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    public function getProduct()
    {
        if ($this->product === null) {
            $productId = (int) $this->request->getParam('id');
            if ($productId) {
                try {
                    $this->product = $this->productRepository->getById($productId);
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $this->product = null;
                }
            }
        }
        return $this->product;
    }

    /**
     * 获取成交量数据
     *
     * @return \Folix\ProductSales\Model\ProductSales|null
     */
    public function getSalesData()
    {
        if ($this->salesData === null) {
            $product = $this->getProduct();
            if ($product && $product->getId()) {
                /** @var \Folix\ProductSales\Model\ProductSales $sales */
                $sales = $this->productSalesFactory->create();
                $sales->loadByProductId((int) $product->getId());
                $this->salesData = $sales->getId() ? $sales : null;
            }
        }
        return $this->salesData;
    }

    /**
     * 获取格式化后的成交量（如 "970.7k+"）
     *
     * @return string
     */
    public function getFormattedVolume(): string
    {
        $sales = $this->getSalesData();
        return $sales ? $sales->getFormattedVolume() : '';
    }

    /**
     * 是否有成交量数据
     *
     * @return bool
     */
    public function hasSalesData(): bool
    {
        return $this->getSalesData() !== null;
    }

    /**
     * 加载评论汇总数据到产品对象
     *
     * 调用 AppendSummaryData 将 review_entity_summary 表的 reviews_count 和 rating_summary
     * 加载到产品对象上，这样 getReviewsCount() 和 getRatingSummary() 才能返回数据
     */
    private function loadReviewData(): void
    {
        if ($this->reviewDataLoaded) {
            return;
        }

        $product = $this->getProduct();
        if (!$product) {
            $this->reviewDataLoaded = true;
            return;
        }

        // 如果产品已经有评论数据，不需要重新加载
        if ($product->getRatingSummary() !== null) {
            $this->reviewDataLoaded = true;
            return;
        }

        // 调用 AppendSummaryData 加载评论汇总数据
        try {
            $this->appendSummaryDataFactory->create()->execute(
                $product,
                (int)$this->storeManager->getStore()->getId(),
                Review::ENTITY_PRODUCT_CODE
            );
        } catch (\Exception $e) {
            // 静默失败，不影响页面显示
        }

        $this->reviewDataLoaded = true;
    }

    /**
     * 获取评分百分比（0-100）
     *
     * 数据来源：review_entity_summary 表的 rating_summary 字段
     * 通过 AppendSummaryData 模型加载到产品对象上
     *
     * @return int
     */
    public function getRatingPercent(): int
    {
        $this->loadReviewData();
        $product = $this->getProduct();
        if (!$product) {
            return 0;
        }

        // getRatingSummary() 返回的是百分比值（0-100），不是对象
        // 数据来自 review_entity_summary.rating_summary
        return (int) $product->getRatingSummary();
    }

    /**
     * 获取评论数量
     *
     * @return int
     */
    public function getReviewsCount(): int
    {
        $this->loadReviewData();
        $product = $this->getProduct();
        if (!$product) {
            return 0;
        }

        return (int) $product->getReviewsCount();
    }

    /**
     * 获取评论列表页 URL
     *
     * @return string
     */
    public function getReviewsUrl(): string
    {
        $product = $this->getProduct();
        if (!$product) {
            return '#';
        }

        return $product->getUrlModel()->getUrl($product, ['_ignore_category' => true]) . '#reviews';
    }

    /**
     * 将百分比转换为五星评分（1-5）
     *
     * @return float
     */
    public function getStarRating(): float
    {
        $percent = $this->getRatingPercent();
        return round($percent / 20, 1);
    }

    /**
     * 是否有评论数据
     *
     * @return bool
     */
    public function hasReviews(): bool
    {
        return $this->getReviewsCount() > 0;
    }
}
