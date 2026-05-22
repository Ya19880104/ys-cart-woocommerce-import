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

global $wpdb;

$results = [
    'counts' => [
        'ys_orders' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_orders"),
        'ys_order_items' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_order_items"),
        'ys_customers' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ys_ec_customers"),
        'wc_orders' => table_exists($wpdb->prefix . 'wc_orders') ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders") : null,
        'wp_shop_order_posts' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'shop_order')),
        'actionscheduler_pending' => table_exists($wpdb->prefix . 'actionscheduler_actions') ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = %s", 'pending')) : null,
    ],
    'timings' => [],
    'slow_queries' => [],
];

$results['timings']['ys_order_get_list'] = timed(function () {
    return \YangSheep\Ecommerce\Models\YSOrder::get_list([
        'per_page' => 20,
        'page' => 1,
    ]);
});

$results['timings']['ys_order_status_counts'] = timed(function () use ($wpdb) {
    $table = \YangSheep\Ecommerce\Models\YSOrder::table();
    return $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A);
});

$results['timings']['ys_order_monthly_stats_equivalent'] = timed(function () use ($wpdb) {
    $table = \YangSheep\Ecommerce\Models\YSOrder::table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS cnt
         FROM {$table}
         WHERE status NOT IN ('trash', 'cancelled', 'failed', 'refunded')
         AND created_at BETWEEN %s AND %s",
        gmdate('Y-m-01 00:00:00'),
        gmdate('Y-m-t 23:59:59')
    ));
});

$results['timings']['orders_notification_rest'] = timed(function () {
    return rest_request('GET', '/ys-ecommerce-headless/v1/admin/orders/notifications/state', [
        'since_id' => 0,
        'limit' => 5,
    ]);
});

$results['timings']['ys_order_admin_render_list'] = timed(function () {
    $_GET['page'] = 'ys-ec-orders';
    $_GET['action'] = 'list';
    $_GET['paged'] = '1';
    ob_start();
    \YangSheep\Ecommerce\Admin\YSOrderAdmin::render();
    $html = ob_get_clean();

    return [
        'html_bytes' => strlen((string)$html),
        'contains_order_table' => str_contains((string)$html, 'ys-ec-orders-form'),
    ];
});

$results['timings']['woocommerce_wc_get_orders_three'] = timed(function () {
    if (!function_exists('wc_get_orders')) {
        return null;
    }

    return wc_get_orders([
        'limit' => 3,
        'return' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
});

if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries)) {
    $queries = $wpdb->queries;
    usort($queries, static function ($a, $b) {
        return ($b[1] ?? 0) <=> ($a[1] ?? 0);
    });
    $results['slow_queries'] = array_slice(array_map(static function ($q) {
        return [
            'seconds' => round((float)($q[1] ?? 0), 6),
            'sql' => preg_replace('/\s+/', ' ', (string)($q[0] ?? '')),
        ];
    }, $queries), 0, 10);
}

echo wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

function timed(callable $callback): array
{
    $start = microtime(true);
    $startMemory = memory_get_usage(true);
    $value = $callback();

    return [
        'ms' => round((microtime(true) - $start) * 1000, 2),
        'memory_delta_kb' => round((memory_get_usage(true) - $startMemory) / 1024, 2),
        'summary' => summarize($value),
    ];
}

function summarize($value)
{
    if (is_array($value)) {
        if (isset($value['items'], $value['total'], $value['pages'])) {
            return [
                'items' => is_array($value['items']) ? count($value['items']) : 0,
                'total' => (int)$value['total'],
                'pages' => (int)$value['pages'],
            ];
        }

        if (isset($value['status'], $value['data'])) {
            return [
                'status' => $value['status'],
                'data_keys' => is_array($value['data']) ? array_keys($value['data']) : [],
            ];
        }

        return [
            'count' => count($value),
            'sample' => array_slice($value, 0, 2),
        ];
    }

    if (is_object($value)) {
        return get_object_vars($value);
    }

    return $value;
}

function rest_request(string $method, string $route, array $params = []): array
{
    $request = new WP_REST_Request($method, $route);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    $response = rest_do_request($request);
    $data = json_decode(wp_json_encode(rest_get_server()->response_to_data($response, false)), true);

    return [
        'status' => $response->get_status(),
        'data' => $data,
    ];
}

function table_exists(string $table): bool
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

