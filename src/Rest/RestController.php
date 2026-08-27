<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Capabilities\CapabilityDetector;
use YangSheep\YsCartWooImport\Rest\BackupController;

final class RestController
{
    public const NAMESPACE = 'ys-cart-wc-import/v1';
    private const CORE_ROUTE_PREFIX = '/admin/woocommerce-import';
    private const CORE_REGISTRAR = '\\YangSheep\\Ecommerce\\Api\\Admin\\YSAdminRouteRegistrar';

    private bool $routesRegistered = false;

    public function registerCoreRoutes(string $registrar = ''): void
    {
        if ($this->routesRegistered || !class_exists($registrar) || !defined($registrar . '::NAMESPACE')) {
            return;
        }

        $namespace = $registrar::NAMESPACE;
        if (!is_string($namespace) || trim($namespace, '/') === '') {
            return;
        }

        $this->registerRoutes(trim($namespace, '/'), self::CORE_ROUTE_PREFIX);
    }

    public function registerFallbackRoutes(): void
    {
        if ($this->routesRegistered) {
            return;
        }

        $this->registerRoutes(self::NAMESPACE, '');
    }

    public static function apiPath(): string
    {
        $registrar = self::CORE_REGISTRAR;
        if (class_exists($registrar) && defined($registrar . '::NAMESPACE')) {
            $namespace = $registrar::NAMESPACE;
            if (is_string($namespace) && trim($namespace, '/') !== '') {
                return '/' . trim($namespace, '/') . self::CORE_ROUTE_PREFIX;
            }
        }

        return '/' . self::NAMESPACE;
    }

    private function registerRoutes(string $namespace, string $prefix): void
    {
        $this->routesRegistered = true;

        register_rest_route($namespace, $prefix . '/capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'capabilities'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/export-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createExportJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/import-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createImportJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'listJobs'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/direct-jobs', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'createDirectJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs/(?P<id>\d+)/run-next', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'runNext'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'getJob'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs/(?P<id>\d+)/errors', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'getErrors'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [new JobController(), 'cancel'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/jobs/(?P<id>\d+)/download', [
            'methods' => 'GET',
            'callback' => [new JobController(), 'download'],
            // v0.2.4 Reviewer #3 fix: GET endpoint 改用 adminDownload (要求 nonce-query-arg)
            // 防 CSRF — `<iframe src="/.../download">` 借 admin session 觸發 readfile()
            'permission_callback' => [Permission::class, 'adminDownload'],
        ]);

        register_rest_route($namespace, $prefix . '/packages/upload', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'upload'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/packages/(?P<id>\d+)/preview', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'preview'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        // v0.7.0：訂單狀態掃描（狀態選擇 + 未對應詢問 UI 的資料來源）
        register_rest_route($namespace, $prefix . '/packages/order-statuses', [
            'methods' => 'POST',
            'callback' => [new PackageController(), 'orderStatuses'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/backups', [
            'methods' => 'GET',
            'callback' => [new BackupController(), 'list'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/backups', [
            'methods' => 'POST',
            'callback' => [new BackupController(), 'create'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/backups/(?P<file>[A-Za-z0-9._-]+)', [
            'methods' => 'DELETE',
            'callback' => [new BackupController(), 'delete'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/backups/(?P<file>[A-Za-z0-9._-]+)/restore', [
            'methods' => 'POST',
            'callback' => [new BackupController(), 'restore'],
            'permission_callback' => [Permission::class, 'admin'],
        ]);

        register_rest_route($namespace, $prefix . '/backups/(?P<file>[A-Za-z0-9._-]+)/download', [
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
