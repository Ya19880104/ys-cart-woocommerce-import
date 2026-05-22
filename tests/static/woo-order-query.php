<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$exporter = $root . '/src/Woo/OrderExporter.php';

if (!is_file($exporter)) {
    throw new RuntimeException('Woo order exporter is missing.');
}

$contents = file_get_contents($exporter);

if (strpos($contents, 'wc_get_orders') === false && strpos($contents, 'WC_Order_Query') === false) {
    throw new RuntimeException('Woo order exporter must use wc_get_orders() or WC_Order_Query.');
}

if (preg_match('/\$wpdb->(get_results|get_col|get_row|query)\s*\(/', $contents)) {
    throw new RuntimeException('Woo order exporter must not query orders through $wpdb.');
}

