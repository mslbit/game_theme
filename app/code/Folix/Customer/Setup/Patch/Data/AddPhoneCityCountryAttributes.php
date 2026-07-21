<?php
declare(strict_types=1);

namespace Folix\Customer\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * 注册 phone/city/country_id 为 customer static EAV 属性
 *
 * type=static 表示数据存储在 customer_entity 主表列上（由 db_schema.xml 创建），
 * 但需要在 eav_attribute 表中注册，EAV 引擎的 _collectSaveData() 才能在保存时处理它们。
 * 同时关联到 customer_account_edit 表单，使 CustomerExtractor 自动提取表单数据。
 */
class AddPhoneCityCountryAttributes implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private ModuleDataSetupInterface $moduleDataSetup;

    /**
     * @var CustomerSetupFactory
     */
    private CustomerSetupFactory $customerSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory $customerSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
    }

    /**
     * @inheritdoc
     */
    public function apply(): void
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributes = [
            'phone' => [
                'type' => 'static',
                'label' => 'Phone Number',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => false,
                'system' => false,
                'sort_order' => 120,
                'position' => 120,
            ],
            'city' => [
                'type' => 'static',
                'label' => 'City',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => false,
                'system' => false,
                'sort_order' => 130,
                'position' => 130,
            ],
            'country_id' => [
                'type' => 'static',
                'label' => 'Country',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => false,
                'system' => false,
                'sort_order' => 140,
                'position' => 140,
            ],
        ];

        foreach ($attributes as $attributeCode => $attributeData) {
            $customerSetup->addAttribute(
                Customer::ENTITY,
                $attributeCode,
                $attributeData
            );

            /* 关联到表单，使 CustomerExtractor::extract() 能自动提取 */
            $attribute = $customerSetup->getEavConfig()
                ->getAttribute(Customer::ENTITY, $attributeCode);

            $usedInForms = [
                'customer_account_create',
                'customer_account_edit',
                'adminhtml_customer',
            ];

            $attribute->setData('used_in_forms', $usedInForms);
            $attribute->save();
        }
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}