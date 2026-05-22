<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Jobs;

defined('ABSPATH') || exit;

final class Scheduler
{
    public const HOOK_RUN_JOB = 'ys_cwci_run_job';

    public function enqueue(int $jobId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_RUN_JOB, ['job_id' => $jobId], 'ys-cart-wc-import', true);
            return;
        }

        if (!wp_next_scheduled(self::HOOK_RUN_JOB, [$jobId])) {
            wp_schedule_single_event(time() + 5, self::HOOK_RUN_JOB, [$jobId]);
        }
    }
}

