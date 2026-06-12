<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Capabilities\CapabilityDetector;
use YangSheep\YsCartWooImport\Rest\BackupController;

final class RestController
{
    public const NAMESPACE = 'ys-cart-wc-import/v1';

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'capabilities'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/export-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createExportJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/import-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createImportJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'listJobs'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/direct-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createDirectJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/run-next', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'runNext'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'getJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/errors', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'getErrors'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'cancel'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/download', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'download'],
            // v0.2.4 Reviewer #3 fix: GET endpoint 改用 adminDownload (要求 nonce-query-arg)
            // 防 CSRF — `<iframe src="/.../download">` 借 admin session 觸發 readfile()
            'permission_callback' => [Permission::class, 'adminDownload'],
        ]);

        register_rest_route(self::NAMESPACE, '/packages/upload', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'upload'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/packages/(?P<id>\d+)/preview', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'preview'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        // v0.7.0：訂單狀態掃描（狀態選擇 + 未對應詢問 UI 的資料來源）
        register_rest_route(self::NAMESPACE, '/packages/order-statuses', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'orderStatuses'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/backups', [
            'methods' => 'GET',
            'callback' => [new BackupController(), 'list'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/backups', [
            'methods' => 'POST',
            'callback' => [new BackupController(), 'create'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/backups/(?P<file>[A-Za-z0-9._-]+)', [
            'methods' => 'DELETE',
            'callback' => [new BackupController(), 'delete'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/backups/(?P<file>[A-Za-z0-9._-]+)/restore', [
            'methods' => 'POST',
            'callback' => [new BackupController(), 'restore'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/backups/(?P<file>[A-Za-z0-9._-]+)/download', [
            'methods' => 'GET',
            'callback' => [new BackupController(), 'download'],
            'permission_callback' => [Permission::class, 'adminDownload'],
        ]);
    }

    public function capabilities(): array
    {
        return (new CapabilityDetector())->getCapabilities();
    }
}
