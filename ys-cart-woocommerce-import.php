<?php
/**
 * Plugin Name: YS CART WC 匯入
 * Plugin URI:  https://yangsheep.com.tw
 * Description: WooCommerce 客戶、商品與訂單移轉到 YS CART 的匯出匯入工具，支援可續跑 REST 批次工作。
 * Version:     0.7.9
 * Author:      YANGSHEEP DESIGN
 * Author URI:  https://yangsheep.com.tw
 * License:     GPL-2.0-or-later
 * Text Domain: ys-cart-woocommerce-import
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package YangSheep\YsCartWooImport
 */

defined('ABSPATH') || exit;

define('YS_CWCI_VERSION', '0.7.9');
define('YS_CWCI_PLUGIN_FILE', __FILE__);
define('YS_CWCI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YS_CWCI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YS_CWCI_BASENAME', plugin_basename(__FILE__));

if (file_exists(YS_CWCI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once YS_CWCI_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'YangSheep\\YsCartWooImport\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = YS_CWCI_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

register_activation_hook(__FILE__, static function (): void {
    \YangSheep\YsCartWooImport\Database\TableMaker::createTables();
});

add_action('plugins_loaded', static function (): void {
    if (class_exists('\YangSheep\PluginHubClient\YSPluginHubClient')) {
        \YangSheep\PluginHubClient\YSPluginHubClient::register([
            'slug' => 'ys-cart-woocommerce-import',
            'version' => YS_CWCI_VERSION,
            'plugin_file' => __FILE__,
            'name' => 'YS CART WC 匯入',
        ]);
    }
}, 5);

add_action('plugins_loaded', static function (): void {
    \YangSheep\YsCartWooImport\Plugin::instance()->init();
}, 20);
