<?php
declare(strict_types=1);

namespace Folix\Customer\Plugin;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerExtensionFactory;
use Magento\Framework\Api\SearchResults;

/**
 * 客户扩展属性读取插件
 *
 * 从 Customer 的 customAttribute（EAV static 属性）中读取 phone/city/country_id，
 * 注入到 ExtensionAttributes 对象，使模板和 API 可以通过 getExtensionAttributes()->getPhone() 等访问。
 *
 * 保存流程不需要插件：
 * - CustomerExtractor::extract() 自动提取表单数据到 customAttribute
 * - toNestedArray() 将 extension_attributes 展平到数据数组
 * - EAV _collectSaveData() 自动处理 static 属性写入 customer_entity 表
 */
class CustomerExtensionAttributesPlugin
{
    /**
     * @var CustomerExtensionFactory
     */
    private CustomerExtensionFactory $extensionFactory;

    /**
     * @param CustomerExtensionFactory $extensionFactory
     */
    public function __construct(
        CustomerExtensionFactory $extensionFactory
    ) {
        $this->extensionFactory = $extensionFactory;
    }

    /**
     * afterGetById: 从 customAttribute 读取扩展属性
     *
     * @param CustomerRepositoryInterface $subject
     * @param CustomerInterface $customer
     * @return CustomerInterface
     */
    public function afterGetById(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer
    ): CustomerInterface {
        $this->loadExtensionAttributes($customer);
        return $customer;
    }

    /**
     * afterGet: 从 customAttribute 读取扩展属性
     *
     * @param CustomerRepositoryInterface $subject
     * @param CustomerInterface $customer
     * @return CustomerInterface
     */
    public function afterGet(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer
    ): CustomerInterface {
        $this->loadExtensionAttributes($customer);
        return $customer;
    }

    /**
     * afterGetList: 批量从 customAttribute 读取扩展属性
     *
     * @param CustomerRepositoryInterface $subject
     * @param SearchResults $searchResults
     * @return SearchResults
     */
    public function afterGetList(
        CustomerRepositoryInterface $subject,
        SearchResults $searchResults
    ): SearchResults {
        foreach ($searchResults->getItems() as $customer) {
            $this->loadExtensionAttributes($customer);
        }
        return $searchResults;
    }

    /**
     * 将 customAttribute 中的 phone/city/country_id 注入到 ExtensionAttributes
     *
     * 注册为 static EAV 属性后，CustomerRepository::getById() 返回的 Customer 对象
     * 会将 phone/city/country_id 作为 customAttribute 携带，
     * 但模板和 API 更习惯通过 extensionAttributes 访问。
     *
     * @param CustomerInterface $customer
     */
    private function loadExtensionAttributes(CustomerInterface $customer): void
    {
        $extensionAttributes = $customer->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        $phone = $customer->getCustomAttribute('phone');
        if ($phone !== null) {
            $extensionAttributes->setPhone($phone->getValue());
        }

        $city = $customer->getCustomAttribute('city');
        if ($city !== null) {
            $extensionAttributes->setCity($city->getValue());
        }

        $countryId = $customer->getCustomAttribute('country_id');
        if ($countryId !== null) {
            $extensionAttributes->setCountryId($countryId->getValue());
        }

        $customer->setExtensionAttributes($extensionAttributes);
    }
}