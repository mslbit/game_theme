<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductSales\Console\Command;

use Folix\ProductSales\Model\ResourceModel\ProductSales as ProductSalesResource;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 批量导入产品成交量
 *
 * 用法：bin/magento folix:sales:import <csv_file>
 *
 * CSV 格式（首行为表头）：
 *   sku,volume
 *   PUBG-UC-60,970700
 *   HOK-TOKEN-10,50000
 *
 * 导入逻辑：
 * - 通过 SKU 查找产品 ID
 * - 直接覆盖原数据（upsert）
 */
class SalesImportCommand extends Command
{
    /**
     * @var ProductSalesResource
     */
    private $resource;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var State
     */
    private $state;

    /**
     * @param ProductSalesResource $resource
     * @param ProductRepositoryInterface $productRepository
     * @param State $state
     */
    public function __construct(
        ProductSalesResource $resource,
        ProductRepositoryInterface $productRepository,
        State $state
    ) {
        $this->resource = $resource;
        $this->productRepository = $productRepository;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('folix:sales:import')
            ->setDescription('Batch import product sales volume from CSV file')
            ->addArgument('csv_file', InputArgument::REQUIRED, 'Path to CSV file');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (LocalizedException $e) {
            // Area code already set
        }

        $csvFile = $input->getArgument('csv_file');

        if (!file_exists($csvFile)) {
            $output->writeln("<error>File not found: {$csvFile}</error>");
            return Command::FAILURE;
        }

        $handle = fopen($csvFile, 'r');
        if ($handle === false) {
            $output->writeln("<error>Cannot open file: {$csvFile}</error>");
            return Command::FAILURE;
        }

        // 读取表头（PHP 8.4+ 要求明确指定 escape 参数）
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            $output->writeln('<error>Empty CSV file</error>');
            fclose($handle);
            return Command::FAILURE;
        }

        // 验证表头
        $header = array_map('trim', $header);
        $requiredColumns = ['sku', 'volume'];
        $missingColumns = array_diff($requiredColumns, $header);
        if (!empty($missingColumns)) {
            $output->writeln('<error>Missing required columns: ' . implode(', ', $missingColumns) . '</error>');
            $output->writeln('<info>Required: sku, volume</info>');
            fclose($handle);
            return Command::FAILURE;
        }

        $skuIndex = array_search('sku', $header);
        $volumeIndex = array_search('volume', $header);

        $successCount = 0;
        $errorCount = 0;
        $skipCount = 0;
        $rowNum = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rowNum++;

            if (count($row) <= max($skuIndex, $volumeIndex)) {
                $output->writeln("<comment>Row {$rowNum}: insufficient columns, skipped</comment>");
                $skipCount++;
                continue;
            }

            $sku = trim($row[$skuIndex]);
            $volume = (int) trim($row[$volumeIndex]);

            if ($sku === '') {
                $skipCount++;
                continue;
            }

            try {
                $product = $this->productRepository->get($sku);
                $productId = (int) $product->getId();

                $this->resource->upsertByProductId($productId, $volume);
                $successCount++;
                $output->writeln("<info>Row {$rowNum}: {$sku} (ID: {$productId}) → volume={$volume}</info>");
            } catch (\Exception $e) {
                $errorCount++;
                $output->writeln("<error>Row {$rowNum}: {$sku} → {$e->getMessage()}</error>");
            }
        }

        fclose($handle);

        $output->writeln('');
        $output->writeln("<info>Import complete: {$successCount} success, {$errorCount} errors, {$skipCount} skipped</info>");

        return Command::SUCCESS;
    }
}
