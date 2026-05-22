<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

use Throwable;
use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Database\MapRepository;
use YangSheep\YsCartWooImport\Packages\PackageReader;

final class OrderImporter
{
    private const YS_ORDER = '\YangSheep\Ecommerce\Models\YSOrder';
    private const YS_PRODUCT = '\YangSheep\Ecommerce\Models\YSProduct';

    public function importBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!class_exists(self::YS_ORDER) || !class_exists(self::YS_PRODUCT)) {
            return ['done' => true, 'message' => 'YS CART order/product models are not available.'];
        }

        $filePath = (string)($options['file_path'] ?? '');
        if ($filePath === '') {
            return ['done' => true, 'message' => 'No package path provided.'];
        }

        $offset = (int)($cursor['offset'] ?? 0);
        $processed = 0;
        $success = 0;
        $fingerprint = (string)($options['source_fingerprint'] ?? '');
        $limit = max(1, (int)($limits['max_rows'] ?? 50));

        foreach ((new PackageReader())->streamJsonLines($filePath, 'orders.jsonl') as $index => $record) {
            if ($index < $offset) {
                continue;
            }
            if ($processed >= $limit) {
                break;
            }

            $processed++;
            try {
                $orderId = $this->importOrder($jobId, $fingerprint, $record);
                if ($orderId > 0) {
                    $success++;
                }
            } catch (Throwable $e) {
                (new ErrorRepository())->record($jobId, 'order', (string)($record['source_id'] ?? ''), $e->getMessage(), $record);
            }
        }

        $newOffset = $offset + $processed;
        $done = $processed < $limit;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => $newOffset,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $success,
            'error_count' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);
        $repo->updateCursor($jobId, [
            'offset' => $newOffset,
            'success' => ((int)($cursor['success'] ?? 0)) + $success,
            'errors' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);

        return ['done' => $done, 'processed' => $processed, 'success' => $success];
    }

    private function importOrder(int $jobId, string $fingerprint, array $record): int
    {
        $mapRepo = new MapRepository();
        $sourceId = (string)($record['source_id'] ?? '');
        $orderSources = new OrderSourceRepository();
        $existingSourceOrderId = $orderSources->findOrderId($fingerprint, $sourceId);
        if ($existingSourceOrderId) {
            $mapRepo->upsert($jobId, $fingerprint, 'order', $sourceId, $existingSourceOrderId, 'ys_order', [
                'source_order_number' => (string)($record['number'] ?? ''),
                'source_table' => 'ys_ec_order_sources',
            ]);
            return $existingSourceOrderId;
        }

        $existingMap = $mapRepo->find($fingerprint, 'order', $sourceId);
        if ($existingMap) {
            // v0.2.1 nit B1：legacy map backfill 不驗證 target_id 仍對應有效 YS order。
            // 若該 YS order 已被人工刪除 / 屬於不同 fingerprint，upsertWooOrder 會碰到
            // uk_order_platform unique constraint、$wpdb->query 回 false → silent ignore、
            // import flow 不中斷。這是 race / 孤兒 map 的容忍策略、刻意不 throw。
            $orderSources->upsertWooOrder((int)$existingMap->target_id, $fingerprint, $record);
            return (int)$existingMap->target_id;
        }

        $customer = $this->resolveCustomer($jobId, $fingerprint, $record);
        $orderData = OrderMapper::mapOrder($record, $customer['customer_id'], $customer['user_id']);
        $orderClass = self::YS_ORDER;
        $orderId = (int)$orderClass::create($orderData);

        if ($orderId <= 0) {
            throw new \RuntimeException('Unable to create YS CART order.');
        }

        $this->preserveSourceCreatedAt($orderId, (string)($orderData['created_at'] ?? ''));

        $placeholderProductId = $this->ensurePlaceholderProduct();
        foreach ((array)($record['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $target = $this->resolveItemTarget($fingerprint, $item, $placeholderProductId);
            $orderClass::add_item($orderId, OrderMapper::mapItem($item, $target['product_id'], $target['variant_id']));
        }

        $mapRepo->upsert($jobId, $fingerprint, 'order', $sourceId, $orderId, 'ys_order', [
            'source_order_number' => (string)($record['number'] ?? ''),
        ]);
        $orderSources->upsertWooOrder($orderId, $fingerprint, $record);
        return $orderId;
    }

    private function resolveCustomer(int $jobId, string $fingerprint, array $record): array
    {
        $mapRepo = new MapRepository();
        $sourceCustomerId = (string)($record['customer_id'] ?? '');
        if ($sourceCustomerId !== '' && $sourceCustomerId !== '0') {
            $map = $mapRepo->find($fingerprint, 'customer', $sourceCustomerId);
            if ($map) {
                $extra = json_decode((string)$map->extra_json, true) ?: [];
                return [
                    'customer_id' => (int)$map->target_id,
                    'user_id' => (int)($extra['user_id'] ?? 0),
                ];
            }
        }

        $billing = is_array($record['billing'] ?? null) ? OrderMapper::mapAddress($record['billing']) : [];
        $email = strtolower((string)($record['billing_email'] ?? $billing['email'] ?? ''));
        if ($email === '') {
            $email = 'imported-order-' . (string)($record['source_id'] ?? wp_generate_uuid4()) . '@example.invalid';
        }

        return (new CustomerImporter())->ensureCustomerForEmail($jobId, $fingerprint, $email, $billing);
    }

    private function resolveItemTarget(string $fingerprint, array $item, int $placeholderProductId): array
    {
        $mapRepo = new MapRepository();
        $sourceProductId = (string)($item['source_product_id'] ?? '');
        $sourceVariationId = (string)($item['source_variation_id'] ?? '');
        $productId = $placeholderProductId;
        $variantId = null;

        if ($sourceProductId !== '') {
            $map = $mapRepo->find($fingerprint, 'product', $sourceProductId);
            if ($map) {
                $productId = (int)$map->target_id;
            }
        }

        if ($sourceVariationId !== '' && $sourceVariationId !== '0') {
            $map = $mapRepo->find($fingerprint, 'variant', $sourceVariationId);
            if ($map) {
                $variantId = (int)$map->target_id;
            }
        }

        return ['product_id' => $productId, 'variant_id' => $variantId];
    }

    private function ensurePlaceholderProduct(): int
    {
        $class = self::YS_PRODUCT;
        $sku = 'YS-WC-IMPORTED-ITEM';
        $existing = method_exists($class, 'find_by_sku') ? $class::find_by_sku($sku) : null;
        if ($existing) {
            return (int)$existing->id;
        }

        $id = (int)$class::create([
            'title' => 'WooCommerce Imported Item',
            'slug' => 'woocommerce-imported-item',
            'type' => 'simple',
            'status' => 'draft',
            'sku' => $sku,
            'price' => 0,
            'stock_qty' => -1,
            'is_virtual' => 1,
        ]);

        if ($id <= 0) {
            throw new \RuntimeException('Unable to create placeholder product for imported order items.');
        }

        return $id;
    }

    private function preserveSourceCreatedAt(int $orderId, string $createdAt): void
    {
        if ($createdAt === '' || !method_exists(self::YS_ORDER, 'table')) {
            return;
        }

        global $wpdb;
        $class = self::YS_ORDER;
        $wpdb->update($class::table(), ['created_at' => $createdAt], ['id' => $orderId]);
    }
}
