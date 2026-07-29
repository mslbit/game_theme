<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Helper;

use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use MslPay\LianLian\Model\Data\Address;

/**
 * 地址构建助手
 *
 * 将 Magento AddressAdapter 转换为连连 Address DataObject。
 * 只设置有实际值的字段，不使用 N/A 等占位符。
 * 非必传字段为空时不设置，TransferFactory 展平时会自动跳过空值。
 * 必传字段由 RequiredFieldValidator 在发送前校验。
 */
class AddressBuilder
{
    /**
     * 从 Magento 地址适配器构建连连 Address DataObject
     *
     * 只设置有实际值的字段，空值字段不设置（TransferFactory 会跳过空字符串）
     *
     * @param AddressAdapterInterface $magentoAddress Magento 地址
     * @return Address 连连地址 DataObject
     */
    public function build(AddressAdapterInterface $magentoAddress): Address
    {
        $address = new Address();

        $line1 = implode(' ', array_filter([
            $magentoAddress->getStreetLine1(),
            $magentoAddress->getStreetLine2(),
        ]));
        if ($line1 !== '') {
            $address->setLine1($line1);
        }

        $countryId = $magentoAddress->getCountryId();
        if (!empty($countryId)) {
            $address->setCountry($countryId);
        }

        $city = $magentoAddress->getCity();
        if (!empty($city)) {
            $address->setCity($city);
        }

        $region = $magentoAddress->getRegionCode();
        if (!empty($region)) {
            $address->setState($region);
        }

        $postcode = $magentoAddress->getPostcode();
        if (!empty($postcode)) {
            $address->setPostalCode($postcode);
        }

        return $address;
    }
}