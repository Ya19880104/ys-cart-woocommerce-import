<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mapper = $root . '/src/YsCart/OrderMapper.php';

if (!is_file($mapper)) {
    throw new RuntimeException('Order mapper is missing.');
}

require_once $mapper;

$class = 'YangSheep\\YsCartWooImport\\YsCart\\OrderMapper';
if (!class_exists($class)) {
    throw new RuntimeException('OrderMapper class is missing.');
}

$expected = [
    'pending' => 'pending',
    'on-hold' => 'offline_payment',
    'processing' => 'processing',
    'completed' => 'completed',
    'cancelled' => 'cancelled',
    'refunded' => 'refunded',
    'failed' => 'failed',
    'trash' => 'trash',
    'unknown' => 'pending',
];

foreach ($expected as $woo => $ys) {
    $actual = $class::mapStatus($woo);
    if ($actual !== $ys) {
        throw new RuntimeException("Status {$woo} mapped to {$actual}; expected {$ys}.");
    }
}

