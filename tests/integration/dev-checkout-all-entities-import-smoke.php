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
    'wc_get_products' => function_exists('wc_get_products'),
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
$fingerprint = hash('sha256', home_url());
$orderSourcesTable = $GLOBALS['wpdb']->prefix . 'ys_ec_order_sources';
$hasOrderSourcesTable = table_exists($orderSourcesTable);
$entities = [
    'customers' => 5,
    'products' => 5,
    'orders' => 5,
];
$results = [];

foreach ($entities as $entity => $maxTotal) {
    $packageId = 'dev-checkout-' . $entity . '-smoke-' . gmdate('YmdHis');
    $exportJobId = $repo->create('export', $entity, [
        'package_id' => $packageId,
        'batch_size' => 5,
        'max_total' => $maxTotal,
    ], get_current_user_id());

    $exportResult = run_job_until_done($runner, $repo, $exportJobId);
    $exportJob = $repo->find($exportJobId);

    if (!$exportJob || $exportJob->status !== 'completed' || !is_file((string)$exportJob->file_path)) {
        $results[$entity] = [
            'export_job_id' => $exportJobId,
            'export_result' => $exportResult,
            'export_status' => $exportJob ? $exportJob->status : 'missing',
            'export_file' => $exportJob ? $exportJob->file_path : '',
            'import_skipped' => true,
        ];
        continue;
    }

    $importJobId = $repo->create('import', $entity, [
        'file_path' => (string)$exportJob->file_path,
        'source_fingerprint' => $fingerprint,
        'batch_size' => 5,
    ], get_current_user_id());

    $importResult = run_job_until_done($runner, $repo, $importJobId);
    $importJob = $repo->find($importJobId);

    $results[$entity] = [
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
    ];
}

global $wpdb;
$counts = [
    'ys_customers' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_customers"),
    'ys_products' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_products"),
    'ys_orders' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_orders"),
    'ys_order_sources' => $hasOrderSourcesTable ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$orderSourcesTable} WHERE source_platform = 'woocommerce'") : null,
    'maps' => $wpdb->get_results("SELECT entity, COUNT(*) AS total FROM {$wpdb->prefix}ys_wc_migration_maps GROUP BY entity ORDER BY entity", ARRAY_A),
];

echo wp_json_encode([
    'capabilities' => (new CapabilityDetector())->getCapabilities(),
    'results' => $results,
    'counts' => $counts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

foreach ($results as $entity => $result) {
    if (!empty($result['import_skipped'])) {
        continue;
    }

    if (($result['import']['status'] ?? '') !== 'completed' || (int)($result['import']['error_count'] ?? 0) > 0) {
        exit(1);
    }
}

if ($hasOrderSourcesTable) {
    $orderImportSuccess = (int)($results['orders']['import']['success_count'] ?? 0);
    if ($orderImportSuccess > 0 && (int)$counts['ys_order_sources'] < $orderImportSuccess) {
        fwrite(STDERR, "Order source table was not populated for Woo order imports.\n");
        exit(1);
    }
}

function run_job_until_done(JobRunner $runner, JobRepository $repo, int $jobId): array
{
    $last = [];

    for ($i = 0; $i < 8; $i++) {
        $last = $runner->runNext($jobId);
        $job = $repo->find($jobId);
        if ($job && in_array((string)$job->status, ['completed', 'failed', 'cancelled'], true)) {
            return $last;
        }
    }

    return $last;
}

function table_exists(string $table): bool
{
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}
