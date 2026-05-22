<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Capabilities;

defined('ABSPATH') || exit;

final class CapabilityDetector
{
    public function hasWooCommerce(): bool
    {
        return function_exists('WC')
            || function_exists('wc_get_orders')
            || class_exists('\WooCommerce');
    }

    public function hasYsCart(): bool
    {
        return defined('YS_ECOMMERCE_TABLE_PREFIX')
            || class_exists('\YangSheep\Ecommerce\YSEcommerce')
            || class_exists('\YangSheep\Ecommerce\Models\YSCustomer');
    }

    public function getCapabilities(): array
    {
        $hasWoo = $this->hasWooCommerce();
        $hasYsCart = $this->hasYsCart();

        return [
            'woocommerce' => $hasWoo,
            'ys_cart' => $hasYsCart,
            'can_export' => $hasWoo,
            'can_import' => $hasYsCart,
            'can_direct_transfer' => $hasWoo && $hasYsCart,
        ];
    }
}

