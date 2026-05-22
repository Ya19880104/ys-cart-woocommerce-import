<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

final class Permission
{
    public static function admin($request)
    {
        return self::check($request, false);
    }

    public static function adminDownload($request)
    {
        return self::check($request, true);
    }

    /**
     * v0.2.4 (Reviewer #1 #2 #3):
     * 統一的 admin permission check、兩層 gate:
     *   Layer 1: Nonce - non-GET 強制、$requireNonceOnGet=true 連 GET 也強制 (給 /download 用)
     *            檢查在 YSAdminRestAuth delegation 之上、永遠 enforce 一次
     *   Layer 2: Capability - manage_options 或 YSAdminRestAuth delegate
     */
    private static function check($request, bool $requireNonceOnGet)
    {
        $method = method_exists($request, 'get_method') ? strtoupper((string)$request->get_method()) : 'GET';
        $needsNonce = $requireNonceOnGet || $method !== 'GET';

        if ($needsNonce) {
            $nonce = '';
            if (method_exists($request, 'get_header')) {
                $nonce = (string)$request->get_header('X-WP-Nonce');
            }
            if ($nonce === '' && method_exists($request, 'get_param')) {
                $nonce = (string)$request->get_param('_wpnonce');
            }
            if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new \WP_Error(
                    'ys_cwci_invalid_nonce',
                    __('Invalid REST nonce.', 'ys-cart-woocommerce-import'),
                    ['status' => 403]
                );
            }
        }

        $ysAuth = '\YangSheep\Ecommerce\Api\Admin\YSAdminRestAuth';
        if (class_exists($ysAuth) && method_exists($ysAuth, 'permission_admin')) {
            return $ysAuth::permission_admin($request);
        }

        if (!current_user_can('manage_options')) {
            return new \WP_Error(
                'ys_cwci_forbidden',
                __('Forbidden.', 'ys-cart-woocommerce-import'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * v0.2.4 (Reviewer #1) — path canonicalize.
     * realpath + 必須以 WP upload dir 為 prefix。防 admin 送任意檔案路徑讀取 wp-config 等。
     *
     * @param string $rawPath  caller-controlled file path
     * @return string|null     canonicalized abs path if safe; null if rejected
     */
    public static function safePackagePath(string $rawPath): ?string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return null;
        }

        $upload = wp_upload_dir();
        $uploadBase = isset($upload['basedir']) ? rtrim((string)$upload['basedir'], DIRECTORY_SEPARATOR . '/') : '';
        if ($uploadBase === '') {
            return null;
        }

        $real = realpath($rawPath);
        if ($real === false) {
            return null;
        }
        $realUploadBase = realpath($uploadBase);
        if ($realUploadBase === false) {
            return null;
        }
        if (!str_starts_with($real, $realUploadBase . DIRECTORY_SEPARATOR) && $real !== $realUploadBase) {
            return null;
        }

        return $real;
    }
}
