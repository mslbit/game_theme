<?php
declare(strict_types=1);

namespace MslPay\LianLian\Block;


/**
 * 连连支付信息块
 *
 * 在后台订单详情页显示连连支付的额外信息
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * 不在后台显示的交易信息字段
     */
    protected $_template = 'MslPay_LianLian::info/lianlian.phtml';
}