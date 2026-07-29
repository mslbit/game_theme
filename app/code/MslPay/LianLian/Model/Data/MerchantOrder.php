<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\MerchantOrderInterface;
use MslPay\LianLian\Api\Data\ShippingInterface;

class MerchantOrder extends DataObject implements MerchantOrderInterface
{
    public function getMerchantOrderId(): string
    {
        return (string) ($this->_data[self::MERCHANT_ORDER_ID] ?? '');
    }

    public function setMerchantOrderId(string $merchantOrderId): static
    {
        return $this->setData(self::MERCHANT_ORDER_ID, $merchantOrderId);
    }

    public function getMerchantOrderTime(): string
    {
        return (string) ($this->_data[self::MERCHANT_ORDER_TIME] ?? '');
    }

    public function setMerchantOrderTime(string $merchantOrderTime): static
    {
        return $this->setData(self::MERCHANT_ORDER_TIME, $merchantOrderTime);
    }

    public function getOrderAmount(): string
    {
        return (string) ($this->_data[self::ORDER_AMOUNT] ?? '');
    }

    public function setOrderAmount(string $orderAmount): static
    {
        return $this->setData(self::ORDER_AMOUNT, $orderAmount);
    }

    public function getOrderCurrencyCode(): string
    {
        return (string) ($this->_data[self::ORDER_CURRENCY_CODE] ?? '');
    }

    public function setOrderCurrencyCode(string $orderCurrencyCode): static
    {
        return $this->setData(self::ORDER_CURRENCY_CODE, $orderCurrencyCode);
    }

    public function getOrderDescription(): string
    {
        return (string) ($this->_data[self::ORDER_DESCRIPTION] ?? '');
    }

    public function setOrderDescription(string $orderDescription): static
    {
        return $this->setData(self::ORDER_DESCRIPTION, $orderDescription);
    }

    /**
     * @return Product[]
     */
    public function getProducts(): array
    {
        return (array) ($this->_data[self::PRODUCTS] ?? []);
    }

    /**
     * @param Product[] $products
     */
    public function setProducts(array $products): static
    {
        return $this->setData(self::PRODUCTS, $products);
    }

    public function getShipping(): ?ShippingInterface
    {
        $shipping = $this->_data[self::SHIPPING] ?? null;
        return $shipping instanceof ShippingInterface ? $shipping : null;
    }

    public function setShipping(ShippingInterface $shipping): static
    {
        return $this->setData(self::SHIPPING, $shipping);
    }
}