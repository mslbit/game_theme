<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\AddressInterface;

class Address extends DataObject implements AddressInterface
{
    public function getLine1(): string
    {
        return (string) ($this->_data[self::LINE1] ?? '');
    }

    public function setLine1(string $line1): static
    {
        return $this->setData(self::LINE1, $line1);
    }

    public function getLine2(): string
    {
        return (string) ($this->_data[self::LINE2] ?? '');
    }

    public function setLine2(string $line2): static
    {
        return $this->setData(self::LINE2, $line2);
    }

    public function getCity(): string
    {
        return (string) ($this->_data[self::CITY] ?? '');
    }

    public function setCity(string $city): static
    {
        return $this->setData(self::CITY, $city);
    }

    public function getState(): string
    {
        return (string) ($this->_data[self::STATE] ?? '');
    }

    public function setState(string $state): static
    {
        return $this->setData(self::STATE, $state);
    }

    public function getCountry(): string
    {
        return (string) ($this->_data[self::COUNTRY] ?? '');
    }

    public function setCountry(string $country): static
    {
        return $this->setData(self::COUNTRY, $country);
    }

    public function getPostalCode(): string
    {
        return (string) ($this->_data[self::POSTAL_CODE] ?? '');
    }

    public function setPostalCode(string $postalCode): static
    {
        return $this->setData(self::POSTAL_CODE, $postalCode);
    }

    public function getDistrict(): string
    {
        return (string) ($this->_data[self::DISTRICT] ?? '');
    }

    public function setDistrict(string $district): static
    {
        return $this->setData(self::DISTRICT, $district);
    }
}