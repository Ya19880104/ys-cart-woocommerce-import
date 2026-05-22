<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Admin\AdminPage;
use YangSheep\YsCartWooImport\Jobs\JobRunner;
use YangSheep\YsCartWooImport\Jobs\Scheduler;
use YangSheep\YsCartWooImport\Rest\RestController;

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

        add_action('admin_menu', [new AdminPage(), 'register'], 30);
        add_action('admin_enqueue_scripts', [new AdminPage(), 'enqueue']);
        add_action('rest_api_init', [new RestController(), 'registerRoutes']);
        add_action(Scheduler::HOOK_RUN_JOB, [new JobRunner(), 'runScheduledJob'], 10, 1);
    }
}

