<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Oceanpayment 商品信息构建器
 *
 * 构建购物车商品明细的 API 请求参数：
 * - productName: 逗号分隔的商品名称列表
 * - productNum: 逗号分隔的商品数量列表
 * - productSku: 逗号分隔的商品 SKU 列表
 * - productPrice: 逗号分隔的商品单价列表
 *
 * 所有列表字段必须一一对应，顺序与数量保持一致
 */
class ProductBuilder implements BuilderInterface
{
    /**
     * 构建商品信息请求参数
     *
     * 遍历订单中所有可见商品，将名称、数量、SKU、单价
     * 分别拼接为逗号分隔的字符串
     *
     * @param array $buildSubject 构建参数，包含 payment 数据对象
     * @return array 商品信息 API 参数
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();

        $productNames = [];
        $productNums = [];
        $productSkus = [];
        $productPrices = [];

        /* 遍历订单中的所有商品项 */
        foreach ($order->getAllVisibleItems() as $item) {
            /** @var OrderItemInterface $item */
            $productNames[]  = $this->sanitizeProductField((string) $item->getName());
            $productNums[]   = (int) $item->getQtyOrdered();
            $productSkus[]   = $this->sanitizeProductField((string) $item->getSku());
            $productPrices[] = number_format((float) $item->getPrice(), 2, '.', '');
        }

        return [
            /* 商品名称列表：逗号分隔，如 "T-Shirt,Shoes,Hat" */
            'productName'  => implode(',', $productNames),

            /* 商品数量列表：逗号分隔，如 "2,1,3" */
            'productNum'   => implode(',', $productNums),

            /* 商品 SKU 列表：逗号分隔，如 "TS001,SH002,HT003" */
            'productSku'   => implode(',', $productSkus),

            /* 商品单价列表：逗号分隔，如 "29.99,59.00,15.50" */
            'productPrice' => implode(',', $productPrices),
        ];
    }

    /**
     * 清理商品字段中的特殊字符
     *
     * 移除逗号（避免与分隔符冲突）和可能影响 XML 解析的特殊字符
     *
     * @param string $value 原始字段值
     * @return string 清理后的字段值
     */
    private function sanitizeProductField(string $value): string
    {
        /* 移除逗号，防止与列表分隔符冲突 */
        $value = str_replace(',', ' ', $value);

        /* 移除 XML 特殊字符，防止解析异常 */
        $value = str_replace(['<', '>', '"', "'"], ' ', $value);

        return trim($value);
    }
}
