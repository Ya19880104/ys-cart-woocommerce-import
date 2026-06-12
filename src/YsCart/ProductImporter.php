<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

use Throwable;
use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Database\MapRepository;
use YangSheep\YsCartWooImport\Database\Transaction;
use YangSheep\YsCartWooImport\Packages\PackageReader;
// MediaSideloader 同 namespace（YsCart），無需 use。

final class ProductImporter
{
    private const YS_PRODUCT = '\YangSheep\Ecommerce\Models\YSProduct';

    public function importBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!class_exists(self::YS_PRODUCT)) {
            return ['done' => true, 'message' => 'YS CART product model is not available.'];
        }

        $filePath = (string)($options['file_path'] ?? '');
        if ($filePath === '') {
            return ['done' => true, 'message' => 'No package path provided.'];
        }

        $stage = (string)($cursor['stage'] ?? 'products');
        $entry = $stage === 'variants' ? 'product_variants.jsonl' : 'products.jsonl';
        $offset = (int)($cursor['offset'] ?? 0);
        $processed = 0;
        $success = 0;
        $limit = max(1, (int)($limits['max_rows'] ?? 50));
        $fingerprint = (string)($options['source_fingerprint'] ?? '');
        // v0.5.0 H1：圖片落地預設開啟；純匯出站或刻意保留外連時可帶 sideload_images=false 關閉。
        $sideloadImages = (bool)($options['sideload_images'] ?? true);

        try {
            $records = (new PackageReader())->streamJsonLines($filePath, $entry);
            foreach ($records as $index => $record) {
                if ($index < $offset) {
                    continue;
                }
                if ($processed >= $limit) {
                    break;
                }
                $processed++;

                try {
                    $id = $stage === 'variants'
                        ? $this->importVariant($jobId, $fingerprint, $record, $sideloadImages)
                        : $this->importProduct($jobId, $fingerprint, $record, $sideloadImages);
                    if ($id > 0) {
                        $success++;
                    }
                } catch (Throwable $e) {
                    (new ErrorRepository())->record($jobId, $stage === 'variants' ? 'variant' : 'product', (string)($record['source_id'] ?? ''), $e->getMessage(), $record);
                }
            }
        } catch (Throwable $e) {
            if ($stage === 'variants') {
                $this->saveCursor($jobId, $cursor, $offset, $processed, $success, true);
                return ['done' => true, 'processed' => 0, 'success' => 0, 'message' => 'No variants file.'];
            }

            throw $e;
        }

        $doneWithStage = $processed < $limit;
        if ($doneWithStage && $stage === 'products') {
            $this->saveCursor($jobId, $cursor, 0, 0, $success, false, 'variants');
            return ['done' => false, 'processed' => $processed, 'success' => $success, 'stage' => 'variants'];
        }

        $this->saveCursor($jobId, $cursor, $offset, $processed, $success, $doneWithStage, $stage);
        return ['done' => $doneWithStage, 'processed' => $processed, 'success' => $success, 'stage' => $stage];
    }

    private function importProduct(int $jobId, string $fingerprint, array $record, bool $sideloadImages = true): int
    {
        $class = self::YS_PRODUCT;
        $data = ProductMapper::mapProduct($record);

        // v0.5.0 H1：圖片在交易「之前」落地到本站媒體庫（網路 I/O 不可放進 DB 交易）。
        if ($sideloadImages) {
            $sideloader = new MediaSideloader();
            $data['image_url'] = $sideloader->resolveUrl($jobId, $fingerprint, (string)($data['image_url'] ?? ''));
            $data['gallery_urls'] = $sideloader->resolveUrls(
                $jobId,
                $fingerprint,
                is_array($data['gallery_urls'] ?? null) ? $data['gallery_urls'] : []
            );
        }

        // v0.5.0 C1：商品 + 屬性 + map 原子化（任一步失敗 ROLLBACK、整筆不落地）。
        return Transaction::run(function () use ($jobId, $fingerprint, $record, $class, $data): int {
            $existing = null;

            if ($data['sku'] !== '' && method_exists($class, 'find_by_sku')) {
                $existing = $class::find_by_sku($data['sku']);
            }

            if (!$existing && $data['slug'] !== '' && method_exists($class, 'find_by_slug')) {
                $existing = $class::find_by_slug($data['slug']);
            }

            if ($existing) {
                $class::update((int)$existing->id, $data);
                $productId = (int)$existing->id;
            } else {
                $productId = (int)$class::create($data);
            }

            if ($productId <= 0) {
                throw new \RuntimeException('Unable to create/update YS CART product.');
            }

            $this->replaceAttributes($productId, is_array($record['attributes'] ?? null) ? $record['attributes'] : []);
            (new MapRepository())->upsert($jobId, $fingerprint, 'product', (string)$record['source_id'], $productId, 'ys_product');

            return $productId;
        });
    }

    private function importVariant(int $jobId, string $fingerprint, array $record, bool $sideloadImages = true): int
    {
        global $wpdb;

        $map = (new MapRepository())->find($fingerprint, 'product', (string)($record['source_parent_id'] ?? ''));
        if (!$map) {
            throw new \RuntimeException('Parent product map missing.');
        }

        $variantData = ProductMapper::mapVariant($record);
        $variantData['product_id'] = (int)$map->target_id;
        $variantData['attributes'] = wp_json_encode($variantData['attributes']);

        // v0.5.0 H1：變體圖片同樣在交易前落地。
        if ($sideloadImages) {
            $variantData['image_url'] = (new MediaSideloader())->resolveUrl(
                $jobId,
                $fingerprint,
                (string)($variantData['image_url'] ?? '')
            );
        }

        $table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'product_variants';

        // v0.5.0 C1/H3：變體 insert/update + map 原子化。修「insert 成功但 map upsert 失敗 →
        // 重跑查無 variant map → 再插入一筆重複變體」的窗口。
        return Transaction::run(function () use ($jobId, $fingerprint, $record, $variantData, $table, $wpdb): int {
            $data = $variantData;
            $data['updated_at'] = current_time('mysql');

            $existing = (new MapRepository())->find($fingerprint, 'variant', (string)$record['source_id']);
            if ($existing) {
                $wpdb->update($table, $data, ['id' => (int)$existing->target_id]);
                $variantId = (int)$existing->target_id;
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($table, $data);
                $variantId = (int)$wpdb->insert_id;
            }

            if ($variantId <= 0) {
                throw new \RuntimeException('Unable to create/update YS CART variant.');
            }

            (new MapRepository())->upsert($jobId, $fingerprint, 'variant', (string)$record['source_id'], $variantId, 'ys_variant');
            return $variantId;
        });
    }

    private function replaceAttributes(int $productId, array $attributes): void
    {
        global $wpdb;

        if ($attributes === []) {
            return;
        }

        $table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'product_attributes';
        $wpdb->delete($table, ['product_id' => $productId]);

        $sort = 0;
        foreach ($attributes as $attribute) {
            $values = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
            $wpdb->insert($table, [
                'product_id' => $productId,
                'attribute_name' => (string)($attribute['name'] ?? ''),
                'attribute_values' => wp_json_encode(array_values($values)),
                'display_type' => 'pill',
                'sort_order' => $sort++,
            ]);
        }
    }

    private function saveCursor(int $jobId, array $cursor, int $offset, int $processed, int $success, bool $done, string $stage = 'products'): void
    {
        $newOffset = $done ? $offset + $processed : $offset + $processed;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $success,
            'error_count' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);
        $repo->updateCursor($jobId, [
            'stage' => $stage,
            'offset' => $newOffset,
            'processed' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $success,
            'errors' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);
    }
}

