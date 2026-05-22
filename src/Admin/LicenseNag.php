<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Admin;

use YangSheep\YsCartWooImport\Licensing\LicenseState;

defined('ABSPATH') || exit;

final class LicenseNag
{
    private string $modalClass = '';

    public function shouldNag(): bool
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        return !LicenseState::isAllowed();
    }

    public function enqueue(): void
    {
        if (!$this->shouldNag()) {
            return;
        }

        $this->modalClass = 'ys-cwci-license-' . substr(wp_hash(microtime(true) . '|' . wp_rand()), 0, 10);

        wp_enqueue_style(
            'ys-cwci-license-nag',
            YS_CWCI_PLUGIN_URL . 'assets/css/license-nag.css',
            [],
            YS_CWCI_VERSION
        );

        wp_enqueue_script(
            'ys-cwci-license-nag',
            YS_CWCI_PLUGIN_URL . 'assets/js/license-nag.js',
            [],
            YS_CWCI_VERSION,
            true
        );

        $state = LicenseState::publicState();
        wp_localize_script('ys-cwci-license-nag', 'ysCwciLicenseNag', [
            'modalClass' => $this->modalClass,
            'status' => (string)$state['status'],
            'errorCode' => (string)$state['last_error_code'],
            'licenseUrl' => admin_url('tools.php?page=' . AdminPage::SLUG . '#ys-cwci-license'),
            'title' => __('YS CART Woo Import is not licensed', 'ys-cart-woocommerce-import'),
            'body' => __('Enter a license key to keep this migration add-on eligible for official support and updates.', 'ys-cart-woocommerce-import'),
            'buttonLabel' => __('Open license settings', 'ys-cart-woocommerce-import'),
            'closeLabel' => __('Close this page reminder', 'ys-cart-woocommerce-import'),
        ]);
    }

    public function renderSeed(): void
    {
        if (!$this->shouldNag()) {
            return;
        }

        echo '<div id="ys-cwci-license-nag-seed" data-ys-cwci-license-seed="1" hidden></div>';
    }
}
