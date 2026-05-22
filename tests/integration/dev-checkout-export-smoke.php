<?php
declare(strict_types=1);

use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Jobs\JobRunner;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

if (!function_exists('wc_get_orders')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

if (!class_exists(JobRepository::class) || !class_exists(JobRunner::class)) {
    fwrite(STDERR, "Migration plugin classes are not loaded.\n");
    exit(1);
}

$repo = new JobRepository();
$packageId = 'dev-checkout-smoke-' . gmdate('YmdHis');
$jobId = $repo->create('export', 'orders', [
    'package_id' => $packageId,
    'batch_size' => 2,
    'max_total' => 2,
], get_current_user_id());

$runner = new JobRunner();
$result = $runner->runNext($jobId);
$job = $repo->find($jobId);

echo wp_json_encode([
    'job_id' => $jobId,
    'result' => $result,
    'status' => $job ? $job->status : 'missing',
    'processed_count' => $job ? (int)$job->processed_count : 0,
    'success_count' => $job ? (int)$job->success_count : 0,
    'file_name' => $job ? (string)$job->file_name : '',
    'file_exists' => $job && $job->file_path ? is_file((string)$job->file_path) : false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

