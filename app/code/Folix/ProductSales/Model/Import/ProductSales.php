<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Model\Import;

use Folix\ProductSales\Model\ResourceModel\ProductSales as ProductSalesResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ImportExport\Model\ImportFactory;
use Magento\ImportExport\Model\ResourceModel\Helper as ResourceHelper;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\AbstractEntity;
use Magento\Store\Model\ScopeInterface;

class ProductSales extends AbstractEntity
{
    /**
     * Entity type code
     */
    private const ENTITY_TYPE_CODE = 'product_sales';

    /**
     * Master attribute for row grouping
     */
    protected $masterAttributeCode = 'sku';

    /**
     * Required columns in CSV
     */
    private const COLUMN_SKU = 'sku';
    private const COLUMN_VOLUME = 'volume';

    /**
     * @var string[]
     */
    protected $_permanentAttributes = [self::COLUMN_SKU, self::COLUMN_VOLUME];

    /**
     * @var bool
     */
    protected $needColumnCheck = true;

    /**
     * @var array
     */
    protected $validColumnNames = [self::COLUMN_SKU, self::COLUMN_VOLUME];

    /**
     * @var ProductSalesResource
     */
    private $productSalesResource;

    /**
     * @var AdapterInterface
     */
    private $connection;

    /**
     * @var string
     */
    private $productEntityTable;

    /**
     * @var array SKU => product_id cache
     */
    private $skuToProductIdMap = [];

    /**
     * @var array SKU existence cache [sku => bool]
     */
    private array $skuExistCache = [];

    /**
     * @var array row_num => validated result
     */
    protected $_validatedRows = [];

    public function __construct(
        StringUtils $string,
        ScopeConfigInterface $scopeConfig,
        ImportFactory $importFactory,
        ResourceHelper $resourceHelper,
        ResourceConnection $resource,
        ProcessingErrorAggregatorInterface $errorAggregator,
        ProductSalesResource $productSalesResource,
        array $data = []
    ) {
        parent::__construct(
            $string,
            $scopeConfig,
            $importFactory,
            $resourceHelper,
            $resource,
            $errorAggregator,
            $data
        );
        $this->productSalesResource = $productSalesResource;
        $this->connection = $resource->getConnection();
        $this->productEntityTable = $resource->getTableName('catalog_product_entity');
    }

    /**
     * @inheritDoc
     */
    public function getEntityTypeCode(): string
    {
        return self::ENTITY_TYPE_CODE;
    }

    /**
     * @inheritDoc
     */
    public function validateRow(array $rowData, $rowNum): bool
    {
        if (isset($this->_validatedRows[$rowNum])) {
            return $this->_validatedRows[$rowNum];
        }

        $valid = true;
        $sku = trim($rowData[self::COLUMN_SKU] ?? '');
        $volume = trim($rowData[self::COLUMN_VOLUME] ?? '');

        // Validate SKU
        if ($sku === '') {
            $this->addRowError('SKU cannot be empty', $rowNum, self::COLUMN_SKU);
            $valid = false;
        }

        // Validate volume
        if ($volume === '') {
            $this->addRowError('Volume cannot be empty', $rowNum, self::COLUMN_VOLUME);
            $valid = false;
        } elseif (!ctype_digit($volume) && !(is_numeric($volume) && (int)$volume == $volume && (int)$volume >= 0)) {
            $this->addRowError('Volume must be a non-negative integer', $rowNum, self::COLUMN_VOLUME);
            $valid = false;
        } elseif ((int)$volume < 0) {
            $this->addRowError('Volume must be a non-negative integer', $rowNum, self::COLUMN_VOLUME);
            $valid = false;
        }

        // Validate SKU exists (checked here so invalid rows are excluded during validation)
        if ($sku !== '' && !$this->productExists($sku)) {
            $this->addRowError(
                sprintf('Product with SKU "%s" not found', $sku),
                $rowNum,
                self::COLUMN_SKU
            );
            $valid = false;
        }

        $this->_validatedRows[$rowNum] = $valid;
        return $valid;
    }

    /**
     * Check whether a product with the given SKU exists (cached per request)
     *
     * @param string $sku
     * @return bool
     */
    private function productExists(string $sku): bool
    {
        if (!isset($this->skuExistCache[$sku])) {
            $select = $this->connection->select()
                ->from($this->productEntityTable, ['entity_id'])
                ->where('sku = ?', $sku)
                ->limit(1);
            $this->skuExistCache[$sku] = $this->connection->fetchOne($select) !== false;
        }
        return $this->skuExistCache[$sku];
    }

    /**
     * @inheritDoc
     */
    protected function _importData(): bool
    {
        // Read validated bunches saved during the validation phase.
        // The source file is NOT available in the import request, so we must not use getSource().
        while ($bunch = $this->_dataSourceModel->getNextUniqueBunch($this->getIds())) {
            // Batch resolve SKU => product_id for this bunch
            $skus = [];
            foreach ($bunch as $rowData) {
                $sku = trim($rowData[self::COLUMN_SKU] ?? '');
                if ($sku !== '') {
                    $skus[$sku] = true;
                }
            }
            $map = $this->getSkuToProductIdMap(array_keys($skus));

            foreach ($bunch as $rowNum => $rowData) {
                $sku = trim($rowData[self::COLUMN_SKU] ?? '');
                $volume = (int) trim($rowData[self::COLUMN_VOLUME] ?? '0');

                if (!isset($map[$sku])) {
                    $this->addRowError(
                        sprintf('Product with SKU "%s" not found', $sku),
                        $rowNum,
                        self::COLUMN_SKU,
                        null,
                        ProcessingError::ERROR_LEVEL_NOT_CRITICAL
                    );
                    continue;
                }

                try {
                    $this->productSalesResource->upsertByProductId((int) $map[$sku], $volume);
                    $this->countItemsUpdated++;
                } catch (\Exception $e) {
                    $this->addRowError(
                        sprintf('Error saving data for SKU "%s": %s', $sku, $e->getMessage()),
                        $rowNum,
                        self::COLUMN_SKU,
                        null,
                        ProcessingError::ERROR_LEVEL_NOT_CRITICAL
                    );
                }
            }
        }

        return true;
    }

    /**
     * Batch fetch SKU to product_id mapping
     *
     * @param string[] $skus
     * @return array [sku => product_id]
     */
    private function getSkuToProductIdMap(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $select = $this->connection->select()
            ->from($this->productEntityTable, ['entity_id', 'sku'])
            ->where('sku IN (?)', $skus);

        $result = $this->connection->fetchAll($select);
        $map = [];
        foreach ($result as $row) {
            $map[$row['sku']] = $row['entity_id'];
        }

        return $map;
    }
}
