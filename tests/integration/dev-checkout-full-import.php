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
    'WP_User_Query' => class_exists('WP_User_Query'),
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

$batchSize = max(1, min(100, (int)(getenv('YS_CWCI_BATCH_SIZE') ?: 50)));
$repo = new JobRepository();
$runner = new JobRunner();
$fingerprint = hash('sha256', home_url());
$entities = ['customers', 'products', 'orders'];
$startedAt = microtime(true);
$results = [
    'started_at' => gmdate(DATE_ATOM),
    'batch_size' => $batchSize,
    'capabilities' => (new CapabilityDetector())->getCapabilities(),
    'source_counts' => source_counts(),
    'before_counts' => target_counts(),
    'entities' => [],
];

foreach ($entities as $entity) {
    $packageId = 'dev-checkout-full-' . $entity . '-' . gmdate('YmdHis');
    $exportJobId = $repo->create('export', $entity, [
        'package_id' => $packageId,
        'batch_size' => $batchSize,
        'max_total' => 0,
    ], get_current_user_id());

    $exportRun = run_job_until_done($runner, $repo, $exportJobId, 20000);
    $exportJob = $repo->find($exportJobId);

    if (!$exportJob || $exportJob->status !== 'completed' || !is_file((string)$exportJob->file_path)) {
        $results['entities'][$entity] = [
            'export' => summarize_job($exportJobId, $exportJob, $exportRun),
            'import_skipped' => true,
        ];
        echo wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }

    $importJobId = $repo->create('import', $entity, [
        'file_path' => (string)$exportJob->file_path,
        'source_fingerprint' => $fingerprint,
        'batch_size' => $batchSize,
    ], get_current_user_id());

    $importRun = run_job_until_done($runner, $repo, $importJobId, 20000);
    $importJob = $repo->find($importJobId);

    $results['entities'][$entity] = [
        'export' => summarize_job($exportJobId, $exportJob, $exportRun),
        'import' => summarize_job($importJobId, $importJob, $importRun),
    ];

    if (!$importJob || $importJob->status !== 'completed' || (int)$importJob->error_count > 0) {
        $results['after_counts'] = target_counts();
        echo wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
}

$results['after_counts'] = target_counts();
$results['duration_seconds'] = round(microtime(true) - $startedAt, 3);

echo wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ((int)$results['after_counts']['ys_order_sources'] > 0
    && (int)$results['after_counts']['ys_order_sources'] < (int)$results['entities']['orders']['import']['success_count']) {
    fwrite(STDERR, "Order source count is lower than full order import success count.\n");
    exit(1);
}

function run_job_until_done(JobRunner $runner, JobRepository $repo, int $jobId, int $maxSteps): array
{
    $last = [];

    for ($i = 0; $i < $maxSteps; $i++) {
        $last = $runner->runNext($jobId);
        $job = $repo->find($jobId);
        if ($job && in_array((string)$job->status, ['completed', 'failed', 'cancelled'], true)) {
            return $last + ['steps' => $i + 1];
        }

        if (($i + 1) % 25 === 0) {
            usleep(100000);
        }
    }

    return $last + ['steps' => $maxSteps, 'max_steps_reached' => true];
}

function summarize_job(int $jobId, ?object $job, array $lastRun): array
{
    return [
        'job_id' => $jobId,
        'status' => $job ? (string)$job->status : 'missing',
        'processed_count' => $job ? (int)$job->processed_count : 0,
        'success_count' => $job ? (int)$job->success_count : 0,
        'error_count' => $job ? (int)$job->error_count : 0,
        'file_name' => $job ? (string)$job->file_name : '',
        'file_exists' => $job && $job->file_path ? is_file((string)$job->file_path) : false,
        'last_run' => $lastRun,
    ];
}

function source_counts(): array
{
    global $wpdb;

    $userQuery = new WP_User_Query([
        'role__in' => ['customer', 'subscriber'],
        'number' => 1,
        'fields' => 'ID',
        'count_total' => true,
    ]);

    $productTotal = 0;
    $products = wc_get_products([
        'limit' => 1,
        'page' => 1,
        'paginate' => true,
        'status' => ['publish', 'draft', 'private'],
        'type' => ['simple', 'variable'],
    ]);
    if (is_object($products) && isset($products->total)) {
        $productTotal = (int)$products->total;
    }

    $orderTotal = 0;
    $orders = wc_get_orders([
        'type' => 'shop_order',
        'status' => array_keys(wc_get_order_statuses()),
        'limit' => 1,
        'paged' => 1,
        'paginate' => true,
        'return' => 'ids',
    ]);
    if (is_object($orders) && isset($orders->total)) {
        $orderTotal = (int)$orders->total;
    }

    return [
        'wp_customer_or_subscriber_users' => (int)$userQuery->get_total(),
        'woo_simple_or_variable_products' => $productTotal,
        'woo_shop_orders' => $orderTotal,
        'wp_users' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    ];
}

function target_counts(): array
{
    global $wpdb;

    return [
        'ys_customers' => table_count($wpdb->prefix . 'ys_ec_customers'),
        'ys_products' => table_count($wpdb->prefix . 'ys_ec_products'),
        'ys_product_variants' => table_count($wpdb->prefix . 'ys_ec_product_variants'),
        'ys_orders' => table_count($wpdb->prefix . 'ys_ec_orders'),
        'ys_order_sources' => table_count($wpdb->prefix . 'ys_ec_order_sources'),
        'migration_maps' => $wpdb->get_results(
            "SELECT entity, COUNT(*) AS total FROM {$wpdb->prefix}ys_wc_migration_maps GROUP BY entity ORDER BY entity",
            ARRAY_A
        ),
    ];
}

function table_count(string $table): int
{
    global $wpdb;
    if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return 0;
    }

    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}
