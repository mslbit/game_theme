<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付客户信息数据接口
 *
 * 定义 customer 对象的字段常量，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface CustomerInterface
{
    /** @var string 客户类型 I-个人 C-公司 */
    public const CUSTOMER_TYPE = 'customer_type';
    /** @var string 名 */
    public const FIRST_NAME = 'first_name';
    /** @var string 姓 */
    public const LAST_NAME = 'last_name';
    /** @var string 全名 */
    public const FULL_NAME = 'full_name';
    /** @var string 邮箱 */
    public const EMAIL = 'email';
    /** @var string 手机号 */
    public const PHONE = 'phone';
    /** @var string 性别 */
    public const GENDER = 'gender';
    /** @var string 公司名称（customer_type=C 时必传） */
    public const COMPANY = 'company';
    /** @var string 地址信息（嵌套对象） */
    public const ADDRESS = 'address';

    /**
     * 获取客户类型
     */
    public function getCustomerType(): string;

    /**
     * 设置客户类型
     */
    public function setCustomerType(string $customerType): static;

    /**
     * 获取客户名
     */
    public function getFirstName(): string;

    /**
     * 设置客户名
     */
    public function setFirstName(string $firstName): static;

    /**
     * 获取客户姓
     */
    public function getLastName(): string;

    /**
     * 设置客户姓
     */
    public function setLastName(string $lastName): static;

    /**
     * 获取客户全名
     */
    public function getFullName(): string;

    /**
     * 设置客户全名
     */
    public function setFullName(string $fullName): static;

    /**
     * 获取客户邮箱
     */
    public function getEmail(): string;

    /**
     * 设置客户邮箱
     */
    public function setEmail(string $email): static;

    /**
     * 获取客户电话
     */
    public function getPhone(): string;

    /**
     * 设置客户电话
     */
    public function setPhone(string $phone): static;

    /**
     * 获取客户性别
     */
    public function getGender(): string;

    /**
     * 设置客户性别
     */
    public function setGender(string $gender): static;

    /**
     * 获取客户公司
     */
    public function getCompany(): string;

    /**
     * 设置客户公司
     */
    public function setCompany(string $company): static;

    /**
     * 获取客户地址
     */
    public function getAddress(): ?AddressInterface;

    /**
     * 设置客户地址
     */
    public function setAddress(AddressInterface $address): static;
}
