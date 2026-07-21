<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model;

use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Checkout\Api\PaymentInformationManagementInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\UrlInterface;
use Oceanpayment\Payment\Api\PlacePayInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Service\AutoRedirectService;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 发起支付 REST API 实现
 *
 * 职责：下单 + 根据模式分发获取支付跳转地址
 *
 * merchant_controlled: InitializeCommand 已调 sendTrade → 从 additional_information 读 pay_url
 * auto_redirect: 落单后调 AutoRedirectService → POST /pay → 获取重定向地址
 */
class PlacePay implements PlacePayInterface
{
    /**
     * additional_information 中 pay_url 的键名
     */
    private const KEY_PAY_URL = 'oceanpayment_pay_url';

    /**
     * @var PaymentInformationManagementInterface
     */
    private PaymentInformationManagementInterface $paymentInfoManagement;

    /**
     * @var GuestPaymentInformationManagementInterface
     */
    private GuestPaymentInformationManagementInterface $guestPaymentInfoManagement;

    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var UrlInterface
     */
    private UrlInterface $url;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var AutoRedirectService
     */
    private AutoRedirectService $autoRedirectService;

    /**
     * @var PaymentDataObjectFactoryInterface
     */
    private PaymentDataObjectFactoryInterface $paymentDataObjectFactory;

    /**
     * @param PaymentInformationManagementInterface $paymentInfoManagement
     * @param GuestPaymentInformationManagementInterface $guestPaymentInfoManagement
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param UrlInterface $url
     * @param LoggerInterface $logger
     * @param Config $config
     * @param AutoRedirectService $autoRedirectService
     * @param PaymentDataObjectFactoryInterface $paymentDataObjectFactory
     */
    public function __construct(
        PaymentInformationManagementInterface $paymentInfoManagement,
        GuestPaymentInformationManagementInterface $guestPaymentInfoManagement,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        UrlInterface $url,
        LoggerInterface $logger,
        Config $config,
        AutoRedirectService $autoRedirectService,
        PaymentDataObjectFactoryInterface $paymentDataObjectFactory
    ) {
        $this->paymentInfoManagement = $paymentInfoManagement;
        $this->guestPaymentInfoManagement = $guestPaymentInfoManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->url = $url;
        $this->logger = $logger;
        $this->config = $config;
        $this->autoRedirectService = $autoRedirectService;
        $this->paymentDataObjectFactory = $paymentDataObjectFactory;
    }

    /**
     * @inheritDoc
     */
    public function placePay(
        int $cartId,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null
    ): string {
        try {
            $orderId = $this->paymentInfoManagement->savePaymentInformationAndPlaceOrder(
                $cartId,
                $paymentMethod,
                $billingAddress
            );

            return $this->getRedirectUrl((int) $orderId);
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] PlacePay failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function guestPlacePay(
        string $cartId,
        string $email,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null
    ): string {
        try {
            $orderId = $this->guestPaymentInfoManagement->savePaymentInformationAndPlaceOrder(
                $cartId,
                $email,
                $paymentMethod,
                $billingAddress
            );

            return $this->getRedirectUrl((int) $orderId);
        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Guest PlacePay failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 根据模式获取支付跳转地址
     *
     * merchant_controlled: 从 additional_information 读取 pay_url（InitializeCommand 已存入）
     * auto_redirect: 调 AutoRedirectService 落单后请求 /pay 端点
     *
     * @param int $orderId
     * @return string 支付跳转地址
     */
    private function getRedirectUrl(int $orderId): string
    {
        $order = $this->orderRepository->get($orderId);
        $payment = $order->getPayment();

        if (!$payment) {
            throw new \RuntimeException('Order payment not found');
        }

        /* merchant_controlled 模式：InitializeCommand 已存入 pay_url */
        if (!$this->config->isAutoRedirect()) {
            $payUrl = $payment->getAdditionalInformation(self::KEY_PAY_URL);
            if (empty($payUrl)) {
                throw new \RuntimeException('Failed to get payment URL from Oceanpayment');
            }
            return $payUrl;
        }

        /* auto_redirect 模式：落单后 POST /pay → 获取重定向地址 */
        $paymentDO = $this->paymentDataObjectFactory->create($payment);
        $result = $this->autoRedirectService->execute($paymentDO);

        $redirectUrl = $result['redirect_url'] ?? '';
        if (empty($redirectUrl)) {
            throw new \RuntimeException('Failed to get payment redirect URL from Oceanpayment');
        }

        /* 存入 additional_information 供后续使用 */
        $payment->setAdditionalInformation(self::KEY_PAY_URL, $redirectUrl);
        $this->orderRepository->save($order);

        return $redirectUrl;
    }
}
