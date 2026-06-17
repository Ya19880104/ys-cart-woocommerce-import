<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

use Throwable;
use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\MapRepository;

final class DownloadPermissionBackfiller
{
    public function backfillFromOrderRecord(int $jobId, string $fingerprint, int $orderId, array $record): void
    {
        $permissions = is_array($record['download_permissions'] ?? null) ? $record['download_permissions'] : [];
        if ($permissions === [] || $orderId <= 0) {
            return;
        }

        foreach ($permissions as $permission) {
            if (!is_array($permission)) {
                continue;
            }

            try {
                $this->backfillOne($jobId, $fingerprint, $orderId, $permission);
            } catch (Throwable $e) {
                (new ErrorRepository())->record(
                    $jobId,
                    'download_permission',
                    (string)($permission['source_permission_id'] ?? ''),
                    $e->getMessage(),
                    $permission
                );
            }
        }
    }

    private function backfillOne(int $jobId, string $fingerprint, int $orderId, array $permission): void
    {
        global $wpdb;

        $sourceProductId = (string)($permission['product_id'] ?? '');
        $sourceDownloadId = (string)($permission['download_id'] ?? '');
        $sourcePermissionId = (string)($permission['source_permission_id'] ?? '');
        if ($sourceProductId === '' || $sourceDownloadId === '') {
            return;
        }

        $mapSourceId = $sourceProductId . ':' . $sourceDownloadId;
        $fileMap = (new MapRepository())->find($fingerprint, 'digital_file', $mapSourceId);
        if (!$fileMap) {
            (new ErrorRepository())->record(
                $jobId,
                'download_permission',
                $sourcePermissionId,
                'YS CART digital file map is missing for Woo source_download_id.',
                $permission + ['source_download_id' => $sourceDownloadId]
            );
            return;
        }

        $prefix = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, customer_id FROM {$prefix}orders WHERE id = %d",
            $orderId
        ));
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT id, product_id FROM {$prefix}digital_files WHERE id = %d",
            (int)$fileMap->target_id
        ));
        if (!$order || !$file) {
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}download_permissions WHERE order_id = %d AND file_id = %d LIMIT 1",
            $orderId,
            (int)$file->id
        ));
        if ($existing) {
            return;
        }

        $downloadCount = max(0, (int)($permission['download_count'] ?? 0));
        $downloadLimit = $this->downloadLimit((string)($permission['downloads_remaining'] ?? ''), $downloadCount);
        $expiresAt = $this->mysqlDate((string)($permission['access_expires'] ?? ''));
        $createdAt = $this->mysqlDate((string)($permission['access_granted'] ?? '')) ?: current_time('mysql');
        $status = ($expiresAt !== null && strtotime($expiresAt) < time()) ? 'expired' : 'active';

        $wpdb->insert(
            "{$prefix}download_permissions",
            [
                'customer_id' => (int)$order->customer_id,
                'order_id' => $orderId,
                'product_id' => (int)$file->product_id,
                'file_id' => (int)$file->id,
                'access_type' => $expiresAt !== null ? 'timed' : 'download',
                'download_limit' => $downloadLimit,
                'download_count' => $downloadCount,
                'expires_at' => $expiresAt,
                'status' => $status,
                'access_token' => wp_generate_password(48, false),
                'created_at' => $createdAt,
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function downloadLimit(string $remaining, int $downloadCount): int
    {
        $remaining = trim($remaining);
        if ($remaining === '' || strtolower($remaining) === 'unlimited') {
            return -1;
        }

        return max(0, (int)$remaining + $downloadCount);
    }

    private function mysqlDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($date);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }
}
