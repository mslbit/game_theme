<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\ProductInterface;

class Product extends DataObject implements ProductInterface
{
    public function getProductId(): string
    {
        return (string) ($this->_data[self::PRODUCT_ID] ?? '');
    }

    public function setProductId(string $productId): static
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getName(): string
    {
        return (string) ($this->_data[self::NAME] ?? '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::NAME, $name);
    }

    public function getPrice(): string
    {
        return (string) ($this->_data[self::PRICE] ?? '');
    }

    public function setPrice(string $price): static
    {
        return $this->setData(self::PRICE, $price);
    }

    public function getQuantity(): int
    {
        return (int) ($this->_data[self::QUANTITY] ?? 0);
    }

    public function setQuantity(int $quantity): static
    {
        return $this->setData(self::QUANTITY, $quantity);
    }

    public function getSku(): string
    {
        return (string) ($this->_data[self::SKU] ?? '');
    }

    public function setSku(string $sku): static
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getUrl(): string
    {
        return (string) ($this->_data[self::URL] ?? '');
    }

    public function setUrl(string $url): static
    {
        return $this->setData(self::URL, $url);
    }

    public function getCategory(): string
    {
        return (string) ($this->_data[self::CATEGORY] ?? '');
    }

    public function setCategory(string $category): static
    {
        return $this->setData(self::CATEGORY, $category);
    }

    public function getDescription(): string
    {
        return (string) ($this->_data[self::DESCRIPTION] ?? '');
    }

    public function setDescription(string $description): static
    {
        return $this->setData(self::DESCRIPTION, $description);
    }

    public function getShippingProvider(): string
    {
        return (string) ($this->_data[self::SHIPPING_PROVIDER] ?? '');
    }

    public function setShippingProvider(string $shippingProvider): static
    {
        return $this->setData(self::SHIPPING_PROVIDER, $shippingProvider);
    }
}