<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\CustomerInterface;
use MslPay\LianLian\Api\Data\LianLianPaymentInterface;
use MslPay\LianLian\Api\Data\MerchantOrderInterface;
use MslPay\LianLian\Api\Data\PaymentDataInterface;

/**
 * 连连支付请求数据模型
 *
 * 聚合所有子对象，提供 toArray() 递归输出为 API 请求体。
 * Builder 创建此对象并设置必填项，toArray() 自动展平 DataObject 层级。
 */
class LianLianPayment extends DataObject implements LianLianPaymentInterface
{
    public function getMerchantId(): string
    {
        return (string) ($this->_data[self::MERCHANT_ID] ?? '');
    }

    public function setMerchantId(string $merchantId): static
    {
        return $this->setData(self::MERCHANT_ID, $merchantId);
    }

    public function getSubMerchantId(): string
    {
        return (string) ($this->_data[self::SUB_MERCHANT_ID] ?? '');
    }

    public function setSubMerchantId(string $subMerchantId): static
    {
        return $this->setData(self::SUB_MERCHANT_ID, $subMerchantId);
    }

    public function getMerchantTransactionId(): string
    {
        return (string) ($this->_data[self::MERCHANT_TRANSACTION_ID] ?? '');
    }

    public function setMerchantTransactionId(string $merchantTransactionId): static
    {
        return $this->setData(self::MERCHANT_TRANSACTION_ID, $merchantTransactionId);
    }

    public function getPaymentMethod(): string
    {
        return (string) ($this->_data[self::PAYMENT_METHOD] ?? '');
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        return $this->setData(self::PAYMENT_METHOD, $paymentMethod);
    }

    public function getCountry(): string
    {
        return (string) ($this->_data[self::COUNTRY] ?? '');
    }

    public function setCountry(string $country): static
    {
        return $this->setData(self::COUNTRY, $country);
    }

    public function getNotificationUrl(): string
    {
        return (string) ($this->_data[self::NOTIFICATION_URL] ?? '');
    }

    public function setNotificationUrl(string $notificationUrl): static
    {
        return $this->setData(self::NOTIFICATION_URL, $notificationUrl);
    }

    public function getRedirectUrl(): string
    {
        return (string) ($this->_data[self::REDIRECT_URL] ?? '');
    }

    public function setRedirectUrl(string $redirectUrl): static
    {
        return $this->setData(self::REDIRECT_URL, $redirectUrl);
    }

    public function getCancelUrl(): string
    {
        return (string) ($this->_data[self::CANCEL_URL] ?? '');
    }

    public function setCancelUrl(string $cancelUrl): static
    {
        return $this->setData(self::CANCEL_URL, $cancelUrl);
    }

    public function getAdditionalInfo(): string
    {
        return (string) ($this->_data[self::ADDITIONAL_INFO] ?? '');
    }

    public function setAdditionalInfo(string $additionalInfo): static
    {
        return $this->setData(self::ADDITIONAL_INFO, $additionalInfo);
    }

    public function getCustomer(): ?CustomerInterface
    {
        $customer = $this->_data[self::CUSTOMER] ?? null;
        return $customer instanceof CustomerInterface ? $customer : null;
    }

    public function setCustomer(CustomerInterface $customer): static
    {
        return $this->setData(self::CUSTOMER, $customer);
    }

    public function getMerchantOrder(): ?MerchantOrderInterface
    {
        $order = $this->_data[self::MERCHANT_ORDER] ?? null;
        return $order instanceof MerchantOrderInterface ? $order : null;
    }

    public function setMerchantOrder(MerchantOrderInterface $merchantOrder): static
    {
        return $this->setData(self::MERCHANT_ORDER, $merchantOrder);
    }

    public function getPaymentData(): ?PaymentDataInterface
    {
        $paymentData = $this->_data[self::PAYMENT_DATA] ?? null;
        return $paymentData instanceof PaymentDataInterface ? $paymentData : null;
    }

    public function setPaymentData(PaymentDataInterface $paymentData): static
    {
        return $this->setData(self::PAYMENT_DATA, $paymentData);
    }

    /**
     * 递归将 DataObject 层级展平为纯数组
     *
     * 用于 TransferFactory 序列化为 JSON 请求体。
     * 自动跳过空值字段，避免传 null 给连连 API。
     */
    public function toArray(array $keys = []): array
    {
        return $this->flattenDataObject($this);
    }

    /**
     * 递归展平 DataObject
     */
    private function flattenDataObject(DataObject $obj): array
    {
        $result = [];
        foreach ($obj->getData() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof DataObject) {
                $flattened = $this->flattenDataObject($value);
                if (!empty($flattened)) {
                    $result[$key] = $flattened;
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->flattenArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 展平数组（处理 Product[] 等 DataObject 数组）
     */
    private function flattenArray(array $arr): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if ($value instanceof DataObject) {
                $flattened = $this->flattenDataObject($value);
                if (!empty($flattened)) {
                    $result[$key] = $flattened;
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->flattenArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}