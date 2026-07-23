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

class PlacePay implements PlacePayInterface
{
    private const KEY_PAY_URL = 'oceanpayment_pay_url';

    private PaymentInformationManagementInterface $paymentInfoManagement;
    private GuestPaymentInformationManagementInterface $guestPaymentInfoManagement;
    private CartRepositoryInterface $quoteRepository;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private UrlInterface $url;
    private LoggerInterface $logger;
    private Config $config;
    private AutoRedirectService $autoRedirectService;
    private PaymentDataObjectFactoryInterface $paymentDataObjectFactory;

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

    private function getRedirectUrl(int $orderId): string
    {
        $order = $this->orderRepository->get($orderId);
        $payment = $order->getPayment();

        if (!$payment) {
            throw new \RuntimeException('Order payment not found');
        }

        if (!$this->config->isAutoRedirect()) {
            $payUrl = $payment->getAdditionalInformation(self::KEY_PAY_URL);
            if (empty($payUrl)) {
                throw new \RuntimeException('Failed to get payment URL from Oceanpayment');
            }
            return $payUrl;
        }

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $paymentDO = $this->paymentDataObjectFactory->create($payment);
        $result = $this->autoRedirectService->execute($paymentDO);

        $redirectUrl = $result['redirect_url'] ?? '';
        if (empty($redirectUrl)) {
            throw new \RuntimeException('Failed to get payment redirect URL from Oceanpayment');
        }

        $payment->setAdditionalInformation(self::KEY_PAY_URL, $redirectUrl);
        $this->orderRepository->save($order);

        return $redirectUrl;
    }
}