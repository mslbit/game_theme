<?php
namespace Folix\SimpleCheckout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

class BillingConfigProvider implements ConfigProviderInterface
{
    private CheckoutSession $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        /* 从 quote billing address 获取 firstname/lastname，如果没有则从 email 提取 */
        $quote = $this->checkoutSession->getQuote();
        $billingAddress = $quote->getBillingAddress();

        $firstname = $billingAddress ? $billingAddress->getFirstname() : '';
        $lastname = $billingAddress ? $billingAddress->getLastname() : '';

        if (empty($firstname) && empty($lastname)) {
            $email = $quote->getCustomerEmail() ?: ($billingAddress ? $billingAddress->getEmail() : '');
            if (!empty($email)) {
                /* email 格式如 john.doe@gmail.com，取 @ 前部分作为 name */
                $namePart = strstr($email, '@', true) ?: $email;
                $parts = preg_split('/[._-]/', $namePart, 2);
                $firstname = ucfirst($parts[0] ?? $namePart);
                $lastname = ucfirst($parts[1] ?? '');
            }
        }

        return [
            'defaultBillingAddress' => [
                'firstname' => $firstname ?: 'checkout',
                'lastname' => $lastname ?: 'checkout',
                'street' => ['checkout street 123'],
                'city' => 'Hong Kong',
                'postcode' => '123456',
                'country_id' => 'CN',
                'telephone' => '12345678',
                'region' => 'CN-HK',
            ]
        ];
    }
}