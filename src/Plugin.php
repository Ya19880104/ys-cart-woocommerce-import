<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Admin\AdminPage;
use YangSheep\YsCartWooImport\Jobs\JobRunner;
use YangSheep\YsCartWooImport\Jobs\Scheduler;
use YangSheep\YsCartWooImport\Rest\RestController;
use YangSheep\YsCartWooImport\YsCart\OrderSourceRepository;

final class Plugin
{
    private static ?self $instance = null;
    private bool $initialized = false;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $adminPage = new AdminPage();
        $restController = new RestController();

        add_action('admin_menu', [$adminPage, 'register'], 30);
        add_action('admin_enqueue_scripts', [$adminPage, 'enqueue']);
        add_action('ys_ec_register_admin_rest_routes', [$restController, 'registerCoreRoutes'], 10, 1);
        add_action('rest_api_init', [$restController, 'registerFallbackRoutes'], 20);
        add_action(Scheduler::HOOK_RUN_JOB, [new JobRunner(), 'runScheduledJob'], 10, 1);

        // v0.2.3: GDPR cascade — listen for ys-cart core ≥ 2.45.43 `ys_ec_order_deleted`
        // action、刪 source row 含 source_meta JSON PII（billing_email）。
        // 跨 plugin 動作協同設計、ys-cart core 是 source-of-truth。
        add_action('ys_ec_order_deleted', [OrderSourceRepository::class, 'onOrderDeleted'], 10, 2);
    }
}

