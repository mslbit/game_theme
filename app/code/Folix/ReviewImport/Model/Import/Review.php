<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ReviewImport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\Review\Model\ResourceModel\Rating\Option as RatingOptionResource;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Review Import Entity Model
 *
 * 导入评论 + Price 评分（1-5星）
 *
 * CSV format:
 * sku,store_id,customer_name,title,detail,status,rating
 */
class Review extends AbstractEntity
{
    private const ENTITY_TYPE_CODE = 'review';

    /**
     * Master attribute for row grouping
     */
    protected $masterAttributeCode = 'sku';

    private const PRICE_RATING_CODE = 'Price';

    /**
     * review entity_id = product
     */
    private const REVIEW_ENTITY_ID = 1;

    /**
     * status_id: 1=Approved, 2=Pending
     */
    private const STATUS_APPROVED = 1;
    private const STATUS_PENDING = 2;

    /**
     * @var string[]
     */
    protected $_permanentAttributes = [
        'sku',
        'store_id',
        'customer_name',
        'title',
        'detail',
        'status',
        'rating',
    ];

    /**
     * @var string[]
     */
    protected $validColumnNames = [
        'sku',
        'store_id',
        'customer_name',
        'title',
        'detail',
        'status',
        'rating',
    ];

    /**
     * @var bool
     */
    protected $needColumnCheck = true;

    /**
     * Validated rows cache
     *
     * @var array
     */
    protected $_validatedRows = [];

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var RatingOptionResource
     */
    private RatingOptionResource $ratingOptionResource;

    /**
     * Cached SKU => product_id mapping
     *
     * @var array|null
     */
    private ?array $skuToProductId = null;

    /**
     * Price 维度 option_id 映射: value(1-5) → option_id
     *
     * @var array|null
     */
    private ?array $priceOptions = null;

    /**
     * Price 维度的动态 rating_id
     *
     * @var int|null
     */
    private ?int $priceRatingId = null;

