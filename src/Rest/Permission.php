<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

final class Permission
{
    public static function admin($request)
    {
        $ysAuth = '\YangSheep\Ecommerce\Api\Admin\YSAdminRestAuth';
        if (class_exists($ysAuth) && method_exists($ysAuth, 'permission_admin')) {
            return $ysAuth::permission_admin($request);
        }

        if (!current_user_can('manage_options')) {
            return new \WP_Error('ys_cwci_forbidden', __('Forbidden.', 'ys-cart-woocommerce-import'), ['status' => 403]);
        }

        $method = method_exists($request, 'get_method') ? strtoupper((string)$request->get_method()) : 'GET';
        $nonce = method_exists($request, 'get_header') ? (string)$request->get_header('X-WP-Nonce') : '';
        if ($method !== 'GET' && (!$nonce || !wp_verify_nonce($nonce, 'wp_rest'))) {
            return new \WP_Error('ys_cwci_invalid_nonce', __('Invalid REST nonce.', 'ys-cart-woocommerce-import'), ['status' => 403]);
        }

        return true;
    }
}
