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
        $skipped = 0;
        $fingerprint = (string)($options['source_fingerprint'] ?? '');
        $limit = max(1, (int)($limits['max_rows'] ?? 50));

        // v0.7.0：匯入模式與訂單狀態控制。
        // mode: skip（預設＝既有訂單只回填不覆蓋）| overwrite（覆蓋＝更新欄位並重建品項）。
        // status_include: 勾選要匯入的 Woo 狀態（空＝全部）。
        // status_map: 使用者自訂 Woo→YS 狀態對應（值已在 JobController 驗證過白名單）。
        $mode = (string)($options['mode'] ?? 'skip');
        $statusInclude = array_values(array_filter(array_map('strval', (array)($options['status_include'] ?? []))));
        $statusMap = is_array($options['status_map'] ?? null) ? $options['status_map'] : [];

        // v0.5.0 M1：首批時用 manifest 的 entity 計數設定進度條分母（單階段、可精準顯示 %）。
        if ($offset === 0) {
            $this->primeTotalCount($jobId, $filePath, 'orders');
        }

        foreach ((new PackageReader())->streamJsonLines($filePath, 'orders.jsonl') as $index => $record) {
            if ($index < $offset) {
                continue;
            }
            if ($processed >= $limit) {
                break;
            }

            $processed++;

            // 狀態過濾：不在勾選清單的訂單跳過（不算錯誤，offset 仍前進避免重讀）。
            if ($statusInclude !== [] && !in_array((string)($record['status'] ?? ''), $statusInclude, true)) {
                $skipped++;
                continue;
            }

            try {
                $orderId = $this->importOrder($jobId, $fingerprint, $record, $mode, $statusMap);
                if ($orderId > 0) {
                    $success++;
                }
            } catch (Throwable $e) {
                (new ErrorRepository())->record($jobId, 'order', (string)($record['source_id'] ?? ''), $e->getMessage(), $record);
            }
        }

        $newOffset = $offset + $processed;
        $done = $processed < $limit;
        $totalSkipped = ((int)($cursor['skipped'] ?? 0)) + $skipped;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => $newOffset,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $success,
            'error_count' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success - $skipped),
        ]);
        $repo->updateCursor($jobId, [
            'offset' => $newOffset,
            'success' => ((int)($cursor['success'] ?? 0)) + $success,
            'errors' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success - $skipped),
            'skipped' => $totalSkipped,
        ]);

        return ['done' => $done, 'processed' => $processed, 'success' => $success, 'skipped' => $skipped];
    }

    /**
     * 盡力而為地用 manifest 的 entity 計數設定 total_count；拿不到就讓進度條 fallback、不影響匯入。
     */
    private function primeTotalCount(int $jobId, string $filePath, string $entity): void
    {
        try {
            $manifest = (new PackageReader())->readManifest($filePath);
            $total = (int)($manifest['entities'][$entity] ?? 0);
            if ($total > 0) {
                (new JobRepository())->updateProgress($jobId, ['total_count' => $total]);
            }
        } catch (Throwable $e) {
            // manifest 缺失/無效不阻擋匯入。
        }
    }

    /**
     * @param string               $mode      'skip'（預設）或 'overwrite'。
     * @param array<string,string> $statusMap 使用者自訂狀態對應。
     */
    private function importOrder(int $jobId, string $fingerprint, array $record, string $mode = 'skip', array $statusMap = []): int
    {
        $mapRepo = new MapRepository();
        $sourceId = (string)($record['source_id'] ?? '');
        $orderSources = new OrderSourceRepository();
        $existingSourceOrderId = $orderSources->findOrderId($fingerprint, $sourceId);
        if ($existingSourceOrderId) {
            if ('overwrite' === $mode) {
                // v0.7.0 覆蓋模式：以套件資料更新既有訂單欄位並重建品項。
                $this->overwriteOrder($jobId, $fingerprint, $existingSourceOrderId, $record, $statusMap);
            } else {
                $this->backfillExistingOrderShipping($existingSourceOrderId, $record);
            }
            $mapRepo->upsert($jobId, $fingerprint, 'order', $sourceId, $existingSourceOrderId, 'ys_order', [
                'source_order_number' => (string)($record['number'] ?? ''),
                'source_table' => 'ys_ec_order_sources',
            ]);
            (new DownloadPermissionBackfiller())->backfillFromOrderRecord($jobId, $fingerprint, $existingSourceOrderId, $record);
            return $existingSourceOrderId;
        }

        $existingMap = $mapRepo->find($fingerprint, 'order', $sourceId);
        if ($existingMap) {
            if ('overwrite' === $mode) {
                $this->overwriteOrder($jobId, $fingerprint, (int)$existingMap->target_id, $record, $statusMap);
            } else {
                $this->backfillExistingOrderShipping((int)$existingMap->target_id, $record);
            }
            // v0.2.1 nit B1：legacy map backfill 不驗證 target_id 仍對應有效 YS order。
            // 若該 YS order 已被人工刪除 / 屬於不同 fingerprint，upsertWooOrder 會碰到
            // uk_order_platform unique constraint、$wpdb->query 回 false → silent ignore、
            // import flow 不中斷。這是 race / 孤兒 map 的容忍策略、刻意不 throw。
            $orderSources->upsertWooOrder((int)$existingMap->target_id, $fingerprint, $record);
            (new DownloadPermissionBackfiller())->backfillFromOrderRecord($jobId, $fingerprint, (int)$existingMap->target_id, $record);
            return (int)$existingMap->target_id;
        }

        // 客戶與占位商品在交易外解析：兩者皆冪等（依 email / SKU 去重），且 wp_insert_user
        // 等動作有 DB 以外的副作用，不適合被 ROLLBACK 連帶回滾。
        $customer = $this->resolveCustomer($jobId, $fingerprint, $record);
        $placeholderProductId = $this->ensurePlaceholderProduct();
        $orderClass = self::YS_ORDER;

        // v0.5.0 C1：訂單 + 品項 + map + source 原子化。任一步失敗即 ROLLBACK、整筆不落地，
        // 下次重跑由 dedup 乾淨地只建立一次（修「中途失敗 → 重複訂單」）。
        return Transaction::run(function () use ($jobId, $fingerprint, $record, $sourceId, $customer, $placeholderProductId, $orderClass, $mapRepo, $orderSources, $statusMap): int {
            $orderData = OrderMapper::mapOrder($record, $customer['customer_id'], $customer['user_id'], $statusMap);
            // v0.7.0 跨站整合：orders 表 uk_order_number 唯一鍵 — 不同來源站可能有相同
            // Woo 單號（WC-1001）。撞號時以來源站短指紋後綴（WC-1001-a1b2c3）區隔，
            // 決定性產生 → 重跑仍冪等（dedup 走 source/map，不經單號）。
            $orderData['order_number'] = $this->uniqueOrderNumber((string)($orderData['order_number'] ?? ''), $fingerprint);
            $orderId = (int)$orderClass::create($orderData);

            if ($orderId <= 0) {
                throw new \RuntimeException('Unable to create YS CART order.');
            }

            $this->preserveSourceCreatedAt($orderId, (string)($orderData['created_at'] ?? ''));

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
            (new DownloadPermissionBackfiller())->backfillFromOrderRecord($jobId, $fingerprint, $orderId, $record);

            return $orderId;
        });
    }

    /**
     * v0.7.0 跨站整合：order_number 撞號時回傳「單號-來源站短指紋」。
     *
     * 同站重匯不會走到這裡（dedup 先攔）；不同來源站同單號 → 各自帶不同短指紋、
     * 同一來源站重試 → 後綴決定性相同。後綴版仍撞（理論邊界）則拋例外記錄為該筆錯誤。
     */
    private function uniqueOrderNumber(string $number, string $fingerprint): string
    {
        if ($number === '') {
            return $number;
        }

        global $wpdb;
        $class = self::YS_ORDER;
        $table = $class::table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE order_number = %s LIMIT 1", $number));
        if (empty($exists)) {
            return $number;
        }

        $suffixed = $number . '-' . substr($fingerprint, 0, 6);
        $existsSuffixed = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE order_number = %s LIMIT 1", $suffixed));
        if (!empty($existsSuffixed)) {
            throw new \RuntimeException('Order number collision even with source suffix: ' . $suffixed);
        }

        return $suffixed;
    }

    /**
     * v0.7.0 覆蓋模式：以套件資料更新既有訂單。
     *
     * 更新 mapOrder 的全部欄位（排除 order_number＝保留 YS 單號、created_at＝保留原建單時間），
     * 並刪除舊品項後依套件重建。整段包在交易內，任一步失敗整筆回滾。
     * 客戶關聯沿用既有 resolveCustomer（冪等）。
     */
    private function overwriteOrder(int $jobId, string $fingerprint, int $orderId, array $record, array $statusMap): void
    {
        if ($orderId <= 0 || !method_exists(self::YS_ORDER, 'table')) {
            return;
        }

        $customer = $this->resolveCustomer($jobId, $fingerprint, $record);
        $placeholderProductId = $this->ensurePlaceholderProduct();
        $orderClass = self::YS_ORDER;

        Transaction::run(function () use ($orderId, $record, $customer, $placeholderProductId, $orderClass, $statusMap, $fingerprint): void {
            global $wpdb;

            $data = OrderMapper::mapOrder($record, $customer['customer_id'], $customer['user_id'], $statusMap);
            unset($data['order_number'], $data['created_at']); // 保留 YS 單號與原建單時間

            // 鏡射核心 YSOrder::create 的 JSON 欄位序列化（直接 $wpdb->update 不會自動編碼）。
            foreach (['payment_detail', 'discount_ids'] as $jsonField) {
                if (isset($data[$jsonField]) && is_array($data[$jsonField])) {
                    $data[$jsonField] = wp_json_encode($data[$jsonField]);
                }
            }
            $data['updated_at'] = current_time('mysql');

            $table = $orderClass::table();
            $updated = $wpdb->update($table, $data, ['id' => $orderId]);
            if (false === $updated) {
                throw new \RuntimeException('Unable to overwrite YS CART order #' . $orderId . '.');
            }

            // 重建品項：刪舊（同核心 delete 的品項清理 pattern）→ 依套件重加。
            //
            // 金額權威性（與建立路徑一致、刻意不重算）：表頭金額（subtotal / total /
            // shipping_fee / discount …）一律採上面 mapOrder 寫入的套件值 —— 那是來源站
            // （Woo）結算後的權威金額，已含運費 / 折扣 / 稅，並非品項單純加總。核心
            // YSOrder::add_item 是純 INSERT、不回算表頭，故下方重建品項不會改動已寫入的金額。
            // ⚠ 切勿改成「依重建後的品項重算 total」：會丟失運費 / 折扣 / 稅，且與 importOrder
            //   建立路徑（同樣信任 mapOrder 金額）產生不一致。
            $itemsTable = method_exists($orderClass, 'items_table')
                ? $orderClass::items_table()
                : $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'order_items';
            $wpdb->delete($itemsTable, ['order_id' => $orderId]);

            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $target = $this->resolveItemTarget($fingerprint, $item, $placeholderProductId);
                $orderClass::add_item($orderId, OrderMapper::mapItem($item, $target['product_id'], $target['variant_id']));
            }
        });
    }

    private function backfillExistingOrderShipping(int $orderId, array $record): void
    {
        if ($orderId <= 0 || !method_exists(self::YS_ORDER, 'table')) {
            return;
        }

        $mapped = OrderMapper::mapOrder($record, 0, 0);
        $incoming = [
            'shipping_provider' => (string)($mapped['shipping_provider'] ?? ''),
        ];
        if ($incoming['shipping_provider'] === '') {
            return;
        }

        global $wpdb;
        $class = self::YS_ORDER;
        $table = $class::table();
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT shipping_provider FROM {$table} WHERE id = %d",
            $orderId
        ), ARRAY_A);
        if (!is_array($current)) {
            return;
        }

        $updates = [];
        foreach ($incoming as $field => $value) {
            if ($value !== '' && trim((string)($current[$field] ?? '')) === '') {
                $updates[$field] = $value;
            }
        }

        if ($updates !== []) {
            $updates['updated_at'] = current_time('mysql');
            $wpdb->update($table, $updates, ['id' => $orderId]);
        }
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
