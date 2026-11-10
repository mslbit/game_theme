<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Product Sales Volume Model
 *
 * @method int getProductId()
 * @method $this setProductId(int $productId)
 * @method int getDisplayVolume()
 * @method $this setDisplayVolume(int $displayVolume)
 */
class ProductSales extends AbstractModel
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\ProductSales::class);
    }

    /**
     * Load by product ID
     *
     * @param int $productId
     * @return $this
     */
    public function loadByProductId(int $productId): self
    {
        $this->getResource()->load($this, $productId, 'product_id');
        return $this;
    }

    /**
     * Increment display volume
     *
     * @param int $amount
     * @return $this
     */
    public function incrementVolume(int $amount = 1): self
    {
        $currentVolume = (int) $this->getDisplayVolume();
        $this->setDisplayVolume($currentVolume + $amount);
        return $this;
    }

    /**
     * Format volume for display (e.g., 970700 → "970.7k+")
     *
     * @return string
     */
    public function getFormattedVolume(): string
    {
        $volume = (int) $this->getDisplayVolume();

        if ($volume <= 0) {
            return '';
        }

        if ($volume >= 1000000) {
            return rtrim(rtrim(sprintf('%.1f', $volume / 1000000), '0'), '.') . 'M+';
        }

        if ($volume >= 1000) {
            return rtrim(rtrim(sprintf('%.1f', $volume / 1000), '0'), '.') . 'k+';
        }

        return (string) $volume;
    }
}
