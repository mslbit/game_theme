<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\AddressInterface;
use MslPay\LianLian\Api\Data\ShippingInterface;

class Shipping extends DataObject implements ShippingInterface
{
    public function getName(): string
    {
        return (string) ($this->_data[self::NAME] ?? '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::NAME, $name);
    }

    public function getFirstName(): string
    {
        return (string) ($this->_data[self::FIRST_NAME] ?? '');
    }

    public function setFirstName(string $firstName): static
    {
        return $this->setData(self::FIRST_NAME, $firstName);
    }

    public function getLastName(): string
    {
        return (string) ($this->_data[self::LAST_NAME] ?? '');
    }

    public function setLastName(string $lastName): static
    {
        return $this->setData(self::LAST_NAME, $lastName);
    }

    public function getPhone(): string
    {
        return (string) ($this->_data[self::PHONE] ?? '');
    }

    public function setPhone(string $phone): static
    {
        return $this->setData(self::PHONE, $phone);
    }

    public function getAddress(): ?AddressInterface
    {
        $addr = $this->_data[self::ADDRESS] ?? null;
        return $addr instanceof AddressInterface ? $addr : null;
    }

    public function setAddress(AddressInterface $address): static
    {
        return $this->setData(self::ADDRESS, $address);
    }

    public function getCycle(): string
    {
        return (string) ($this->_data[self::CYCLE] ?? '48h');
    }

    public function setCycle(string $cycle): static
    {
        return $this->setData(self::CYCLE, $cycle);
    }
}