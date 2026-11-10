<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\ProductCarousel\Plugin;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Magento\CatalogWidget\Block\Product\ProductsList;
use Psr\Log\LoggerInterface;

/**
 * 服务端优化产品轮播图片
 *
 * 挂在 Magento\CatalogWidget\Block\Product\ProductsList::toHtml() 之后，
 * 在产品轮播 HTML 输出前完成以下优化：
 *
 * 转换规则：
 * - 目标容器：[data-content-type=products][data-appearance=carousel]
 * - 图片优化：
 *   - 移除 Bss_LazyImageLoader 残留的 data-src / data-lazy 属性
 *   - 第一个产品图片：不加 loading="lazy"（LCP 优化）
 *   - 其余产品图片：加 loading="lazy" + decoding="async"（原生懒加载）
 *   - 保留 width/height 防止布局偏移
 * - 骨架屏：为 <img> 自身添加 folix-product-skeleton class
 *   （shimmer 作为 <img> 的 background，图片位图绘制后自然覆盖骨架，
 *   与 BannerImg 方案一致：单一元素无层级博弈，无 JS、无 class 切换）
 */
class ProductsListPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 优化产品轮播 HTML 后返回
     *
     * @param ProductsList $subject
     * @param string $result
     * @return string
     */
    public function afterToHtml(ProductsList $subject, string $result): string
    {
        if ($result === '' || strpos($result, 'product-item') === false) {
            return $result;
        }

        try {
            return $this->optimizeCarouselImages($result);
        } catch (\Exception $e) {
            $this->logger->error('Folix_ProductCarousel: ' . $e->getMessage(), ['exception' => $e]);
            return $result;
        }
    }

    /**
     * DOM 层优化：图片 lazy loading + 骨架屏 class
     *
     * @param string $html
     * @return string
     */
    private function optimizeCarouselImages(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $converted = mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        );
        $document->loadHTML(
            '<html><body>' . $converted . '</body></html>',
            LIBXML_SCHEMA_CREATE
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        // 查找产品轮播容器内的所有产品图片
        $images = $xpath->query(
            '//div[@data-content-type="products" and @data-appearance="carousel"]'
            . '//img[@class="product-image-photo"]'
        );

        if ($images === false || $images->length === 0) {
            // 兜底：如果 XPath 没匹配到（PageBuilder 容器属性可能不同），
            // 尝试匹配所有 product-image-photo 图片
            $images = $xpath->query('//img[@class="product-image-photo"]');
            if ($images === false || $images->length === 0) {
                return $html;
            }
        }

        $isFirst = true;
        foreach ($images as $img) {
            /** @var DOMElement $img */
            $this->optimizeImage($img, $isFirst);
            $isFirst = false;
        }

        preg_match('/<body>(.+)<\/body><\/html>$/si', $document->saveHTML(), $matches);
        return !empty($matches) ? $matches[1] : $html;
    }

    /**
     * 优化单个产品图片
     *
     * @param DOMElement $img
     * @param bool $isFirst 是否第一个产品图片
     * @return void
     */
    private function optimizeImage(DOMElement $img, bool $isFirst): void
    {
        // 1. 移除 Bss_LazyImageLoader 残留属性
        foreach (['data-src', 'data-lazy-src', 'data-lazy'] as $attr) {
            if ($img->hasAttribute($attr)) {
                $img->removeAttribute($attr);
            }
        }

        // 2. 确保 src 是真实图片 URL（不是 1×1 占位 GIF）
        $src = $img->getAttribute('src');
        if ($src !== '' && strpos($src, 'data:image') === 0) {
            // src 是占位图但 data-src 已被移除，无法恢复真实 URL
            // 这种情况不应发生（Bss 卸载后不会出现），记录日志
            $this->logger->warning('Folix_ProductCarousel: placeholder src detected without data-src', [
                'tag' => $img->ownerDocument->saveHTML($img)
            ]);
            return;
        }

        // 3. 添加原生 lazy loading（首图除外，保证 LCP）
        if (!$isFirst) {
            $img->setAttribute('loading', 'lazy');
        }

        // 4. 异步解码
        $img->setAttribute('decoding', 'async');

        // 5. 为 <img> 自身添加骨架屏 class
        // 骨架是 <img> 的 background：位图绘制后自然覆盖，失败时骨架持续显示
        $this->addSkeletonClass($img);
    }

    /**
     * 为 <img> 自身添加骨架屏 class
     *
     * 骨架是 <img> 的 background（非容器 ::after），
     * 图片加载完成后位图自然覆盖骨架背景，无需淡出动画。
     *
     * @param DOMElement $img
     * @return void
     */
    private function addSkeletonClass(DOMElement $img): void
    {
        $class = $img->getAttribute('class');
        $img->setAttribute('class', trim($class . ' folix-product-skeleton'));
    }
}
