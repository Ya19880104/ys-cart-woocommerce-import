<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Jobs;

defined('ABSPATH') || exit;

final class Scheduler
{
    public const HOOK_RUN_JOB = 'ys_cwci_run_job';

    public function enqueue(int $jobId): void
    {
        // v0.2.4 CRITICAL fix (Reviewer #4 dormant bug):
        // 原 ['job_id' => $jobId] associative array、Action Scheduler 透過
        // call_user_func_array 把整 array 當單一 arg 傳給 listener →
        // JobRunner::runScheduledJob 收到 array、(int)$array = 1 → 每個
        // async job 都跑 jobId=1。LIVE dev-checkout 證實 wp_actionscheduler_actions
        // 為 0 rows（10K LIVE test 走 manual runNext loop、AS path 從未真實觸發）。
        // Fix: 用 positional args [$jobId]、對齊 line 20 wp_schedule_single_event 的 pattern。
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_RUN_JOB, [$jobId], 'ys-cart-wc-import', true);
            return;
        }

        if (!wp_next_scheduled(self::HOOK_RUN_JOB, [$jobId])) {
            wp_schedule_single_event(time() + 5, self::HOOK_RUN_JOB, [$jobId]);
        }
    }
}

