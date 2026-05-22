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

$mapped = $class::mapOrder([
    'source_id' => '11702',
    'number' => '11702',
    'status' => 'on-hold',
    'currency' => 'TWD',
    'prices' => [
        'subtotal' => '35',
        'shipping_total' => '80',
        'discount_total' => '0',
        'total' => '115',
    ],
    'payment' => [
        'method' => 'bacs',
        'method_title' => 'Bank transfer',
    ],
    'shipping_methods' => [
        [
            'method_id' => 'payuni_shipping_711_c2c_normal',
            'instance_id' => 5,
            'name' => 'PAYUNi 7-Eleven Store Pickup Normal',
            'total' => '80',
        ],
    ],
    'billing' => [
        'first_name' => 'Codex',
        'last_name' => 'Load',
        'email' => 'codex@example.test',
        'phone' => '0911222333',
        'country' => 'TW',
    ],
    'shipping' => [
        'first_name' => 'Codex',
        'last_name' => 'Load',
        'country' => 'TW',
        'state' => 'Taipei City',
        'city' => 'Zhongzheng',
        'address_1' => 'Load Test Road 2484',
    ],
], 123, 456);

if (array_key_exists('shipping_method_id', $mapped) && trim((string)$mapped['shipping_method_id']) !== '') {
    throw new RuntimeException('Order mapper must not write Woo shipping method_id into YS shipping_method_id.');
}

if (($mapped['shipping_provider'] ?? '') !== 'PAYUNi 7-Eleven Store Pickup Normal') {
    throw new RuntimeException('Order mapper must preserve Woo shipping method name as YS shipping_provider.');
}
