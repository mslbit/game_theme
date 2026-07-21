<?php
/**
 * Created by
 * User：罗志禹
 * Date：2022/2/27
 * Time：22:41
 */

namespace LianLian\LLPay\Model;

use LianLian\LLPay\Helper\Data;
use lianlianpay\v3sdk\service\Payment;
use lianlianpay\v3sdk\core\PaySDK;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;

class CheckoutConfigProvider implements ConfigProviderInterface
{

    /**
     * @var string
     */
    protected $methodCode = "llpay_custompaymentoption";

    /**
     * @var MethodInterface
     */
    protected $method;

    /**
     * @var Payment
     */
    protected $paymentLL;

    /**
     * @var Data
     */
    protected $llHelper;

    /**
     * @param Payment $payment
     * @param PaymentHelper $paymentHelper
     * @param Data $llHelper
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        Payment $payment,
        PaymentHelper $paymentHelper,
        Data $llHelper
    ) {
        $this->paymentLL = $payment;
        $this->llHelper = $llHelper;
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);
    }

    /**
     * 给前端返回内容
     *
     * @return array
     */
    public function getConfig(): array
    {
        //调用payment的get_token方法，返回参数
        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init($this->llHelper->getIsSandBoxEnv());

        $merchant_id = $this->llHelper->getMerchantId();
        $public_key  = $this->llHelper->getLLPubKey();
        $private_key = $this->llHelper->getMerchantPriKey();
        if (!$merchant_id || !$public_key || !$private_key) {
            return [];
        }
        $pay_token_response = $this->paymentLL->get_token($merchant_id, $private_key, $public_key);

        $this->llHelper->_logger->log('INFO', 'token_response', $pay_token_response);

        $token = '';
        if (isset($pay_token_response['sign_verify']) && $pay_token_response['sign_verify']) {
            $token = $pay_token_response['order'];
        }

        return $this->method->isAvailable() ? [
            'payment' => [
                'llpay_custompaymentoption' => [
                    "token" => $token,
                    "env" => $this->llHelper->getIsSandBoxEnv()
                ]
            ]
        ] : [];
    }
}
