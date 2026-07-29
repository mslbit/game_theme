<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Store\Model\StoreManagerInterface;
use MslPay\LianLian\Api\Data\LianLianPaymentInterface;
use MslPay\LianLian\Gateway\Config\Config;

/**
 * 连连支付交易参数构建器
 *
 * 收银台模式：payment_method 固定为 Checkout（用户在连连收银台页面选择具体支付方式）
 */
class TransactionBuilder implements BuilderInterface
{
    private Config $config;
    private StoreManagerInterface $storeManager;

    public function __construct(Config $config, StoreManagerInterface $storeManager)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();

        $baseUrl = $this->storeManager->getStore($order->getStoreId())->getBaseUrl();

        return [
            LianLianPaymentInterface::MERCHANT_TRANSACTION_ID => $order->getOrderIncrementId(),
            LianLianPaymentInterface::MERCHANT_ID => $this->config->getMerchantId(),
            LianLianPaymentInterface::SUB_MERCHANT_ID => $this->config->getSubMerchantId(),
            LianLianPaymentInterface::NOTIFICATION_URL => trim($baseUrl,'/') . '/rest/V1/lianlian/notify',
            LianLianPaymentInterface::REDIRECT_URL => trim($baseUrl,'/') . '/lianlian/payment/back',
            LianLianPaymentInterface::CANCEL_URL => trim($baseUrl,'/') . '/checkout/onepage/failure',
            LianLianPaymentInterface::COUNTRY => $this->config->getCountry(),
            LianLianPaymentInterface::PAYMENT_METHOD => '',
        ];
    }
}
