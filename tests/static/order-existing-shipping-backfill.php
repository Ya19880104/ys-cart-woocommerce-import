<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$importer = $root . '/src/YsCart/OrderImporter.php';

if (!is_file($importer)) {
    throw new RuntimeException('Order importer is missing.');
}

$contents = (string)file_get_contents($importer);

foreach (['backfillExistingOrderShipping', 'shipping_provider'] as $needle) {
    if (strpos($contents, $needle) === false) {
        throw new RuntimeException("Order importer must backfill {$needle} for existing Woo-imported orders.");
    }
}

if (strpos($contents, "\$updates['shipping_method_id']") !== false
    || strpos($contents, '$updates["shipping_method_id"]') !== false
    || strpos($contents, "'shipping_method_id' =>") !== false
    || strpos($contents, '"shipping_method_id" =>') !== false) {
    throw new RuntimeException('Order importer must not backfill Woo method_id into YS shipping_method_id.');
}

$existingSourceBranch = strpos($contents, '$existingSourceOrderId') !== false
    && preg_match('/if \\(\\$existingSourceOrderId\\).*?backfillExistingOrderShipping/s', $contents) === 1;
if (!$existingSourceBranch) {
    throw new RuntimeException('Existing source-order dedupe path must backfill shipping before returning.');
}
