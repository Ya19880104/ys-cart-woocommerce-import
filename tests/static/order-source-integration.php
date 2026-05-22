<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$importer = $root . '/src/YsCart/OrderImporter.php';
$repository = $root . '/src/YsCart/OrderSourceRepository.php';

if (!is_file($importer)) {
    throw new RuntimeException('Order importer is missing.');
}

if (!is_file($repository)) {
    throw new RuntimeException('Order source repository is missing.');
}

$contents = (string)file_get_contents($importer);
foreach (['OrderSourceRepository', 'findOrderId', 'upsertWooOrder'] as $needle) {
    if (strpos($contents, $needle) === false) {
        throw new RuntimeException("Order importer must use {$needle} for YS CART source-order dedupe.");
    }
}

$repositoryContents = (string)file_get_contents($repository);
foreach (['YS_ECOMMERCE_TABLE_PREFIX', "'ys_ec_'", "'order_sources'", 'UNHEX(%s)', 'source_order_number_norm'] as $needle) {
    if (strpos($repositoryContents, $needle) === false) {
        throw new RuntimeException("Order source repository must include {$needle}.");
    }
}
