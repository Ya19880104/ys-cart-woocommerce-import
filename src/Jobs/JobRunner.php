<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Jobs;

defined('ABSPATH') || exit;

use Throwable;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Woo\CustomerExporter;
use YangSheep\YsCartWooImport\Woo\OrderExporter;
use YangSheep\YsCartWooImport\Woo\ProductExporter;
use YangSheep\YsCartWooImport\Packages\PackageWriter;
use YangSheep\YsCartWooImport\YsCart\CustomerImporter;
use YangSheep\YsCartWooImport\YsCart\OrderImporter;
use YangSheep\YsCartWooImport\YsCart\ProductImporter;

final class JobRunner
{
    private const MAX_ROWS = 50;
    private const MAX_SECONDS = 15;

    public function runScheduledJob($jobId): void
    {
        $this->runNext((int)$jobId);
    }

    public function runNext(int $jobId): array
    {
        $repo = new JobRepository();
        $job = $repo->find($jobId);

        if (!$job) {
            return ['status' => 'missing'];
        }

        if (in_array((string)$job->status, ['completed', 'failed', 'cancelled'], true)) {
            return ['done' => true, 'status' => (string)$job->status];
        }

        if (!$this->acquireJobLock($jobId)) {
            return [
                'done' => false,
                'status' => 'locked',
                'message' => 'Job is already being processed.',
            ];
        }

        try {
            $started = false;
            if ($job->status === 'pending') {
                $repo->markRunning($jobId);
                $job = $repo->find($jobId);
                $started = true;
            }

            if ($started && $job && $job->type === 'export') {
                $this->resetExportPackage($job);
            }

            $result = $this->dispatch($job);
            if (($result['done'] ?? false) === true) {
                if ($job->type === 'export') {
                    $this->finalizeExport($repo->find($jobId) ?: $job);
                }
                $repo->complete($jobId);
            } else {
                (new Scheduler())->enqueue($jobId);
            }

            return $result;
        } catch (Throwable $e) {
            $repo->fail($jobId, $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            $this->releaseJobLock($jobId);
        }
    }

    private function dispatch(object $job): array
    {
        $options = json_decode((string)$job->options_json, true) ?: [];
        $cursor = json_decode((string)$job->cursor_json, true) ?: [];
        $limits = [
            'max_rows' => (int)($options['batch_size'] ?? self::MAX_ROWS),
            'max_seconds' => self::MAX_SECONDS,
        ];

        if ($job->type === 'export') {
            return match ($job->entity) {
                'customers' => (new CustomerExporter())->exportBatch((int)$job->id, $options, $cursor, $limits),
                'products' => (new ProductExporter())->exportBatch((int)$job->id, $options, $cursor, $limits),
                'orders' => (new OrderExporter())->exportBatch((int)$job->id, $options, $cursor, $limits),
                default => ['done' => true, 'message' => 'Unsupported export entity.'],
            };
        }

        if ($job->type === 'import') {
            return match ($job->entity) {
                'customers' => (new CustomerImporter())->importBatch((int)$job->id, $options, $cursor, $limits),
                'products' => (new ProductImporter())->importBatch((int)$job->id, $options, $cursor, $limits),
                'orders' => (new OrderImporter())->importBatch((int)$job->id, $options, $cursor, $limits),
                default => ['done' => true, 'message' => 'Unsupported import entity.'],
            };
        }

        return ['done' => true, 'message' => 'Unsupported job type.'];
    }

    private function resetExportPackage(object $job): void
    {
        $options = json_decode((string)$job->options_json, true) ?: [];
        $packageId = (string)($options['package_id'] ?? ('job-' . (int)$job->id));
        (new PackageWriter())->resetPackage($packageId);
    }

    private function finalizeExport(object $job): void
    {
        $options = json_decode((string)$job->options_json, true) ?: [];
        $packageId = (string)($options['package_id'] ?? ('job-' . (int)$job->id));
        $writer = new PackageWriter();
        $files = [
            'customers' => 'customers.jsonl',
            'products' => 'products.jsonl',
            'orders' => 'orders.jsonl',
            'product_variants' => 'product_variants.jsonl',
        ];

        foreach ($files as $fileName) {
            $writer->ensureFile($packageId, $fileName);
        }

        $writer->writeManifest($packageId, [
            'schema_version' => '1.0',
            'source' => [
                'site_url_hash' => hash('sha256', function_exists('home_url') ? home_url() : ''),
                'site_url' => function_exists('home_url') ? home_url() : '',
                'wordpress_version' => get_bloginfo('version'),
                'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
            ],
            'created_at' => gmdate(DATE_ATOM),
            'entities' => [
                'customers' => $job->entity === 'customers' ? (int)$job->success_count : 0,
                'products' => $job->entity === 'products' ? (int)$job->success_count : 0,
                'orders' => $job->entity === 'orders' ? (int)$job->success_count : 0,
            ],
            'files' => $files,
        ]);

        $zipPath = $writer->zip($packageId);
        (new JobRepository())->attachFile((int)$job->id, $zipPath);
    }

    private function acquireJobLock(int $jobId): bool
    {
        $key = $this->lockKey($jobId);
        $expiresAt = (string)(time() + self::MAX_SECONDS + 45);

        if (add_option($key, $expiresAt, '', 'no')) {
            return true;
        }

        $existing = (int)get_option($key);
        if ($existing > 0 && $existing < time()) {
            delete_option($key);
            return add_option($key, $expiresAt, '', 'no');
        }

        return false;
    }

    private function releaseJobLock(int $jobId): void
    {
        delete_option($this->lockKey($jobId));
    }

    private function lockKey(int $jobId): string
    {
        return 'ys_cwci_job_lock_' . $jobId;
    }
}
