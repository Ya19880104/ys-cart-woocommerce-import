<?php
declare(strict_types=1);

use YangSheep\YsCartWooImport\Capabilities\CapabilityDetector;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Jobs\JobRunner;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

$required = [
    'wc_get_orders' => function_exists('wc_get_orders'),
    'YSCustomer' => class_exists('\YangSheep\Ecommerce\Models\YSCustomer'),
    'YSOrder' => class_exists('\YangSheep\Ecommerce\Models\YSOrder'),
    'YSProduct' => class_exists('\YangSheep\Ecommerce\Models\YSProduct'),
    'JobRepository' => class_exists(JobRepository::class),
    'JobRunner' => class_exists(JobRunner::class),
];

foreach ($required as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "{$name} is not available.\n");
        exit(1);
    }
}

$repo = new JobRepository();
$runner = new JobRunner();
$packageId = 'dev-checkout-import-smoke-' . gmdate('YmdHis');
$fingerprint = hash('sha256', home_url());

$exportJobId = $repo->create('export', 'orders', [
    'package_id' => $packageId,
    'batch_size' => 2,
    'max_total' => 2,
], get_current_user_id());

$exportResult = run_job_until_done($runner, $repo, $exportJobId);
$exportJob = $repo->find($exportJobId);

if (!$exportJob || $exportJob->status !== 'completed' || !is_file((string)$exportJob->file_path)) {
    fwrite(STDERR, "Export smoke failed.\n");
    echo wp_json_encode([
        'export_job_id' => $exportJobId,
        'export_result' => $exportResult,
        'export_status' => $exportJob ? $exportJob->status : 'missing',
        'export_file' => $exportJob ? $exportJob->file_path : '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

$importJobId = $repo->create('import', 'orders', [
    'file_path' => (string)$exportJob->file_path,
    'source_fingerprint' => $fingerprint,
    'batch_size' => 2,
], get_current_user_id());

$importResult = run_job_until_done($runner, $repo, $importJobId);
$importJob = $repo->find($importJobId);
$capabilities = (new CapabilityDetector())->getCapabilities();

echo wp_json_encode([
    'capabilities' => $capabilities,
    'export' => [
        'job_id' => $exportJobId,
        'result' => $exportResult,
        'status' => $exportJob->status,
        'processed_count' => (int)$exportJob->processed_count,
        'success_count' => (int)$exportJob->success_count,
        'file_name' => (string)$exportJob->file_name,
        'file_exists' => is_file((string)$exportJob->file_path),
    ],
    'import' => [
        'job_id' => $importJobId,
        'result' => $importResult,
        'status' => $importJob ? $importJob->status : 'missing',
        'processed_count' => $importJob ? (int)$importJob->processed_count : 0,
        'success_count' => $importJob ? (int)$importJob->success_count : 0,
        'error_count' => $importJob ? (int)$importJob->error_count : 0,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (!$importJob || $importJob->status !== 'completed' || (int)$importJob->success_count < 1) {
    exit(1);
}

function run_job_until_done(JobRunner $runner, JobRepository $repo, int $jobId): array
{
    $last = [];

    for ($i = 0; $i < 5; $i++) {
        $last = $runner->runNext($jobId);
        $job = $repo->find($jobId);
        if ($job && in_array((string)$job->status, ['completed', 'failed', 'cancelled'], true)) {
            return $last;
        }
    }

    return $last;
}
