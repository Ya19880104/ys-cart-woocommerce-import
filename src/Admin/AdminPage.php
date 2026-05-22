<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Admin;

defined('ABSPATH') || exit;

final class AdminPage
{
    public const SLUG = 'ys-cart-woocommerce-import';

    public function register(): void
    {
        add_management_page(
            __('YS CART Woo Import', 'ys-cart-woocommerce-import'),
            __('YS CART Woo Import', 'ys-cart-woocommerce-import'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
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

