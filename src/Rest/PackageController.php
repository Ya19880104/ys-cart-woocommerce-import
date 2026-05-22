<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use RuntimeException;
use YangSheep\YsCartWooImport\Packages\PackageReader;

final class PackageController
{
    public function upload($request)
    {
        $files = $request->get_file_params();
        if (empty($files['package']['tmp_name'])) {
            return new \WP_Error('ys_cwci_no_package', __('No package uploaded.', 'ys-cart-woocommerce-import'), ['status' => 400]);
        }

        $file = $files['package'];
        $name = sanitize_file_name((string)$file['name']);
        if (!str_ends_with(strtolower($name), '.zip')) {
            return new \WP_Error('ys_cwci_invalid_package', __('Package must be a zip file.', 'ys-cart-woocommerce-import'), ['status' => 400]);
        }

        $upload = wp_upload_dir();
        $dir = trailingslashit((string)$upload['basedir']) . 'ys-cart-wc-import/uploads';
        wp_mkdir_p($dir);
        $target = $dir . '/' . wp_generate_uuid4() . '-' . $name;

        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return new \WP_Error('ys_cwci_upload_failed', __('Package upload failed.', 'ys-cart-woocommerce-import'), ['status' => 500]);
        }

        try {
            $manifest = (new PackageReader())->readManifest($target);
        } catch (RuntimeException $e) {
            @unlink($target);
            return new \WP_Error('ys_cwci_invalid_manifest', $e->getMessage(), ['status' => 400]);
        }

        return [
            'file_path' => $target,
            'file_name' => basename($target),
            'manifest' => $manifest,
        ];
    }

    public function preview($request): array
    {
        return [
            'package_id' => (int)$request['id'],
            'message' => __('Package preview endpoint is ready.', 'ys-cart-woocommerce-import'),
        ];
    }
}

