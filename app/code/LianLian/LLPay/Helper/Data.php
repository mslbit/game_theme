<?php
/**
 * User: 罗志禹
 * Date: 2022/1/13
 * Email: lzy_email@sina.cn
 */

namespace LianLian\LLPay\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    const CONFIG_PAY_REDIRECT_PATH = 'payment/llpay_custompaymentoption/redirect_url';
    const CONFIG_PAY_SANDBOX_PATH = 'payment/llpay_custompaymentoption/sandbox';
    const CONFIG_PAY_NOTIFICATION_URL_PATH = 'payment/llpay_custompaymentoption/notification_url';
    const CONFIG_PAY_MERCHANT_SANDBOX_ID_PATH = 'payment/llpay_custompaymentoption/merchant_sandbox_id';
    const CONFIG_PAY_MERCHANT_PRO_ID_PATH = 'payment/llpay_custompaymentoption/merchant_pro_id';
    const CONFIG_PAY_LL_PUB_KEY_PATH = 'payment/llpay_custompaymentoption/ll_pub_key';
    const CONFIG_PAY_MERCHANT_PRI_PATH = 'payment/llpay_custompaymentoption/merchant_pri_key';

    /**
     * @var EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    public $_logger;

    /**
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        StoreManagerInterface  $storeManager,
        EncryptorInterface     $encryptor,
        LoggerInterface $logger
    ) {
        $this->_encryptor = $encryptor;
        $this->_logger = $logger;
        $this->_storeManager = $storeManager;
        parent::__construct($context);
    }

    /**
     * @param $field
     * @param null $scopeValue
     * @param string $scopeType
     * @return array|mixed
     */
    public function getConfigValue($field, $scopeValue = null, string $scopeType = ScopeInterface::SCOPE_STORE)
    {
        return $this->scopeConfig->getValue($field, $scopeType, $scopeValue);
    }

    /**
     * 是否是沙箱环境
     *
     * @return bool
     */
    public function getIsSandBoxEnv(): bool
    {
        return $this->getConfigValue(static::CONFIG_PAY_SANDBOX_PATH);
    }

    /**
     * 重定向地址
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function redirectUrl(): string
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        return $baseUrl . $this->getConfigValue(static::CONFIG_PAY_REDIRECT_PATH);
    }

    /**
     * 回调地址
     *
     * @return string
     */
    public function notificationUrl(): string
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        return $baseUrl . $this->getConfigValue(static::CONFIG_PAY_NOTIFICATION_URL_PATH);
    }

    /**
     * 商户号
     *
     * @return mixed
     */
    public function getMerchantId()
    {
        if ($this->getIsSandBoxEnv()) {
            $merchantId = $this->getConfigValue(static::CONFIG_PAY_MERCHANT_SANDBOX_ID_PATH);
        } else {
            $merchantId = $this->getConfigValue(static::CONFIG_PAY_MERCHANT_PRO_ID_PATH);
        }
        return $merchantId;
    }

    /**
     * 连连公钥
     *
     * @return string
     */
    public function getLLPubKey(): string
    {
        return $this->_encryptor->decrypt($this->getConfigValue(static::CONFIG_PAY_LL_PUB_KEY_PATH));
    }

    /**
     * 商户私钥
     *
     * @return string
     */
    public function getMerchantPriKey(): string
    {
        return $this->_encryptor->decrypt($this->getConfigValue(static::CONFIG_PAY_MERCHANT_PRI_PATH));
    }

    /**
     * 连连支付回调信息
     *
     * @param $status
     * @return string
     */
    public function getLLPayCodeMsg($status): string
    {
        $msg = '';
        switch ($status) {
            case $status === 'IN';
                $msg = 'Payment Initialize';
                break;
            case $status === 'WP';
                $msg = 'Waiting Payment';
                break;
            case $status === 'PP';
                $msg = 'Payment Processing';
                break;
            case $status === 'PC';
                $msg = 'Payment Cancel';
                break;
            case $status === 'PS';
                $msg = 'Payment Success';
                break;
            case $status === 'PF';
                $msg = 'Payment Failed';
                break;
            case $status === 'RP';
                $msg = 'Refund Processing';
                break;
            case $status === 'RS';
                $msg = 'Refund Success';
                break;
            case $status === 'PR';
                $msg = 'Partial Refund';
                break;
            case $status === 'CB';
                $msg = 'Charge Back';
                break;
        }
        return $msg;
    }

    /**
     * 对象转化数组
     *
     * @param $obj
     * @return array
     */
    public function objectToArray($obj): array
    {
        $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
        $arr = null;
        foreach ($_arr as $key => $val) {
            $val = (is_array($val)) || is_object($val) ? $this->objectToArray($val) : $val;
            $arr[$key] = $val;
        }
        return $arr;
    }

}
