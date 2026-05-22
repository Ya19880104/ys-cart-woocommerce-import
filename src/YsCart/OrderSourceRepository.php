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

    private function mysqlDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }
}
