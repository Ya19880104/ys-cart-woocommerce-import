<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Rest;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Jobs\JobRunner;
use YangSheep\YsCartWooImport\Jobs\Scheduler;

final class JobController
{
    /** v0.5.0 H2：export/import 僅支援這三種 entity（對齊 JobRunner::dispatch 的 match 分支）。 */
    private const SUPPORTED_ENTITIES = ['customers', 'products', 'orders'];

    public function createExportJob($request)
    {
        $entity = sanitize_key((string)($request['entity'] ?? 'orders'));
        if (!in_array($entity, self::SUPPORTED_ENTITIES, true)) {
            return $this->invalidEntityError($entity);
        }
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        // v0.2.4 sanitize package_id (Reviewer #5 配套): export 也允許 caller 帶 package_id、
        // safePackageId 確保 zip path 不會被 traversal。實際 PackageWriter::zip() 已加 safePackageId、
        // 此處只做 normalize 讓 DB 內 stored 的 package_id 也乾淨。
        $packageId = (string)($options['package_id'] ?? '');
        if ($packageId === '') {
            $options['package_id'] = 'export-' . wp_generate_uuid4();
        } else {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $packageId);
            $options['package_id'] = ($clean === '' || $clean === null) ? ('export-' . wp_generate_uuid4()) : $clean;
        }
        $jobId = (new JobRepository())->create('export', $entity, $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function createImportJob($request)
    {
        $entity = sanitize_key((string)($request['entity'] ?? 'orders'));
        if (!in_array($entity, self::SUPPORTED_ENTITIES, true)) {
            return $this->invalidEntityError($entity);
        }
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        // v0.2.4 path canonicalize (Reviewer #1):
        // file_path 必須在 WP upload dir 內、防 admin 送 "/var/www/.../wp-config.php" 任意檔案讀。
        if (!empty($options['file_path'])) {
            $safe = Permission::safePackagePath((string)$options['file_path']);
            if ($safe === null) {
                return new \WP_Error(
                    'ys_cwci_invalid_path',
                    __('Package file_path must be inside WordPress upload directory.', 'ys-cart-woocommerce-import'),
                    ['status' => 400]
                );
            }
            $options['file_path'] = $safe;
        }

        // package_id sanitize
        if (!empty($options['package_id'])) {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$options['package_id']);
            if ($clean === '' || $clean === null) {
                unset($options['package_id']);
            } else {
                $options['package_id'] = $clean;
            }
        }

        // v0.7.0：匯入模式 + 訂單狀態控制（全部 allowlist 驗證、壞值剔除不報錯）。
        if (isset($options['mode'])) {
            $mode = sanitize_key((string)$options['mode']);
            if (in_array($mode, ['skip', 'overwrite', 'update'], true)) {
                $options['mode'] = $mode;
            } else {
                unset($options['mode']);
            }
        }
        if (isset($options['status_include'])) {
            $include = [];
            foreach ((array)$options['status_include'] as $status) {
                $status = sanitize_key((string)$status);
                if ($status !== '') {
                    $include[] = $status;
                }
            }
            $options['status_include'] = array_values(array_unique($include));
        }
        if (isset($options['status_map'])) {
            $map = [];
            foreach ((array)$options['status_map'] as $from => $to) {
                $from = sanitize_key((string)$from);
                $to = sanitize_key((string)$to);
                if ($from !== '' && in_array($to, \YangSheep\YsCartWooImport\YsCart\OrderMapper::YS_STATUSES, true)) {
                    $map[$from] = $to;
                }
            }
            $options['status_map'] = $map;
        }

        $jobId = (new JobRepository())->create('import', $entity, $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function listJobs(): array
    {
        return (new JobRepository())->list();
    }

    private function invalidEntityError(string $entity): \WP_Error
    {
        return new \WP_Error(
            'ys_cwci_invalid_entity',
            sprintf(
                /* translators: %1$s = requested entity, %2$s = comma-separated supported entities */
                __('Unsupported entity "%1$s". Supported: %2$s.', 'ys-cart-woocommerce-import'),
                $entity,
                implode(', ', self::SUPPORTED_ENTITIES)
            ),
            ['status' => 400]
        );
    }

    public function createDirectJob($request)
    {
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        // v0.2.4 path canonicalize (Reviewer #1): 同 createImportJob
        if (!empty($options['file_path'])) {
            $safe = Permission::safePackagePath((string)$options['file_path']);
            if ($safe === null) {
                return new \WP_Error(
                    'ys_cwci_invalid_path',
                    __('Package file_path must be inside WordPress upload directory.', 'ys-cart-woocommerce-import'),
                    ['status' => 400]
                );
            }
            $options['file_path'] = $safe;
        }

        // package_id sanitize
        if (!empty($options['package_id'])) {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$options['package_id']);
            if ($clean === '' || $clean === null) {
                unset($options['package_id']);
            } else {
                $options['package_id'] = $clean;
            }
        }

        $jobId = (new JobRepository())->create('direct', 'all', $options, get_current_user_id());
        (new Scheduler())->enqueue($jobId);

        return ['id' => $jobId, 'status' => 'pending'];
    }

    public function runNext($request)
    {
        return (new JobRunner())->runNext((int)$request['id']);
    }

    public function getJob($request)
    {
        $job = (new JobRepository())->find((int)$request['id']);
        if (!$job) {
            return new \WP_Error('ys_cwci_job_missing', __('Job not found.', 'ys-cart-woocommerce-import'), ['status' => 404]);
        }

        return $job;
    }

    public function getErrors($request): array
    {
        return (new ErrorRepository())->listForJob((int)$request['id']);
    }

    public function cancel($request): array
    {
        (new JobRepository())->cancel((int)$request['id']);
        return ['id' => (int)$request['id'], 'status' => 'cancelled'];
    }

    public function download($request)
    {
        $job = (new JobRepository())->find((int)$request['id']);
        if (!$job || empty($job->file_path) || !is_file((string)$job->file_path)) {
            return new \WP_Error('ys_cwci_file_missing', __('Package file not found.', 'ys-cart-woocommerce-import'), ['status' => 404]);
        }

        // v0.2.4 path canonicalize (Reviewer #1):
        // 雖然 file_path 在 createXxxJob 已 canonicalize、但 DB 內 stored 的值仍可能被
        // 早期 install / migration / 直接 DB write 污染、download 時再 verify 一次（defense in depth）。
        $safe = Permission::safePackagePath((string)$job->file_path);
        if ($safe === null) {
            return new \WP_Error(
                'ys_cwci_invalid_path',
                __('Package file path outside upload directory.', 'ys-cart-woocommerce-import'),
                ['status' => 403]
            );
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($safe) . '"');
        header('Content-Length: ' . filesize($safe));
        readfile($safe);
        exit;
    }
}
