<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Request\Builder;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Store\Model\StoreManagerInterface;
use MslPay\LianLian\Api\Data\LianLianPaymentInterface;

use MslPay\LianLian\Gateway\Helper\AddressBuilder;
use MslPay\LianLian\Model\Data\MerchantOrder;
use MslPay\LianLian\Model\Data\Product;
use MslPay\LianLian\Model\Data\Shipping;

/**
 * 连连支付订单信息构建器
 *
 * 构建 merchant_order 对象（使用 DataObject 模型）：
 * - merchant_order_id: 订单号
 * - merchant_order_time: 下单时间
 * - order_amount: 金额（从 buildSubject['amount'] 读取，即 baseTotalDue）
 * - order_currency_code: 币种（OrderAdapter.getCurrencyCode() 返回 baseCurrencyCode）
 * - order_description: 订单描述
 * - products: 商品列表（Product DataObject 数组，含 sku、url 字段，国际卡支付必传）
 * - shipping: 收货人信息（Shipping DataObject，实物商品必传）
 *
 * 返回的数组中，merchant_order 值为 MerchantOrder DataObject 实例，
 * 最终由 TransferFactory 递归展平为纯数组。
 *
 * 金额传递时序（与 Braintree 一致）：
 * Order::getBaseTotalDue()
 *   → Order\Payment::processAction() 传入 authorize(true, $baseTotalDue)
 *   → Adapter::authorize($payment, $amount) 传入 executeCommand('authorize', ['payment'=>..., 'amount'=>$amount])
 *   → Builder::build($buildSubject) 通过 SubjectReader::readAmount($buildSubject) 读取
 */
class OrderBuilder implements BuilderInterface
{
    private AddressBuilder $addressBuilder;
    private ProductRepositoryInterface $productRepository;
    private StoreManagerInterface $storeManager;

    public function __construct(
        AddressBuilder $addressBuilder,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->addressBuilder = $addressBuilder;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
    }

    public function build(array $buildSubject): array
    {
        $paymentDO = $this->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $amount = SubjectReader::readAmount($buildSubject);

        /* 构建 MerchantOrder DataObject */
        $merchantOrder = new MerchantOrder();
        $merchantOrder->setMerchantOrderId($order->getOrderIncrementId())
            ->setMerchantOrderTime(date('YmdHis'))
            ->setOrderDescription('')
            ->setOrderAmount(number_format((float) $amount, 2, '.', ''))
            ->setOrderCurrencyCode($order->getCurrencyCode())
            ->setProducts($this->buildProducts($paymentDO));

        /* 收货人信息：实物商品必传 */
        $shipping = $this->buildShipping($paymentDO);
        if ($shipping !== null) {
            $merchantOrder->setShipping($shipping);
        }

        return [
            LianLianPaymentInterface::MERCHANT_ORDER => $merchantOrder,
        ];
    }

    /**
     * 构建商品列表（Product DataObject 数组）
     *
     * 补充 sku、url 字段，国际卡支付规范要求必传
     *
     * @return Product[]
     */
    private function buildProducts(PaymentDataObjectInterface $paymentDO): array
    {
        $order = $paymentDO->getOrder();
        $items = $order->getItems();
        $products = [];

        foreach ($items as $item) {
            if ($item->getProductType() === 'configurable') {
                continue;
            }

            $product = new Product();
            $product->setProductId($item->getProductId())
                ->setName($this->sanitize((string) $item->getName()))
                ->setPrice(number_format((float) $item->getRowTotal(), 2, '.', ''))
                ->setQuantity((int) $item->getQtyOrdered())
                ->setSku($this->sanitize((string) $item->getSku()))
                ->setShippingProvider( 'other')
                ->setUrl($this->buildProductUrl($paymentDO, $item->getSku()));

            $products[] = $product;
        }

        return $products;
    }

    /**
     * 构建收货人信息（Shipping DataObject）
     *
     * 实物商品必传，虚拟商品不传（OrderAdapter.getShippingAddress() 返回 null）
     */
    private function buildShipping(PaymentDataObjectInterface $paymentDO): ?Shipping
    {
        $order = $paymentDO->getOrder();
        $shippingAddress = $order->getShippingAddress();

        if (!$shippingAddress) {
            return null;
        }

        $firstName = $shippingAddress->getFirstname() ?? '';
        $lastName = $shippingAddress->getLastname() ?? '';

        /* 构建 Address DataObject */
        $address = $this->addressBuilder->build($shippingAddress);

        /* 构建 Shipping DataObject */
        $shipping = new Shipping();
        $shipping->setName(trim($firstName . ' ' . $lastName))
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setPhone((string) ($shippingAddress->getTelephone() ?? ''))
            ->setCycle('48h')
            ->setAddress($address);

        return $shipping;
    }

    /**
     * 构建商品 URL
     *
     * 通过产品资源获取商品真实 URL，国际卡支付必传
     */
    private function buildProductUrl(PaymentDataObjectInterface $paymentDO, string $sku): string
    {
        try {
            $storeId = (int) $paymentDO->getOrder()->getStoreId();
            $product = $this->productRepository->get($sku, false, $storeId);
            return $product->getProductUrl();
        } catch (\Exception $e) {
            try {
                $store = $this->storeManager->getStore($paymentDO->getOrder()->getStoreId());
                return $store->getBaseUrl();
            } catch (\Exception $e2) {
                return '';
            }
        }
    }

    /**
     * 清理字符串，移除特殊字符
     */
    private function sanitize(string $value): string
    {
        return trim(str_replace(['<', '>', '"', "'"], ' ', str_replace(',', ' ', $value)));
    }

    private function readPayment(array $buildSubject): PaymentDataObjectInterface
    {
        return SubjectReader::readPayment($buildSubject);
    }
}
