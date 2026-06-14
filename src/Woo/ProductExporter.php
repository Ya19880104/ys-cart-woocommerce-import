<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Woo;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Packages\PackageWriter;

final class ProductExporter
{
    public function exportBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!function_exists('wc_get_products')) {
            return ['done' => true, 'message' => 'WooCommerce is not available.'];
        }

        $page = max(1, (int)($cursor['page'] ?? 1));
        $alreadyProcessed = (int)($cursor['processed'] ?? 0);
        $maxTotal = max(0, (int)($options['max_total'] ?? 0));
        if ($maxTotal > 0 && $alreadyProcessed >= $maxTotal) {
            return ['done' => true, 'processed' => 0];
        }

        $limit = max(1, min(100, (int)($limits['max_rows'] ?? 50)));
        if ($maxTotal > 0) {
            $limit = min($limit, $maxTotal - $alreadyProcessed);
        }
        $packageId = $options['package_id'] ?? ('job-' . $jobId);
        $products = wc_get_products([
            'limit' => $limit,
            'page' => $page,
            'paginate' => false,
            'orderby' => 'ID',
            'order' => 'ASC',
            'status' => $options['statuses'] ?? ['publish', 'draft', 'private'],
            'type' => $options['types'] ?? ['simple', 'variable'],
        ]);

        $writer = new PackageWriter();
        foreach ($products as $product) {
            $writer->appendJsonLine($packageId, 'products.jsonl', $this->serializeProduct($product));

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variationId) {
                    $variation = wc_get_product($variationId);
                    if ($variation) {
                        $writer->appendJsonLine($packageId, 'product_variants.jsonl', $this->serializeVariation($variation, $product));
                    }
                }
            }
        }

        $processed = count($products);
        $done = $processed < $limit;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => $alreadyProcessed + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);
        $repo->updateCursor($jobId, [
            'page' => $page + 1,
            'processed' => $alreadyProcessed + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);

        return ['done' => $done || ($maxTotal > 0 && $alreadyProcessed + $processed >= $maxTotal), 'processed' => $processed];
    }

    private function serializeProduct($product): array
    {
        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            $attributes[] = [
                'name' => $attribute->get_name(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options' => $attribute->is_taxonomy()
                    ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                    : $attribute->get_options(),
            ];
        }

        return [
            'source_id' => (string)$product->get_id(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price' => $product->get_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'weight' => $product->get_weight(),
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
            'image_url' => $this->imageUrl((int)$product->get_image_id()),
            'gallery_urls' => array_values(array_filter(array_map([$this, 'imageUrl'], $product->get_gallery_image_ids()))),
            'attributes' => $attributes,
            'category_names' => $this->categoryNames($product->get_id()),
        ];
    }

    private function serializeVariation($variation, $parent): array
    {
        return [
            'source_id' => (string)$variation->get_id(),
            'source_parent_id' => (string)$parent->get_id(),
            'sku' => $variation->get_sku(),
            'attributes' => $this->restoreVariationAttributeKeys($variation->get_attributes(), $parent),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'price' => $variation->get_price(),
            'stock_quantity' => $variation->get_stock_quantity(),
            'weight' => $variation->get_weight(),
            'length' => $variation->get_length(),
            'width' => $variation->get_width(),
            'height' => $variation->get_height(),
            'image_url' => $this->imageUrl((int)$variation->get_image_id()),
        ];
    }

    /**
     * 還原 variation attributes 的 key。
     *
     * WooCommerce 對 custom（非 taxonomy）attribute，WC_Product_Variation::get_attributes() 回傳的
     * key 是 sanitize_title(規格名)（中文→percent-encoded，如「商品規格」→ %e5%95%86...）。用 parent
     * 的原始規格名建 sanitize_title(name) => name 對照還原，避免把編碼 key 寫進 YS CART 的
     * product_variants.attributes（否則前台規格軸名會顯示成亂碼）。
     *
     * 不用 rawurldecode：sanitize_title 對純中文可逆，但對含空格/英數/大寫的名稱不可逆（空格→-、
     * 轉小寫），會還原錯。taxonomy attribute 的 key（pa_xxx slug）經此對照維持原樣。
     */
    private function restoreVariationAttributeKeys(array $attributes, $parent): array
    {
        $map = [];
        foreach ($parent->get_attributes() as $attr) {
            if (is_object($attr) && method_exists($attr, 'get_name')) {
                $name = (string)$attr->get_name();
                if ($name !== '') {
                    $map[sanitize_title($name)] = $name;
                }
            }
        }

        $restored = [];
        foreach ($attributes as $key => $value) {
            $k = (string)$key;
            $restored[$map[$k] ?? $k] = $value;
        }
        return $restored;
    }

    private function imageUrl(int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }

        return (string)wp_get_attachment_url($attachmentId);
    }

    private function categoryNames(int $productId): array
    {
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'names']);
        return is_wp_error($terms) ? [] : (array)$terms;
    }
}
