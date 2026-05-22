<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Jobs\JobRunner;
use YangSheep\YsCartWooImport\Jobs\Scheduler;

final class JobController
{
    public function createExportJob($request)
    {
        $entity = sanitize_key((string)($request['entity'] ?? 'orders'));
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        $options['package_id'] = $options['package_id'] ?? ('export-' . wp_generate_uuid4());
        $jobId = (new JobRepository())->create('export', $entity, $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function createImportJob($request)
    {
        $entity = sanitize_key((string)($request['entity'] ?? 'orders'));
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        $jobId = (new JobRepository())->create('import', $entity, $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function listJobs(): array
    {
        return (new JobRepository())->list();
    }

    public function createDirectJob($request)
    {
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        $jobId = (new JobRepository())->create('direct', 'all', $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function runNext($request)
    {
        return (new JobRunner())->runNext((int)$request['id']);
    }

    public function getJob($request)
    {
        $job = (new JobRepository())->find((int)$request['id']);
        if (!$job) {
            return new \WP_Error('ys_cwci_job_missing', __('Job not found.', 'ys-cart-woocommerce-import'), ['status' => 404]);
        }

        return $job;
    }

    public function getErrors($request): array
    {
        return (new ErrorRepository())->listForJob((int)$request['id']);
    }

    public function cancel($request): array
    {
        (new JobRepository())->cancel((int)$request['id']);
        return ['id' => (int)$request['id'], 'status' => 'cancelled'];
    }

    public function download($request)
    {
        $job = (new JobRepository())->find((int)$request['id']);
        if (!$job || empty($job->file_path) || !is_file((string)$job->file_path)) {
            return new \WP_Error('ys_cwci_file_missing', __('Package file not found.', 'ys-cart-woocommerce-import'), ['status' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename((string)$job->file_path) . '"');
        header('Content-Length: ' . filesize((string)$job->file_path));
        readfile((string)$job->file_path);
        exit;
    }
}
