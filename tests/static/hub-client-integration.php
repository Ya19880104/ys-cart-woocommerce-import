<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = (string)file_get_contents($root . '/ys-cart-woocommerce-import.php');
$gitignore = (string)file_get_contents($root . '/.gitignore');

$requiredFiles = [
    'vendor/autoload.php',
    'vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    'vendor/yangsheep/ys-plugin-hub-client/src/YSPluginHubClient.php',
    'vendor/yangsheep/ys-plugin-hub-client/src/Updater/YSUpdateChecker.php',
    'vendor/yangsheep/ys-plugin-hub-client/src/Marketplace/YSMarketplaceInstaller.php',
];

foreach ($requiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException("Missing bundled Hub Client file: {$relative}");
    }
}

foreach ([
    '\YangSheep\PluginHubClient\YSPluginHubClient',
    "YSPluginHubClient::register",
    "'slug' => 'ys-cart-woocommerce-import'",
    "'version' => YS_CWCI_VERSION",
    "'plugin_file' => __FILE__",
    "'name' => 'YS CART WooCommerce Import'",
    '}, 5);',
] as $needle) {
    if (strpos($main, $needle) === false) {
        throw new RuntimeException("Missing Hub Client registration marker: {$needle}");
    }
}

foreach ([
    '!/vendor/autoload.php',
    '!/vendor/yangsheep/ys-plugin-hub-client/**',
    '/vendor/yangsheep/ys-plugin-hub-client/tests/',
] as $needle) {
    if (strpos($gitignore, $needle) === false) {
        throw new RuntimeException("Missing .gitignore Hub Client rule: {$needle}");
    }
}
