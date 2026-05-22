<?php
declare(strict_types=1);

use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Jobs\JobRunner;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

$entity = sanitize_key((string)(getenv('YS_CWCI_ENTITY') ?: 'orders'));
$filePath = (string)(getenv('YS_CWCI_FILE_PATH') ?: '');
$batchSize = max(1, min(100, (int)(getenv('YS_CWCI_BATCH_SIZE') ?: 50)));

if ($filePath === '' || !is_file($filePath)) {
    fwrite(STDERR, "YS_CWCI_FILE_PATH is missing or not readable.\n");
    exit(1);
}

$repo = new JobRepository();
$runner = new JobRunner();
$jobId = $repo->create('import', $entity, [
    'file_path' => $filePath,
    'source_fingerprint' => hash('sha256', home_url()),
    'batch_size' => $batchSize,
], get_current_user_id());

$last = [];
for ($i = 0; $i < 20000; $i++) {
    $last = $runner->runNext($jobId);
    $job = $repo->find($jobId);
    if ($job && in_array((string)$job->status, ['completed', 'failed', 'cancelled'], true)) {
        break;
    }

    if (($i + 1) % 25 === 0) {
        usleep(100000);
    }
}

$job = $repo->find($jobId);
$result = [
    'job_id' => $jobId,
    'entity' => $entity,
    'status' => $job ? (string)$job->status : 'missing',
    'processed_count' => $job ? (int)$job->processed_count : 0,
    'success_count' => $job ? (int)$job->success_count : 0,
    'error_count' => $job ? (int)$job->error_count : 0,
    'last_run' => $last,
];

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (!$job || $job->status !== 'completed' || (int)$job->error_count > 0) {
    exit(1);
}