    public function __construct(
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\ImportExport\Model\ImportFactory $importFactory,
        \Magento\ImportExport\Model\ResourceModel\Helper $resourceHelper,
        ResourceConnection $resource,
        ProcessingErrorAggregatorInterface $errorAggregator,
        StoreManagerInterface $storeManager,
        RatingOptionResource $ratingOptionResource,
        array $data = []
    ) {
        parent::__construct($string, $scopeConfig, $importFactory, $resourceHelper, $resource, $errorAggregator, $data);
        $this->storeManager = $storeManager;
        $this->ratingOptionResource = $ratingOptionResource;
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
    public function validateRow(array $rowData, $rowNumber): bool
    {
        // Validate SKU
        if (empty($rowData['sku'])) {
            $this->addRowError('SKU is required', $rowNumber, 'sku');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        $sku = trim($rowData['sku']);
        $productId = $this->getProductIdBySku($sku);
        if (!$productId) {
            $this->addRowError(sprintf('Product with SKU "%s" not found', $sku), $rowNumber, 'sku');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        // Validate required fields
        if (empty($rowData['customer_name'])) {
            $this->addRowError('Customer name is required', $rowNumber, 'customer_name');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        if (empty($rowData['title'])) {
            $this->addRowError('Title is required', $rowNumber, 'title');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        if (empty($rowData['detail'])) {
            $this->addRowError('Detail is required', $rowNumber, 'detail');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        // Validate status (optional, default Approved)
        $status = strtolower(trim($rowData['status'] ?? 'approved'));
        if (!in_array($status, ['approved', 'pending'])) {
            $this->addRowError('Status must be "Approved" or "Pending"', $rowNumber, 'status');
            $this->_validatedRows[$rowNumber] = false;
            return false;
        }

        // Validate store_id (optional, must be >= 0 if provided)
        if (isset($rowData['store_id']) && $rowData['store_id'] !== '') {
            $storeId = (int) $rowData['store_id'];
            if ($storeId < 0) {
                $this->addRowError('store_id must be a non-negative integer', $rowNumber, 'store_id');
                $this->_validatedRows[$rowNumber] = false;
                return false;
            }
        }

        // Validate rating (optional, 1-5)
        if (!empty($rowData['rating'])) {
            $rating = (int) $rowData['rating'];
            if ($rating < 1 || $rating > 5) {
                $this->addRowError('Rating must be between 1 and 5', $rowNumber, 'rating');
                $this->_validatedRows[$rowNumber] = false;
                return false;
            }
        }

        $this->_validatedRows[$rowNumber] = true;
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function _importData(): bool
    {
        $connection = $this->_connection;
        $defaultStoreId = $this->getStoreId();
        $processedProductIds = [];

        $connection->beginTransaction();
        try {
            // Read validated bunches saved during the validation phase.
            // The source file is NOT available in the import request, so we must not use getSource().
            while ($bunch = $this->_dataSourceModel->getNextUniqueBunch($this->getIds())) {
                foreach ($bunch as $rowNum => $rowData) {
                    $storeId = (int) ($rowData['store_id'] ?? $defaultStoreId);
                    $productId = $this->processRow($rowData, $storeId);
                    if ($productId) {
                        $processedProductIds[$productId] = true;
                    }
                    $this->countItemsCreated++;
                }
            }

            $priceRatingId = $this->getPriceRatingId();
            if ($priceRatingId) {
                foreach (array_keys($processedProductIds) as $productId) {
                    $this->ratingOptionResource->aggregateEntityByRatingId($priceRatingId, $productId);
                }
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new LocalizedException(__('Import failed: %1', $e->getMessage()), $e);
        }

        return true;
    }

    /**
     * Process a single review row
     *
     * @param array $rowData
     * @param int $storeId
     * @return int|null product_id if processed
     */
    private function processRow(array $rowData, int $storeId): ?int
    {
        $connection = $this->_connection;

        $sku = trim($rowData['sku']);
        $productId = $this->getProductIdBySku($sku);
        if (!$productId) {
            return null;
        }

        $storeId = (int) ($rowData['store_id'] ?? $this->getStoreId());

        // Determine status_id
        $status = strtolower(trim($rowData['status'] ?? 'approved'));
        $statusId = ($status === 'pending') ? self::STATUS_PENDING : self::STATUS_APPROVED;

        // 1. Insert review
        $reviewData = [
            'created_at' => date('Y-m-d H:i:s'),
            'entity_id' => self::REVIEW_ENTITY_ID,
            'entity_pk_value' => $productId,
            'status_id' => $statusId,
        ];
        $connection->insert($this->getTable('review'), $reviewData);
        $reviewId = (int) $connection->lastInsertId($this->getTable('review'));

        // 2. Insert review_detail
        $connection->insert($this->getTable('review_detail'), [
            'review_id' => $reviewId,
            'store_id' => $storeId,
            'title' => trim($rowData['title']),
            'detail' => trim($rowData['detail']),
            'nickname' => trim($rowData['customer_name']),
        ]);

        // 3. Insert review_store (global + specific store)
        $connection->insert($this->getTable('review_store'), [
            'review_id' => $reviewId,
            'store_id' => 0,
        ]);
        if ($storeId != 0) {
            $connection->insert($this->getTable('review_store'), [
                'review_id' => $reviewId,
                'store_id' => $storeId,
            ]);
        }

        // 4. Insert rating_option_vote (Price only, rating_id=3)
        $rating = (int)($rowData['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $optionId = $this->getPriceOptionId($rating);
            if ($optionId) {
                $connection->insert($this->getTable('rating_option_vote'), [
                    'option_id' => $optionId,
                    'remote_ip' => '',
                    'remote_ip_long' => 0,
                    'entity_pk_value' => $productId,
                    'rating_id' => $this->getPriceRatingId(),
                    'review_id' => $reviewId,
                    'percent' => $rating * 20,
                    'value' => $rating,
                ]);
            }
        }

        // 5. Update review_entity_summary
        $this->updateReviewEntitySummary($productId, $storeId);

        return $productId;
    }

    /**
     * Update review_entity_summary table
     *
     * @param int $productId
     * @param int $storeId
     * @return void
     */
    private function updateReviewEntitySummary(int $productId, int $storeId): void
    {
        $connection = $this->_connection;
        $summaryTable = $this->getTable('review_entity_summary');

        $select = $connection->select()
            ->from($summaryTable)
            ->where('entity_pk_value = ?', $productId)
            ->where('entity_type = ?', self::REVIEW_ENTITY_ID)
            ->where('store_id = ?', $storeId);

        $summary = $connection->fetchRow($select);

        // Calculate rating_summary from rating_option_vote
        $ratingSummary = $this->calculateRatingSummary($productId, $storeId);

        if ($summary) {
            $connection->update(
                $summaryTable,
                [
                    'reviews_count' => ((int) $summary['reviews_count']) + 1,
                    'rating_summary' => $ratingSummary,
                ],
                [
                    'entity_pk_value = ?' => $productId,
                    'entity_type = ?' => self::REVIEW_ENTITY_ID,
                    'store_id = ?' => $storeId,
                ]
            );
        } else {
            $connection->insert($summaryTable, [
                'entity_pk_value' => $productId,
                'entity_type' => self::REVIEW_ENTITY_ID,
                'reviews_count' => 1,
                'rating_summary' => $ratingSummary,
                'store_id' => $storeId,
            ]);
        }
    }

    /**
     * Calculate rating summary from rating_option_vote (Price dimension)
     *
     * @param int $productId
     * @param int $storeId
     * @return int percent (0-100)
     */
    private function calculateRatingSummary(int $productId, int $storeId): int
    {
        $connection = $this->_connection;
        $voteTable = $this->getTable('rating_option_vote');
        $reviewTable = $this->getTable('review');

        $select = $connection->select()
            ->from(['v' => $voteTable], ['avg_percent' => new \Zend_Db_Expr('AVG(v.percent)')])
            ->join(['r' => $reviewTable], 'v.review_id = r.review_id', [])
            ->where('v.entity_pk_value = ?', $productId)
            ->where('v.rating_id = ?', $this->getPriceRatingId())
            ->where('r.status_id = ?', self::STATUS_APPROVED);

        $result = $connection->fetchOne($select);

        return $result ? (int) round((float) $result) : 0;
    }

    /**
     * Get Price option_id for a rating value (1-5)
     *
     * @param int $rating
     * @return int|null
     */
    private function getPriceOptionId(int $rating): ?int
    {
        if ($this->priceOptions === null) {
            $this->loadPriceOptions();
        }

        return $this->priceOptions[$rating] ?? null;
    }

    /**
     * Get dynamic Price rating_id by querying rating table
     *
     * @return int|null
     */
    private function getPriceRatingId(): ?int
    {
        if ($this->priceRatingId !== null) {
            return $this->priceRatingId;
        }

        $connection = $this->_connection;
        $select = $connection->select()
            ->from($this->getTable('rating'), ['rating_id'])
            ->where('rating_code = ?', self::PRICE_RATING_CODE)
            ->limit(1);

        $result = $connection->fetchOne($select);
        $this->priceRatingId = $result ? (int) $result : null;

        return $this->priceRatingId;
    }

    /**
     * Load Price rating options from rating_option table
     *
     * @return void
     */
    private function loadPriceOptions(): void
    {
        $connection = $this->_connection;
        $priceRatingId = $this->getPriceRatingId();

        if (!$priceRatingId) {
            $this->priceOptions = [];
            return;
        }

        $select = $connection->select()
            ->from($this->getTable('rating_option'), ['option_id', 'value'])
            ->where('rating_id = ?', $priceRatingId);

        $this->priceOptions = [];
        foreach ($connection->fetchAll($select) as $row) {
            $this->priceOptions[(int) $row['value']] = (int) $row['option_id'];
        }
    }

    /**
     * Get product ID by SKU (cached)
     *
     * @param string $sku
     * @return int|null
     */
    private function getProductIdBySku(string $sku): ?int
    {
        if ($this->skuToProductId === null) {
            $this->loadSkuToProductIdMap();
        }

        return $this->skuToProductId[$sku] ?? null;
    }

    /**
     * Load all SKU => product_id mappings
     *
     * @return void
     */
    private function loadSkuToProductIdMap(): void
    {
        $connection = $this->_connection;

        $select = $connection->select()
            ->from($this->getTable('catalog_product_entity'), ['sku', 'entity_id']);

        $this->skuToProductId = [];
        foreach ($connection->fetchAll($select) as $row) {
            $this->skuToProductId[$row['sku']] = (int) $row['entity_id'];
        }
    }

    /**
     * Get current store ID
     *
     * @return int
     */
    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Get table name with prefix
     *
     * @param string $tableName
     * @return string
     */
    private function getTable(string $tableName): string
    {
        return $this->_connection->getTableName($tableName);
    }
}
