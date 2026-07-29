<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付收货人信息数据接口
 *
 * 定义 shipping 对象的字段常量，实物商品必传，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface ShippingInterface
{
    /** @var string 收货人全名 */
    public const NAME = 'name';
    /** @var string 收货人名 */
    public const FIRST_NAME = 'first_name';
    /** @var string 收货人姓 */
    public const LAST_NAME = 'last_name';
    /** @var string 收货人手机号 */
    public const PHONE = 'phone';
    /** @var string 收货地址（嵌套对象） */
    public const ADDRESS = 'address';
    /** @var string 预计送达周期（如 48h） */
    public const CYCLE = 'cycle';

    /**
     * 获取收货人全名
     */
    public function getName(): string;

    /**
     * 设置收货人全名
     */
    public function setName(string $name): static;

    /**
     * 获取收货人名
     */
    public function getFirstName(): string;

    /**
     * 设置收货人名
     */
    public function setFirstName(string $firstName): static;

    /**
     * 获取收货人姓
     */
    public function getLastName(): string;

    /**
     * 设置收货人姓
     */
    public function setLastName(string $lastName): static;

    /**
     * 获取收货人电话
     */
    public function getPhone(): string;

    /**
     * 设置收货人电话
     */
    public function setPhone(string $phone): static;

    /**
     * 获取收货地址
     */
    public function getAddress(): ?AddressInterface;

    /**
     * 设置收货地址
     */
    public function setAddress(AddressInterface $address): static;

    /**
     * 获取配送周期
     */
    public function getCycle(): string;

    /**
     * 设置配送周期
     */
    public function setCycle(string $cycle): static;
}
