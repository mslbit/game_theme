<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付地址数据接口
 *
 * 定义 address 对象的字段常量，复用于 billing 和 shipping 地址，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface AddressInterface
{
    /** @var string 地址行1 */
    public const LINE1 = 'line1';
    /** @var string 地址行2 */
    public const LINE2 = 'line2';
    /** @var string 城市 */
    public const CITY = 'city';
    /** @var string 州/省 */
    public const STATE = 'state';
    /** @var string 国家代码（ISO 3166-1 alpha-2） */
    public const COUNTRY = 'country';
    /** @var string 邮编 */
    public const POSTAL_CODE = 'postal_code';
    /** @var string 区/县 */
    public const DISTRICT = 'district';

    /**
     * 获取地址行1
     */
    public function getLine1(): string;

    /**
     * 设置地址行1
     */
    public function setLine1(string $line1): static;

    /**
     * 获取地址行2
     */
    public function getLine2(): string;

    /**
     * 设置地址行2
     */
    public function setLine2(string $line2): static;

    /**
     * 获取城市
     */
    public function getCity(): string;

    /**
     * 设置城市
     */
    public function setCity(string $city): static;

    /**
     * 获取州/省
     */
    public function getState(): string;

    /**
     * 设置州/省
     */
    public function setState(string $state): static;

    /**
     * 获取国家代码
     */
    public function getCountry(): string;

    /**
     * 设置国家代码
     */
    public function setCountry(string $country): static;

    /**
     * 获取邮政编码
     */
    public function getPostalCode(): string;

    /**
     * 设置邮政编码
     */
    public function setPostalCode(string $postalCode): static;

    /**
     * 获取区/县
     */
    public function getDistrict(): string;

    /**
     * 设置区/县
     */
    public function setDistrict(string $district): static;
}
