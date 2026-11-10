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

/**
 * PDP 产品成交量 Block
 *
 * 通过 ProductRepositoryInterface 获取当前产品，
 * 根据产品 ID 加载成交量数据，前端格式化输出。
 */
class SalesVolume extends Template
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
     * @var \Folix\ProductSales\Model\ProductSales|null
     */
    private $salesData;

    /**
     * @param Template\Context $context
     * @param ProductSalesFactory $productSalesFactory
     * @param ProductRepositoryInterface $productRepository
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        ProductSalesFactory $productSalesFactory,
        ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        $this->productSalesFactory = $productSalesFactory;
        $this->productRepository = $productRepository;
        $this->request = $context->getRequest();
        parent::__construct($context, $data);
    }

    /**
     * 获取当前产品
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    public function getProduct()
    {
        $productId = (int) $this->request->getParam('id');
        if (!$productId) {
            return null;
        }

        try {
            return $this->productRepository->getById($productId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * 获取当前产品的成交量数据
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
}
