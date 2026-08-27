<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Admin;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Rest\RestController;

final class AdminPage
{
    public const SLUG = 'ys-ec-woo-import';
    private const HUB_MENU_SLUG = 'ys-toolbox';

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

        // v0.7.1 舊核心相容：YS CART < 2.52.31 會因本頁 slug 撞 ys-ec-* 前綴而誤載
        // 其 admin 資產並加上 ysca-active / ysca-hide-wp-chrome body class（隱藏 WP 選單、
        // 卻沒有 shell 可掛）→ 破版。新核心已把本頁列入 WP-native 排除清單；
        // 舊核心由本外掛自行中和：晚序拔掉 body class + 反註冊核心 admin 樣式/腳本。
        $coreVersion = defined('YS_ECOMMERCE_VERSION') ? (string)YS_ECOMMERCE_VERSION : '';
        if ($coreVersion !== '' && version_compare($coreVersion, '2.52.31', '<')) {
            add_filter('admin_body_class', static function ($classes): string {
                return trim((string)preg_replace('/\bysca-[a-z0-9_-]+\b/', '', (string)$classes));
            }, 999);
            add_action('admin_enqueue_scripts', static function (): void {
                foreach (['tokens', 'shell', 'components', 'pages', 'legacy-bridge', 'product-card', 'seo-fields'] as $suffix) {
                    wp_dequeue_style('ys-cart-admin-' . $suffix);
                }
                foreach (['api', 'shell', 'table'] as $suffix) {
                    wp_dequeue_script('ys-cart-admin-' . $suffix);
                }
            }, 999);
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

        $apiPath = RestController::apiPath();

        wp_localize_script('ys-cwci-admin', 'ysCwciAdmin', [
            'apiPath' => $apiPath,
            'restUrl' => esc_url_raw(rest_url(ltrim($apiPath, '/'))),
            'nonce' => wp_create_nonce('wp_rest'),
            // v0.6.0 精靈模式：同站直轉的 import job 需要與 export manifest 相同的
            // source fingerprint（hash of home_url）；由 PHP 算好傳下去，避免 JS 端
            // 因 home_url 含子目錄/尾斜線差異算錯。
            'homeUrl' => esc_url_raw(home_url()),
            'siteFingerprint' => hash('sha256', home_url()),
        ]);
    }

    public function render(): void
    {
        // v0.7.1：匯入工具是「獨立外掛」— 可在無 YS CART 環境使用、不屬於 YS CART 選單，
        // 一律以自身獨立 UI 渲染（移除 v0.5.0 的條件式 YSAdminApp 包裹）。
        // 配合核心 >= 2.52.31 已將本頁列入 WP-native 排除清單（不載 YS CSS / 不進 takeover）。
        require YS_CWCI_PLUGIN_DIR . 'templates/admin/app.php';
    }
}
