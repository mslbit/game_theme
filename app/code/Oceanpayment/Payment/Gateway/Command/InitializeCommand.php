<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Service\SendTradeService;

/**
 * Oceanpayment 初始化命令
 *
 * 用于托管收银（Hosted Checkout）模式下的订单初始化：
 * 1. 调用 SendTradeService 请求 sendTrade API 获取 pay_url
 * 2. 将 pay_url 存入 payment additional_information
 * 3. 设置订单状态为 pending_payment（等待支付）
 *
 * 当 config 中 can_initialize=1 时，Magento 的 Payment::place() 会走
 * isInitializeNeeded 分支，调用此命令而非直接走 processAction。
 *
 * 设计说明：
 * - 不再使用 AuthorizeCommand / GatewayCommand / BuilderComposite
 * - 直接调用 SendTradeService，简化架构
 * - TransferFactory 保留为通用 HTTP 传输层，后期可扩展其他模式
 */
class InitializeCommand implements CommandInterface
{
    /**
     * additional_information 中 pay_url 的键名
     */
    private const KEY_PAY_URL = 'oceanpayment_pay_url';

    /**
     * @var SendTradeService
     */
    private SendTradeService $sendTradeService;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param SendTradeService $sendTradeService
     * @param Config $config
     */
    public function __construct(
        SendTradeService $sendTradeService,
        Config $config
    ) {
        $this->sendTradeService = $sendTradeService;
        $this->config = $config;
    }

    /**
     * 执行初始化命令
     *
     * merchant_controlled 模式：调 SendTradeService 获取 pay_url，存入 additional_information
     * auto_redirect 模式：仅设置 pending_payment，pay_url 由 PlacePay 落单后获取
     *
     * @param array $commandSubject 命令参数，包含 payment 和 stateObject
     */
    public function execute(array $commandSubject)
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $commandSubject['payment'];
        /** @var \Magento\Framework\DataObject $stateObject */
        $stateObject = $commandSubject['stateObject'];

        /* merchant_controlled 模式：初始化时请求网关获取 pay_url */
        if (!$this->config->isAutoRedirect()) {
            $result = $this->sendTradeService->execute($paymentDO);
            $payment = $paymentDO->getPayment();
            $payment->setAdditionalInformation(self::KEY_PAY_URL, $result['pay_url']);
        }

        /* 两种模式都设置订单状态为 pending_payment */
        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('is_notified', false);
    }
}
