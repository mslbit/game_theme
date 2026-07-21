<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;

/**
 * Oceanpayment 配送地址构建器
 *
 * 构建配送地址相关的 API 请求参数：
 * - ship_firstName, ship_lastName: 收件人姓名
 * - ship_email: 收件人邮箱
 * - ship_phone: 收件人电话
 * - ship_country: 收件人国家
 * - ship_state: 收件人省/州
 * - ship_city: 收件人城市
 * - ship_addr: 收件人街道地址
 * - ship_zip: 收件人邮编
 *
 * 当订单无配送地址（如虚拟商品）时，从账单地址复制
 */
class AddressBuilder implements BuilderInterface
{
    /**
     * 构建配送地址请求参数
     *
     * 优先使用配送地址，若不存在则回退到账单地址
     *
     * @param array $buildSubject 构建参数，包含 payment 数据对象
     * @return array 配送地址相关 API 参数
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->readPayment($buildSubject);
        $order = $paymentDO->getOrder();

        /* 优先使用配送地址，虚拟商品订单无配送地址时回退到账单地址 */
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $address = $shippingAddress ?? $billingAddress;

        if ($address === null) {
            /* 极端情况：无任何地址信息，返回空值 */
            return $this->buildEmptyAddress();
        }

        return [
            /* 收件人姓名 */
            'ship_firstName' => (string) $address->getFirstname(),
            'ship_lastName'  => (string) $address->getLastname(),

            /* 收件人邮箱：配送地址可能无邮箱，回退到订单客户邮箱 */
            'ship_email'     => (string) ($address->getEmail() ?: $order->getCustomerEmail() ?? ''),

            /* 收件人电话 */
            'ship_phone'     => (string) $address->getTelephone(),

            /* 收件人国家 */
            'ship_country'   => (string) $address->getCountryId(),

            /* 收件人省/州 */
            'ship_state'     => (string) $address->getRegionCode(),

            /* 收件人城市 */
            'ship_city'      => (string) $address->getCity(),

            /* 收件人街道地址：取第一行 */
            'ship_addr'      => $this->getStreetLine($address),

            /* 收件人邮编 */
            'ship_zip'       => (string) $address->getPostcode(),
        ];
    }

    /**
     * 构建空的配送地址参数
     *
     * 当订单既无配送地址也无账单地址时使用
     *
     * @return array 空值配送地址参数
     */
    private function buildEmptyAddress(): array
    {
        return [
            'ship_firstName' => '',
            'ship_lastName'  => '',
            'ship_email'     => '',
            'ship_phone'     => '',
            'ship_country'   => '',
            'ship_state'     => '',
            'ship_city'      => '',
            'ship_addr'      => '',
            'ship_zip'       => '',
        ];
    }

    /**
     * 获取地址的街道首行
     *
     * @param \Magento\Payment\Gateway\Data\AddressAdapterInterface $address 地址适配器
     * @return string 街道地址首行
     */
    private function getStreetLine($address): string
    {
        $street = $address->getStreetLine1();
        return is_string($street) ? $street : '';
    }

    /**
     * 从构建参数中读取 PaymentDataObject
     *
     * @param array $buildSubject 构建参数
     * @return PaymentDataObjectInterface
     * @throws \InvalidArgumentException
     */
    private function readPayment(array $buildSubject): PaymentDataObjectInterface
    {
        if (!isset($buildSubject['payment']) || !$buildSubject['payment'] instanceof PaymentDataObjectInterface) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        return $buildSubject['payment'];
    }
}