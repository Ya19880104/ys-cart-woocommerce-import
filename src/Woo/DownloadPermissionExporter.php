<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Woo;

defined('ABSPATH') || exit;

final class DownloadPermissionExporter
{
    public function forOrder(int $orderId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT permission_id, download_id, product_id, order_id, downloads_remaining, access_granted, access_expires, download_count
             FROM {$table}
             WHERE order_id = %d
             ORDER BY permission_id ASC",
            $orderId
        ), ARRAY_A);

        return is_array($rows) ? array_map(static function (array $row): array {
            return [
                'source_permission_id' => (string)($row['permission_id'] ?? ''),
                'download_id' => (string)($row['download_id'] ?? ''),
                'product_id' => (string)($row['product_id'] ?? ''),
                'downloads_remaining' => (string)($row['downloads_remaining'] ?? ''),
                'access_granted' => (string)($row['access_granted'] ?? ''),
                'access_expires' => (string)($row['access_expires'] ?? ''),
                'download_count' => (int)($row['download_count'] ?? 0),
            ];
        }, $rows) : [];
    }
}
