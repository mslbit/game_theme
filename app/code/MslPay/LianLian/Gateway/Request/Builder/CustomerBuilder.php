<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use MslPay\LianLian\Api\Data\LianLianPaymentInterface;

use MslPay\LianLian\Gateway\Helper\AddressBuilder;
use MslPay\LianLian\Model\Data\Customer;

/**
 * 连连支付客户信息构建器
 *
 * 构建 customer 对象（使用 DataObject 模型）：
 * - customer_type: I(个人)/C(公司)
 * - first_name / last_name / full_name
 * - email / phone
 * - address: 付款地址（Address DataObject）
 *
 * 返回的数组中，customer 值为 Customer DataObject 实例，
 * 最终由 TransferFactory 递归展平为纯数组。
 */
class CustomerBuilder implements BuilderInterface
{
    private AddressBuilder $addressBuilder;

    public function __construct(AddressBuilder $addressBuilder)
    {
        $this->addressBuilder = $addressBuilder;
    }

    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();

        $billingAddress = $order->getBillingAddress();
        $email = $billingAddress ? $billingAddress->getEmail() : '';
        $firstName = $billingAddress ? $billingAddress->getFirstname() : '';
        $lastName = $billingAddress ? $billingAddress->getLastname() : '';
        $phone = $billingAddress ? (string) $billingAddress->getTelephone() : '';

        /* firstName/lastName 为空时从 email 提取：取 @ 前缀，用 '.' 或 '_' 分割为 first/last */
        if (empty($firstName)) {
            $emailLocal = strstr($email, '@', true) ?: $email;
            $parts = preg_split('/[._]/', $emailLocal, 2);
            $firstName = $parts[0] ?? $emailLocal;
            $lastName = $lastName ??  ($parts[1] ?? $firstName);
        }
      
        $lastName = $lastName ?? $firstName;

        /* 构建 Customer DataObject */
        $customer = new Customer();
        $customer->setCustomerType('I')
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setFullName(trim($firstName . ' ' . $lastName))
            ->setEmail($email)
            ->setPhone($phone);

        /* 付款地址：从 billing address 构建 Address DataObject */
        if ($billingAddress) {
            $customer->setAddress($this->addressBuilder->build($billingAddress));
        }

        return [
            LianLianPaymentInterface::CUSTOMER => $customer,
        ];
    }
}
