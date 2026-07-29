<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付商品数据接口
 *
 * 定义 products 数组中每个商品对象的字段常量，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface ProductInterface
{
    /** @var string 商品ID */
    public const PRODUCT_ID = 'product_id';
    /** @var string 商品名称 */
    public const NAME = 'name';
    /** @var string 商品单价 */
    public const PRICE = 'price';
    /** @var string 商品数量 */
    public const QUANTITY = 'quantity';
    /** @var string 商品SKU */
    public const SKU = 'sku';
    /** @var string 商品URL（国际卡支付必传） */
    public const URL = 'url';
    /** @var string 商品分类 */
    public const CATEGORY = 'category';
    /** @var string 商品描述 */
    public const DESCRIPTION = 'description';
    /** @var string 物流提供商 */
    public const SHIPPING_PROVIDER = 'shipping_provider';

    /**
     * 获取商品ID
     */
    public function getProductId(): string;

    /**
     * 设置商品ID
     */
    public function setProductId(string $productId): static;

    /**
     * 获取商品名称
     */
    public function getName(): string;

    /**
     * 设置商品名称
     */
    public function setName(string $name): static;

    /**
     * 获取商品价格
     */
    public function getPrice(): string;

    /**
     * 设置商品价格
     */
    public function setPrice(string $price): static;

    /**
     * 获取商品数量
     */
    public function getQuantity(): int;

    /**
     * 设置商品数量
     */
    public function setQuantity(int $quantity): static;

    /**
     * 获取SKU编码
     */
    public function getSku(): string;

    /**
     * 设置SKU编码
     */
    public function setSku(string $sku): static;

    /**
     * 获取商品URL
     */
    public function getUrl(): string;

    /**
     * 设置商品URL
     */
    public function setUrl(string $url): static;

    /**
     * 获取商品分类
     */
    public function getCategory(): string;

    /**
     * 设置商品分类
     */
    public function setCategory(string $category): static;

    /**
     * 获取商品描述
     */
    public function getDescription(): string;

    /**
     * 设置商品描述
     */
    public function setDescription(string $description): static;

    /**
     * 获取物流服务商
     */
    public function getShippingProvider(): string;

    /**
     * 设置物流服务商
     */
    public function setShippingProvider(string $shippingProvider): static;
}
