<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Data;

use Magento\Framework\DataObject;
use MslPay\LianLian\Api\Data\AddressInterface;
use MslPay\LianLian\Api\Data\CustomerInterface;

class Customer extends DataObject implements CustomerInterface
{
    public function getCustomerType(): string
    {
        return (string) ($this->_data[self::CUSTOMER_TYPE] ?? 'I');
    }

    public function setCustomerType(string $customerType): static
    {
        return $this->setData(self::CUSTOMER_TYPE, $customerType);
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

    public function getFullName(): string
    {
        return (string) ($this->_data[self::FULL_NAME] ?? '');
    }

    public function setFullName(string $fullName): static
    {
        return $this->setData(self::FULL_NAME, $fullName);
    }

    public function getEmail(): string
    {
        return (string) ($this->_data[self::EMAIL] ?? '');
    }

    public function setEmail(string $email): static
    {
        return $this->setData(self::EMAIL, $email);
    }

    public function getPhone(): string
    {
        return (string) ($this->_data[self::PHONE] ?? '');
    }

    public function setPhone(string $phone): static
    {
        return $this->setData(self::PHONE, $phone);
    }

    public function getGender(): string
    {
        return (string) ($this->_data[self::GENDER] ?? '');
    }

    public function setGender(string $gender): static
    {
        return $this->setData(self::GENDER, $gender);
    }

    public function getCompany(): string
    {
        return (string) ($this->_data[self::COMPANY] ?? '');
    }

    public function setCompany(string $company): static
    {
        return $this->setData(self::COMPANY, $company);
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
}