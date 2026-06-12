<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Admin;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Capabilities\CapabilityDetector;

final class AdminPage
{
    public const SLUG = 'ys-ec-woo-import';
    private const HUB_MENU_SLUG = 'ys-toolbox';
    /** YS CART 核心 takeover chrome 類別（存在時才套用，純匯出站不依賴）。 */
    private const YS_ADMIN_APP = '\YangSheep\Ecommerce\Admin\YSAdminApp';

    public function register(): void
    {
        if ($this->hubMenuExists()) {
            add_submenu_page(
                self::HUB_MENU_SLUG,
                __('YS CART WC 匯入', 'ys-cart-woocommerce-import'),
                __('YS CART WC 匯入', 'ys-cart-woocommerce-import'),
                'manage_options',
                self::SLUG,
                [$this, 'render']
            );
            return;
        }

        add_management_page(
            __('YS CART WC 匯入', 'ys-cart-woocommerce-import'),
            __('YS CART WC 匯入', 'ys-cart-woocommerce-import'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    private function hubMenuExists(): bool
    {
        global $menu;

        if (!is_array($menu)) {
            return false;
        }

        foreach ($menu as $item) {
            if (isset($item[2]) && self::HUB_MENU_SLUG === $item[2]) {
                return true;
            }
        }

        return false;
    }

    public function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }

        wp_enqueue_style(
            'ys-cwci-admin',
            YS_CWCI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            YS_CWCI_VERSION
        );

        wp_enqueue_script(
            'ys-cwci-admin',
            YS_CWCI_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-api-fetch'],
            YS_CWCI_VERSION,
            true
        );

        wp_localize_script('ys-cwci-admin', 'ysCwciAdmin', [
            'restUrl' => esc_url_raw(rest_url('ys-cart-wc-import/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render(): void
    {
        $template = YS_CWCI_PLUGIN_DIR . 'templates/admin/app.php';

        // v0.5.0 UI：偵測到 YS CART 時套用核心 YSAdminApp takeover chrome（側欄＋頂列＋
        // ysca 設計系統），讓匯入頁在買家的 YS CART 後台中原生一致。純匯出站（無 YS CART）
        // 維持既有獨立 .wrap shell，不硬依賴核心 → 兼顧 5 原則與 standalone 運行。
        // 註：核心 YSAdminAssets 已對 ys-ec-* slug 載入 ysca 資產，故此處只需正確掛載 chrome。
        $ys_cwci_chrome = (new CapabilityDetector())->hasYsCart()
            && class_exists(self::YS_ADMIN_APP);

        if ($ys_cwci_chrome) {
            $app = self::YS_ADMIN_APP;
            $app::open(
                __('WooCommerce 匯入', 'ys-cart-woocommerce-import'),
                __('YS CART / WooCommerce 匯入', 'ys-cart-woocommerce-import')
            );
            require $template;
            $app::close();
            return;
        }

        require $template;
    }
}
