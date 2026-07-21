<?php
/**
 * Created by
 * User：罗志禹
 * Date：2022/2/27
 * Time：22:45
 */

namespace LianLian\LLPay\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class Checkout extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code  = 'llpay_custompaymentoption';

    /**
     * Set value after save payment from post data to use in case capture or authorize
     *
     * @param DataObject $data
     * @return $this|Checkout
     * @throws LocalizedException
     */
    public function assignData(DataObject $data): Checkout
    {
        parent::assignData($data);
        $this->getInfoInstance()->setAdditionalInformation('post_ll_data_value', $data->getData());

        return $this;
    }
}
