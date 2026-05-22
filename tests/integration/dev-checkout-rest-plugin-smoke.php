<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

wp_set_current_user(1);

if (!did_action('rest_api_init')) {
    rest_get_server();
    do_action('rest_api_init');
}

$entities = [
    'customers' => 3,
    'products' => 3,
    'orders' => 3,
];
$results = [
    'capabilities' => rest_request('GET', '/ys-cart-wc-import/v1/capabilities'),
    'entities' => [],
];

foreach ($entities as $entity => $limit) {
    $export = rest_request('POST', '/ys-cart-wc-import/v1/export-jobs', [
        'entity' => $entity,
        'options' => [
            'package_id' => 'rest-smoke-' . $entity . '-' . gmdate('YmdHis'),
            'batch_size' => $limit,
            'max_total' => $limit,
        ],
    ]);

    $exportJobId = (int)($export['data']['id'] ?? 0);
    $exportRun = run_rest_job($exportJobId);
    $exportJob = rest_request('GET', '/ys-cart-wc-import/v1/jobs/' . $exportJobId);

    $filePath = (string)($exportJob['data']['file_path'] ?? '');
    $import = ['skipped' => true];
    $importRun = [];
    $importJob = [];

    if ($filePath !== '' && is_file($filePath)) {
        $import = rest_request('POST', '/ys-cart-wc-import/v1/import-jobs', [
            'entity' => $entity,
            'options' => [
                'file_path' => $filePath,
                'source_fingerprint' => hash('sha256', home_url()),
                'batch_size' => $limit,
            ],
        ]);

        $importJobId = (int)($import['data']['id'] ?? 0);
        $importRun = run_rest_job($importJobId);
        $importJob = rest_request('GET', '/ys-cart-wc-import/v1/jobs/' . $importJobId);
    }

    $results['entities'][$entity] = [
        'export_create' => $export,
        'export_run' => $exportRun,
        'export_job' => summarize_job_response($exportJob),
        'import_create' => $import,
        'import_run' => $importRun,
        'import_job' => summarize_job_response($importJob),
    ];
}

global $wpdb;
$results['counts'] = [
    'ys_customers' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_customers"),
    'ys_products' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_products"),
    'ys_orders' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_orders"),
    'migration_maps' => $wpdb->get_results(
        "SELECT entity, COUNT(*) AS total FROM {$wpdb->prefix}ys_wc_migration_maps GROUP BY entity ORDER BY entity",
        ARRAY_A
    ),
    'admin_user_1_caps' => get_user_meta(1, $wpdb->prefix . 'capabilities', true),
];

echo wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

foreach ($results['entities'] as $entity => $result) {
    $exportStatus = $result['export_job']['status'] ?? '';
    $importStatus = $result['import_job']['status'] ?? '';
    $importErrors = (int)($result['import_job']['error_count'] ?? 0);

    if ($exportStatus !== 'completed' || $importStatus !== 'completed' || $importErrors > 0) {
        fwrite(STDERR, "REST plugin smoke failed for {$entity}.\n");
        exit(1);
    }
}

if (!empty($results['counts']['admin_user_1_caps']['ys_ec_customer'])) {
    fwrite(STDERR, "Admin user 1 was incorrectly assigned ys_ec_customer.\n");
    exit(1);
}

function rest_request(string $method, string $route, array $params = []): array
{
    $request = new WP_REST_Request($method, $route);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    $response = rest_do_request($request);
    $data = rest_get_server()->response_to_data($response, false);

    return [
        'status' => $response->get_status(),
        'data' => $data,
    ];
}

function run_rest_job(int $jobId): array
{
    $runs = [];

    for ($i = 0; $i < 10; $i++) {
        $runs[] = rest_request('POST', '/ys-cart-wc-import/v1/jobs/' . $jobId . '/run-next');
        $job = rest_request('GET', '/ys-cart-wc-import/v1/jobs/' . $jobId);
        $status = (string)($job['data']['status'] ?? '');
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $runs[] = $job;
            return $runs;
        }
    }

    return $runs;
}

function summarize_job_response(array $response): array
{
    $data = $response['data'] ?? [];
    return [
        'http_status' => $response['status'] ?? 0,
        'id' => isset($data['id']) ? (int)$data['id'] : 0,
        'type' => (string)($data['type'] ?? ''),
        'entity' => (string)($data['entity'] ?? ''),
        'status' => (string)($data['status'] ?? ''),
        'processed_count' => isset($data['processed_count']) ? (int)$data['processed_count'] : 0,
        'success_count' => isset($data['success_count']) ? (int)$data['success_count'] : 0,
        'error_count' => isset($data['error_count']) ? (int)$data['error_count'] : 0,
        'file_name' => (string)($data['file_name'] ?? ''),
        'file_path' => (string)($data['file_path'] ?? ''),
        'file_exists' => !empty($data['file_path']) && is_file((string)$data['file_path']),
    ];
}

