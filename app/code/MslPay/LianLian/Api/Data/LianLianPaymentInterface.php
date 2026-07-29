<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api\Data;

/**
 * 连连支付请求数据接口
 *
 * 定义连连 API-支付 接口的请求参数常量，
 * 聚合客户、订单、支付数据等子接口，
 * 并提供 getter/setter 方法签名确保 Model 实现时类型安全。
 * @api
 */
interface LianLianPaymentInterface
{
    /** @var string 商户号 */
    public const MERCHANT_ID = 'merchant_id';
    /** @var string 站点号 */
    public const SUB_MERCHANT_ID = 'sub_merchant_id';
    /** @var string 商户交易ID */
    public const MERCHANT_TRANSACTION_ID = 'merchant_transaction_id';
    /** @var string 支付方式 */
    public const PAYMENT_METHOD = 'payment_method';
    /** @var string 商户主体国家 */
    public const COUNTRY = 'country';
    /** @var string 异步通知地址 */
    public const NOTIFICATION_URL = 'notification_url';
    /** @var string 同步跳转地址 */
    public const REDIRECT_URL = 'redirect_url';
    /** @var string 取消支付跳转地址 */
    public const CANCEL_URL = 'cancel_url';
    /** @var string 附加信息 */
    public const ADDITIONAL_INFO = 'additional_info';

    /** @var string 客户信息（嵌套对象） */
    public const CUSTOMER = 'customer';
    /** @var string 商户订单信息（嵌套对象） */
    public const MERCHANT_ORDER = 'merchant_order';
    /** @var string 支付信息（嵌套对象） */
    public const PAYMENT_DATA = 'payment_data';
    /** @var string 终端信息（嵌套对象） */
    public const TERMINAL_DATA = 'terminal_data';

    /**
     * 获取商户ID
     */
    public function getMerchantId(): string;

    /**
     * 设置商户ID
     */
    public function setMerchantId(string $merchantId): static;

    /**
     * 获取子商户ID
     */
    public function getSubMerchantId(): string;

    /**
     * 设置子商户ID
     */
    public function setSubMerchantId(string $subMerchantId): static;

    /**
     * 获取商户交易号
     */
    public function getMerchantTransactionId(): string;

    /**
     * 设置商户交易号
     */
    public function setMerchantTransactionId(string $merchantTransactionId): static;

    /**
     * 获取支付方式
     */
    public function getPaymentMethod(): string;

    /**
     * 设置支付方式
     */
    public function setPaymentMethod(string $paymentMethod): static;

    /**
     * 获取国家代码
     */
    public function getCountry(): string;

    /**
     * 设置国家代码
     */
    public function setCountry(string $country): static;

    /**
     * 获取异步通知URL
     */
    public function getNotificationUrl(): string;

    /**
     * 设置异步通知URL
     */
    public function setNotificationUrl(string $notificationUrl): static;

    /**
     * 获取同步跳转URL
     */
    public function getRedirectUrl(): string;

    /**
     * 设置同步跳转URL
     */
    public function setRedirectUrl(string $redirectUrl): static;

    /**
     * 获取取消支付URL
     */
    public function getCancelUrl(): string;

    /**
     * 设置取消支付URL
     */
    public function setCancelUrl(string $cancelUrl): static;

    /**
     * 获取附加信息
     */
    public function getAdditionalInfo(): string;

    /**
     * 设置附加信息
     */
    public function setAdditionalInfo(string $additionalInfo): static;

    /**
     * 获取客户信息
     */
    public function getCustomer(): ?CustomerInterface;

    /**
     * 设置客户信息
     */
    public function setCustomer(CustomerInterface $customer): static;

    /**
     * 获取商户订单信息
     */
    public function getMerchantOrder(): ?MerchantOrderInterface;

    /**
     * 设置商户订单信息
     */
    public function setMerchantOrder(MerchantOrderInterface $merchantOrder): static;

    /**
     * 获取支付数据
     */
    public function getPaymentData(): ?PaymentDataInterface;

    /**
     * 设置支付数据
     */
    public function setPaymentData(PaymentDataInterface $paymentData): static;
}
