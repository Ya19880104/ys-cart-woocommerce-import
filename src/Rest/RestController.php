<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Capabilities\CapabilityDetector;

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
            'permission_callback' => [Permission::class, 'admin'],
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
    }

    public function capabilities(): array
    {
        return (new CapabilityDetector())->getCapabilities();
    }
}
