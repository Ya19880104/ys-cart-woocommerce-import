<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Backups\SqlBackupManager;

final class BackupController
{
    public function list(): array
    {
        return [
            'backups' => (new SqlBackupManager())->list(),
        ];
    }

    public function create()
    {
        try {
            return [
                'backup' => (new SqlBackupManager())->create(),
            ];
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ys_cwci_backup_create_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function delete($request)
    {
        $file = $this->fileFromRequest($request);
        if (!(new SqlBackupManager())->delete($file)) {
            return new \WP_Error(
                'ys_cwci_backup_delete_failed',
                __('備份不存在或無法刪除。', 'ys-cart-woocommerce-import'),
                ['status' => 404]
            );
        }

        return ['deleted' => true, 'file' => $file];
    }

    public function restore($request)
    {
        $file = $this->fileFromRequest($request);
        $confirm = method_exists($request, 'get_param') ? (string)$request->get_param('confirm') : '';

        try {
            return [
                'restored' => (new SqlBackupManager())->restore($file, $confirm),
            ];
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ys_cwci_backup_restore_failed',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    public function download($request): void
    {
        $file = $this->fileFromRequest($request);
        $path = (new SqlBackupManager())->safeBackupPath($file);
        if ($path === null) {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function fileFromRequest($request): string
    {
        return method_exists($request, 'get_param') ? sanitize_file_name((string)$request->get_param('file')) : '';
    }
}
