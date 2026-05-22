<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

final class OrderSourceRepository
{
    private const PLATFORM = 'woocommerce';
    private static ?bool $tableExists = null;

    public static function fingerprintForStorage(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);
        if (preg_match('/^[a-f0-9]{64}$/i', $fingerprint) === 1) {
            $binary = hex2bin(strtolower($fingerprint));
            if (is_string($binary) && strlen($binary) === 32) {
                return $binary;
            }
        }

        return hash('sha256', $fingerprint, true);
    }

    public static function normalizeOrderNumber(string $orderNumber): string
    {
        $normalized = preg_replace('/\s+/', '', trim($orderNumber));
        return strtolower((string)$normalized);
    }

    public function findOrderId(string $fingerprint, string $sourceOrderId): ?int
    {
        $sourceOrderId = trim($sourceOrderId);
        if ($sourceOrderId === '' || !$this->tableExists()) {
            return null;
        }

        global $wpdb;
        $table = $this->table();
        $orderId = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$table} WHERE source_platform = %s AND source_site_fingerprint = UNHEX(%s) AND source_order_id = %s LIMIT 1",
            self::PLATFORM,
            $this->fingerprintHex($fingerprint),
            $sourceOrderId
        ));

        return $orderId ? (int)$orderId : null;
    }

    /**
     * Insert 一筆 source identity row（給剛 import 完成的 Woo order 用）。
     *
     * 用 INSERT ... ON DUPLICATE KEY UPDATE 對抗 uk_source_order 跟 uk_order_platform
     * unique constraint、race-safe。
     *
     * **v0.2.1 nit B3 PII 注意**：source_meta 內含 billing_email（PII）。雖然 YS order
     * 本身已存 email、不算二次洩漏、但 GDPR 刪除使用者訂單時需**一併清此表**對應 row、
     * 否則 source_meta 會殘留 email。建議未來 YS core 提供 hook 讓 import plugin 在
     * order 刪除時 cascade delete source row（或於 ys_ec_orders ON DELETE 之外用
     * application-level 監聽 ys_ec_order_deleted action）。
     */
    public function upsertWooOrder(int $orderId, string $fingerprint, array $record): void
    {
        if ($orderId <= 0 || !$this->tableExists()) {
            return;
        }

        $sourceOrderId = trim((string)($record['source_id'] ?? ''));
        if ($sourceOrderId === '') {
            return;
        }

        global $wpdb;
        $table = $this->table();
        $now = current_time('mysql');
        $sourceOrderNumber = trim((string)($record['number'] ?? $sourceOrderId));
        $sourceMeta = [
            'status' => (string)($record['status'] ?? ''),
            'currency' => (string)($record['currency'] ?? ''),
            'billing_email' => (string)($record['billing_email'] ?? ''),
        ];

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (order_id, source_platform, source_site_fingerprint, source_order_id, source_order_number, source_order_number_norm, source_created_at, imported_at, source_meta, created_at, updated_at)
             VALUES
                (%d, %s, UNHEX(%s), %s, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                order_id = VALUES(order_id),
                source_site_fingerprint = VALUES(source_site_fingerprint),
                source_order_id = VALUES(source_order_id),
                source_order_number = VALUES(source_order_number),
                source_order_number_norm = VALUES(source_order_number_norm),
                source_created_at = VALUES(source_created_at),
                imported_at = VALUES(imported_at),
                source_meta = VALUES(source_meta),
                updated_at = VALUES(updated_at)",
            $orderId,
            self::PLATFORM,
            $this->fingerprintHex($fingerprint),
            $sourceOrderId,
            $sourceOrderNumber,
            self::normalizeOrderNumber($sourceOrderNumber),
            $this->mysqlDate((string)($record['created_at'] ?? '')),
            $now,
            wp_json_encode($sourceMeta),
            $now,
            $now
        ));
    }

    private function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        global $wpdb;
        if (!is_object($wpdb) || empty($wpdb->prefix)) {
            self::$tableExists = false;
            return false;
        }

        $table = $this->table();
        self::$tableExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return self::$tableExists;
    }

    private function table(): string
    {
        global $wpdb;
        $ysPrefix = defined('YS_ECOMMERCE_TABLE_PREFIX') ? (string)YS_ECOMMERCE_TABLE_PREFIX : 'ys_ec_';
        return $wpdb->prefix . $ysPrefix . 'order_sources';
    }

    private function fingerprintHex(string $fingerprint): string
    {
        return bin2hex(self::fingerprintForStorage($fingerprint));
    }

    /**
     * Source-created-at 字串 → MySQL DATETIME。
     *
     * v0.2.1 nit B2 修正：之前 `strtotime($date)` 對無 tz 字串依賴 server tz、
     * gmdate 又強制 UTC、parse 跟 output 不一致。改用 DateTimeImmutable + UTC
     * fallback、無 tz 字串視同 UTC（manifest 慣例）、有 tz 字串保留語意。
     *
     * 用 wp_date 不適合（wp_date 是 site tz format 用、不是 normalize）；
     * 我們要的是「source 時間語意保留、儲存統一 UTC」。
     */
    private function mysqlDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }

        try {
            // 沒帶 tz 的字串視同 UTC（避免 PHP server tz 飄移）
            $dt = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            // Malformed date string → 保守 skip、不寫入垃圾 DATETIME
            return null;
        }
    }
}
