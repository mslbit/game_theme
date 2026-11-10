<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductSales extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('folix_product_sales', 'entity_id');
    }

    /**
     * Upsert by product_id (insert or update)
     *
     * @param int $productId
     * @param int $volume
     * @return void
     */
    public function upsertByProductId(int $productId, int $volume): void
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();

        $select = $connection->select()
            ->from($table, ['entity_id'])
            ->where('product_id = ?', $productId);

        $entityId = $connection->fetchOne($select);

        $data = [
            'product_id' => $productId,
            'display_volume' => $volume,
        ];

        if ($entityId) {
            $connection->update($table, $data, ['entity_id = ?' => $entityId]);
        } else {
            $connection->insert($table, $data);
        }
    }
}
