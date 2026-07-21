<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Service;

use GeoIp2\Database\Reader;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment GeoIP 查询服务
 *
 * 通过 MaxMind GeoLite2 数据库根据 IP 地址查询国家和城市信息。
 * 优先使用 GeoLite2-City.mmdb（含国家+城市），
 * 回退到 GeoLite2-Country.mmdb（仅国家），
 * 均不可用时返回空值。
 */
class GeoIpService
{
    /**
     * GeoLite2-City 数据库相对路径（优先使用，含城市信息）
     */
    private const CITY_DB_PATH = 'geoip/GeoLite2-City.mmdb';

    /**
     * GeoLite2-Country 数据库相对路径（回退使用，仅含国家）
     */
    private const COUNTRY_DB_PATH = 'geoip/GeoLite2-Country.mmdb';

    /**
     * @var DirectoryList 目录列表接口
     */
    private DirectoryList $directoryList;

    /**
     * @var LoggerInterface 日志接口
     */
    private LoggerInterface $logger;

    /**
     * @var Reader|null 缓存的 City Reader 实例
     */
    private ?Reader $cityReader = null;

    /**
     * @var Reader|null 缓存的 Country Reader 实例
     */
    private ?Reader $countryReader = null;

    /**
     * @var bool 是否已尝试初始化 City Reader
     */
    private bool $cityReaderInitialized = false;

    /**
     * @var bool 是否已尝试初始化 Country Reader
     */
    private bool $countryReaderInitialized = false;

    /**
     * @param DirectoryList $directoryList
     * @param LoggerInterface $logger
     */
    public function __construct(
        DirectoryList $directoryList,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->logger = $logger;
    }

    /**
     * 根据 IP 地址查询国家代码
     *
     * @param string $ipAddress 客户端 IP 地址
     * @return string|null ISO 国家代码（如 US, CN），查询失败返回 null
     */
    public function getCountryCode(string $ipAddress): ?string
    {
        if (!$this->validateIp($ipAddress)) {
            return null;
        }

        /* 优先从 City 库查询（数据更丰富） */
        $cityReader = $this->getCityReader();
        if ($cityReader) {
            try {
                $record = $cityReader->city($ipAddress);
                return $record->country->isoCode ?: null;
            } catch (\Exception $e) {
                $this->logger->debug('GeoIP city lookup failed for country: ' . $e->getMessage());
            }
        }

        /* 回退到 Country 库 */
        $countryReader = $this->getCountryReader();
        if ($countryReader) {
            try {
                $record = $countryReader->country($ipAddress);
                return $record->country->isoCode ?: null;
            } catch (\Exception $e) {
                $this->logger->debug('GeoIP country lookup failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * 根据 IP 地址查询城市名称
     *
     * 仅 GeoLite2-City.mmdb 包含城市信息，
     * Country 库无法提供城市数据。
     *
     * @param string $ipAddress 客户端 IP 地址
     * @return string|null 城市名称，查询失败返回 null
     */
    public function getCity(string $ipAddress): ?string
    {
        if (!$this->validateIp($ipAddress)) {
            return null;
        }

        $cityReader = $this->getCityReader();
        if ($cityReader) {
            try {
                $record = $cityReader->city($ipAddress);
                return $record->city->name ?: null;
            } catch (\Exception $e) {
                $this->logger->debug('GeoIP city lookup failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * 根据 IP 地址查询省/州 ISO 代码
     *
     * 仅 GeoLite2-City.mmdb 包含省州信息。
     *
     * @param string $ipAddress 客户端 IP 地址
     * @return string|null 省/州 ISO 代码（如 CA, NY），查询失败返回 null
     */
    public function getRegionCode(string $ipAddress): ?string
    {
        if (!$this->validateIp($ipAddress)) {
            return null;
        }

        $cityReader = $this->getCityReader();
        if ($cityReader) {
            try {
                $record = $cityReader->city($ipAddress);
                /* mostSpecificSubdivision 提供最具体的行政区划（省/州） */
                return $record->mostSpecificSubdivision->isoCode ?: null;
            } catch (\Exception $e) {
                $this->logger->debug('GeoIP region lookup failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * 验证 IP 地址格式
     *
     * @param string $ipAddress
     * @return bool
     */
    private function validateIp(string $ipAddress): bool
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $this->logger->warning("Invalid IP address: {$ipAddress}");
            return false;
        }
        return true;
    }

    /**
     * 获取 GeoLite2-City Reader 实例（懒加载）
     *
     * @return Reader|null
     */
    private function getCityReader(): ?Reader
    {
        if ($this->cityReaderInitialized) {
            return $this->cityReader;
        }

        $this->cityReaderInitialized = true;

        try {
            $dbPath = $this->getVarPath(self::CITY_DB_PATH);
            if (file_exists($dbPath)) {
                $this->cityReader = new Reader($dbPath);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to init GeoIP City reader: ' . $e->getMessage());
        }

        return $this->cityReader;
    }

    /**
     * 获取 GeoLite2-Country Reader 实例（懒加载）
     *
     * @return Reader|null
     */
    private function getCountryReader(): ?Reader
    {
        if ($this->countryReaderInitialized) {
            return $this->countryReader;
        }

        $this->countryReaderInitialized = true;

        try {
            $dbPath = $this->getVarPath(self::COUNTRY_DB_PATH);
            if (file_exists($dbPath)) {
                $this->countryReader = new Reader($dbPath);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to init GeoIP Country reader: ' . $e->getMessage());
        }

        return $this->countryReader;
    }

    /**
     * 获取 var 目录下文件的绝对路径
     *
     * @param string $relativePath 相对于 var 目录的路径
     * @return string 绝对路径
     */
    private function getVarPath(string $relativePath): string
    {
        $varPath = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        return $varPath . '/' . $relativePath;
    }
}