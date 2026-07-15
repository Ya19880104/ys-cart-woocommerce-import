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
        // v0.7.0：mode=skip 時既有商品（SKU/slug 命中）完全不更新（僅補 map）；
        // 預設 update＝沿用既有「找到就更新」行為（商品的覆蓋語意本來就是更新）。
        $mode = (string)($options['mode'] ?? 'update');

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
                        : $this->importProduct($jobId, $fingerprint, $record, $sideloadImages, $mode);
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

    private function importProduct(int $jobId, string $fingerprint, array $record, bool $sideloadImages = true, string $mode = 'update'): int
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
        return Transaction::run(function () use ($jobId, $fingerprint, $record, $class, $data, $mode): int {
            $existing = null;

            if ($data['sku'] !== '' && method_exists($class, 'find_by_sku')) {
                $existing = $class::find_by_sku($data['sku']);
            }

            if (!$existing && $data['slug'] !== '' && method_exists($class, 'find_by_slug')) {
                $existing = $class::find_by_slug($data['slug']);
            }

            if ($existing) {
                // v0.7.0 mode=skip：既有商品完全不動（僅補 map）；其餘模式照舊更新。
                if ('skip' === $mode) {
                    (new MapRepository())->upsert($jobId, $fingerprint, 'product', (string)$record['source_id'], (int)$existing->id, 'ys_product');
                    return (int)$existing->id;
                }
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
            $this->syncDigitalFiles($jobId, $fingerprint, $productId, null, $record);

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
            $this->syncDigitalFiles($jobId, $fingerprint, (int)$variantData['product_id'], $variantId, $record);
            return $variantId;
        });
    }

    private function syncDigitalFiles(int $jobId, string $fingerprint, int $productId, ?int $variantId, array $record): void
    {
        global $wpdb;

        $downloads = is_array($record['downloads'] ?? null) ? $record['downloads'] : [];
        if ($downloads === []) {
            return;
        }

        $table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'digital_files';
        $mapRepo = new MapRepository();
        $sourceProductId = (string)($record['source_id'] ?? '');

        foreach ($downloads as $download) {
            if (!is_array($download)) {
                continue;
            }

            $sourceDownloadId = (string)($download['source_download_id'] ?? '');
            if ($sourceDownloadId === '') {
                $sourceDownloadId = sha1((string)($download['file'] ?? wp_json_encode($download)));
            }

            $mapSourceId = $sourceProductId . ':' . $sourceDownloadId;
            $file = $this->resolveDigitalFileReference($download, $sourceProductId, $sourceDownloadId);
            if ($file === null) {
                (new ErrorRepository())->record($jobId, 'digital_file', $mapSourceId, 'Woo downloadable file is missing or cannot be resolved.', $download);
                continue;
            }

            $now = current_time('mysql');
            $data = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'file_name' => $file['file_name'],
                'file_type' => $file['file_type'],
                'file_path' => $file['file_path'],
                'file_url' => $file['file_url'],
                'file_size' => $file['file_size'],
                'file_hash' => $file['file_hash'],
                'mime_type' => $file['mime_type'],
                'version' => '1.0.0',
                'is_active' => 1,
                'updated_at' => $now,
            ];

            $existing = $mapRepo->find($fingerprint, 'digital_file', $mapSourceId);
            if ($existing) {
                $wpdb->update($table, $data, ['id' => (int)$existing->target_id]);
                $fileId = (int)$existing->target_id;
            } else {
                $data['created_at'] = $now;
                $wpdb->insert($table, $data);
                $fileId = (int)$wpdb->insert_id;
            }

            if ($fileId <= 0) {
                (new ErrorRepository())->record($jobId, 'digital_file', $mapSourceId, 'Unable to create/update YS CART digital file.', $download);
                continue;
            }

            $mapRepo->upsert($jobId, $fingerprint, 'digital_file', $mapSourceId, $fileId, 'ys_digital_file', [
                'source_download_id' => $sourceDownloadId,
                'source_file' => (string)($download['file'] ?? ''),
                'source_product_id' => $sourceProductId,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ]);
        }
    }

    private function resolveDigitalFileReference(array $download, string $sourceProductId, string $sourceDownloadId): ?array
    {
        $source = trim((string)($download['file'] ?? ''));
        if ($source === '') {
            return null;
        }

        $displayName = trim((string)($download['file_name'] ?? $download['name'] ?? ''));
        $pathName = basename((string)(parse_url($source, PHP_URL_PATH) ?: $source));
        $fileName = $this->sanitizeFileName($displayName !== '' ? $displayName : $pathName);
        if ($fileName === '') {
            $fileName = $this->sanitizeFileName($sourceDownloadId ?: 'download');
        }

        $localPath = $this->sourceFileToLocalPath($source);
        if ($localPath !== '' && is_file($localPath) && is_readable($localPath)) {
            $stored = $this->copyToDigitalStorage($localPath, $sourceProductId, $sourceDownloadId, $fileName);
            if ($stored === null) {
                return null;
            }

            $mime = function_exists('wp_check_filetype') ? wp_check_filetype($stored['path'], null) : ['type' => ''];
            return [
                'file_name' => $fileName,
                'file_type' => 'local',
                'file_path' => $stored['key'],
                'file_url' => null,
                'file_size' => is_file($stored['path']) ? filesize($stored['path']) : null,
                'file_hash' => is_file($stored['path']) ? hash_file('sha256', $stored['path']) : null,
                'mime_type' => (string)($mime['type'] ?? ''),
            ];
        }

        if (preg_match('#^https?://#i', $source)) {
            return [
                'file_name' => $fileName,
                'file_type' => 'url',
                'file_path' => null,
                'file_url' => $source,
                'file_size' => null,
                'file_hash' => null,
                'mime_type' => '',
            ];
        }

        return null;
    }

    private function sourceFileToLocalPath(string $source): string
    {
        $path = $source;
        if (preg_match('#^https?://#i', $source)) {
            $sourcePath = (string)parse_url($source, PHP_URL_PATH);
            if ($sourcePath === '') {
                return '';
            }
            $path = ltrim(rawurldecode($sourcePath), '/');
        }

        $path = str_replace('\\', '/', $path);
        if (is_file($path)) {
            return $path;
        }

        $candidates = [];
        if (defined('ABSPATH')) {
            $candidates[] = rtrim((string)ABSPATH, '/\\') . '/' . ltrim($path, '/');
        }
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = rtrim((string)WP_CONTENT_DIR, '/\\') . '/' . ltrim(preg_replace('#^wp-content/#', '', $path), '/');
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return array{key:string,path:string}|null
     */
    private function copyToDigitalStorage(string $localPath, string $sourceProductId, string $sourceDownloadId, string $fileName): ?array
    {
        if (!class_exists('\YangSheep\Ecommerce\Storage\YSProtectedStorage')) {
            return null;
        }

        $storage = \YangSheep\Ecommerce\Storage\YSProtectedStorage::digital();
        $dir = $storage->ensure();
        if ($dir === '') {
            return null;
        }

        $prefix = $this->sanitizeFileName($sourceProductId . '-' . $sourceDownloadId);
        $targetName = function_exists('wp_unique_filename')
            ? wp_unique_filename($dir, $prefix . '-' . $fileName)
            : $prefix . '-' . $fileName;
        $targetPath = rtrim($dir, '/\\') . '/' . $targetName;

        if (!copy($localPath, $targetPath)) {
            return null;
        }

        return ['key' => $targetName, 'path' => $targetPath];
    }

    private function sanitizeFileName(string $name): string
    {
        return function_exists('sanitize_file_name')
            ? sanitize_file_name($name)
            : preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
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
        $repo->updateProgressAndCursor($jobId, [
            'processed_count' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $success,
            'error_count' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ], [
            'stage' => $stage,
            'offset' => $newOffset,
            'processed' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $success,
            'errors' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);
    }
}
