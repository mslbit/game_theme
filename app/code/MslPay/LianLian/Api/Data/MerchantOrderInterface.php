<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付商户订单数据接口
 *
 * 定义 merchant_order 对象的字段常量，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface MerchantOrderInterface
{
    /** @var string 商户订单号 */
    public const MERCHANT_ORDER_ID = 'merchant_order_id';
    /** @var string 下单时间（格式：YmdHis） */
    public const MERCHANT_ORDER_TIME = 'merchant_order_time';
    /** @var string 订单金额 */
    public const ORDER_AMOUNT = 'order_amount';
    /** @var string 订单币种代码（ISO 4217） */
    public const ORDER_CURRENCY_CODE = 'order_currency_code';
    /** @var string 订单描述 */
    public const ORDER_DESCRIPTION = 'order_description';
    /** @var string 商品列表（嵌套数组） */
    public const PRODUCTS = 'products';
    /** @var string 收货人信息（嵌套对象，实物商品必传） */
    public const SHIPPING = 'shipping';

    /**
     * 获取商户订单号
     */
    public function getMerchantOrderId(): string;

    /**
     * 设置商户订单号
     */
    public function setMerchantOrderId(string $merchantOrderId): static;

    /**
     * 获取商户订单时间
     */
    public function getMerchantOrderTime(): string;

    /**
     * 设置商户订单时间
     */
    public function setMerchantOrderTime(string $merchantOrderTime): static;

    /**
     * 获取订单金额
     */
    public function getOrderAmount(): string;

    /**
     * 设置订单金额
     */
    public function setOrderAmount(string $orderAmount): static;

    /**
     * 获取订单币种代码
     */
    public function getOrderCurrencyCode(): string;

    /**
     * 设置订单币种代码
     */
    public function setOrderCurrencyCode(string $orderCurrencyCode): static;

    /**
     * 获取订单描述
     */
    public function getOrderDescription(): string;

    /**
     * 设置订单描述
     */
    public function setOrderDescription(string $orderDescription): static;

    /**
     * 获取商品列表
     *
     * @return ProductInterface[]
     */
    public function getProducts(): array;

    /**
     * 设置商品列表
     *
     * @param ProductInterface[] $products
     */
    public function setProducts(array $products): static;

    /**
     * 获取物流配送信息
     */
    public function getShipping(): ?ShippingInterface;

    /**
     * 设置物流配送信息
     */
    public function setShipping(ShippingInterface $shipping): static;
}
