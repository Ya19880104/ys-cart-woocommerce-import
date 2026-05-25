<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Admin;

defined('ABSPATH') || exit;

final class AdminPage
{
    public const SLUG = 'ys-ec-woo-import';
    private const HUB_MENU_SLUG = 'ys-toolbox';

    public function register(): void
    {
        if ($this->hubMenuExists()) {
            add_submenu_page(
                self::HUB_MENU_SLUG,
                __('WooCommerce 匯入', 'ys-cart-woocommerce-import'),
                __('WooCommerce 匯入', 'ys-cart-woocommerce-import'),
                'manage_options',
                self::SLUG,
                [$this, 'render']
            );
            return;
        }

        add_management_page(
            __('YS CART Woo 匯入', 'ys-cart-woocommerce-import'),
            __('YS CART Woo 匯入', 'ys-cart-woocommerce-import'),
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
        require YS_CWCI_PLUGIN_DIR . 'templates/admin/app.php';
    }
}
