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
        $limit = max(1, min(100, (int)($limits['max_rows'] ?? 50)));
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
            'processed_count' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);
        $repo->updateCursor($jobId, [
            'page' => $page + 1,
            'processed' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);

        return ['done' => $done, 'processed' => $processed];
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
            'attributes' => $variation->get_attributes(),
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
