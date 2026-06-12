<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use RuntimeException;
use YangSheep\YsCartWooImport\Packages\PackageReader;
use YangSheep\YsCartWooImport\Security\ProtectedDirectory;

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
        ProtectedDirectory::ensure($dir, ['.zip']);
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

    /**
     * v0.7.0：掃描套件 orders.jsonl，回傳各 Woo 狀態的筆數與預設對應。
     *
     * 前端據此渲染「訂單狀態選擇（預設全選）」與「未對應狀態詢問對應」UI。
     * 串流逐行讀取（constant memory），file_path 必須在 uploads 內（防任意檔讀取）。
     */
    public function orderStatuses($request)
    {
        $filePath = Permission::safePackagePath((string)($request['file_path'] ?? ''));
        if ($filePath === null || !is_file($filePath)) {
            return new \WP_Error(
                'ys_cwci_invalid_path',
                __('Package file_path must be an existing file inside the WordPress upload directory.', 'ys-cart-woocommerce-import'),
                ['status' => 400]
            );
        }

        $counts = [];
        try {
            foreach ((new PackageReader())->streamJsonLines($filePath, 'orders.jsonl') as $record) {
                $status = (string)($record['status'] ?? '');
                if ($status === '') {
                    $status = '(unknown)';
                }
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        } catch (RuntimeException $e) {
            return new \WP_Error('ys_cwci_invalid_package', $e->getMessage(), ['status' => 400]);
        }

        $defaultMap = \YangSheep\YsCartWooImport\YsCart\OrderMapper::statusMap();
        $statuses = [];
        foreach ($counts as $status => $count) {
            $statuses[] = [
                'status' => $status,
                'count' => $count,
                'mapped_to' => $defaultMap[$status] ?? null, // null = 未對應，前端需詢問
            ];
        }
        usort($statuses, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'statuses' => $statuses,
            'ys_statuses' => \YangSheep\YsCartWooImport\YsCart\OrderMapper::YS_STATUSES,
            'total' => array_sum($counts),
        ];
    }
}
