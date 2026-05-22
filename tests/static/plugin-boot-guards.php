<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = $root . '/ys-cart-woocommerce-import.php';
$capabilities = $root . '/src/Capabilities/CapabilityDetector.php';

if (!is_file($main)) {
    throw new RuntimeException('Main plugin file is missing.');
}

if (!is_file($capabilities)) {
    throw new RuntimeException('Capability detector is missing.');
}

$mainContents = file_get_contents($main);
$capabilityContents = file_get_contents($capabilities);

foreach (['YS_CWCI_PLUGIN_FILE', 'YS_CWCI_PLUGIN_DIR', 'YS_CWCI_VERSION'] as $constant) {
    if (strpos($mainContents, $constant) === false) {
        throw new RuntimeException("Missing bootstrap constant {$constant}.");
    }
}

foreach (['hasWooCommerce', 'hasYsCart'] as $method) {
    if (strpos($capabilityContents, "function {$method}") === false) {
        throw new RuntimeException("Capability detector missing {$method}().");
    }
}

if (strpos($capabilityContents, 'class_exists') === false && strpos($capabilityContents, 'function_exists') === false) {
    throw new RuntimeException('Capability detector must use guarded runtime checks.');
}

