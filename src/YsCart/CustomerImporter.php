<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

use Throwable;
use YangSheep\YsCartWooImport\Database\ErrorRepository;
use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Database\MapRepository;
use YangSheep\YsCartWooImport\Packages\PackageReader;

final class CustomerImporter
{
    private const YS_CUSTOMER = '\YangSheep\Ecommerce\Models\YSCustomer';

    public function importBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!class_exists(self::YS_CUSTOMER)) {
            return ['done' => true, 'message' => 'YS CART customer model is not available.'];
        }

        $filePath = (string)($options['file_path'] ?? '');
        if ($filePath === '') {
            return ['done' => true, 'message' => 'No package path provided.'];
        }

        $offset = (int)($cursor['offset'] ?? 0);
        $processed = 0;
        $success = 0;
        $fingerprint = (string)($options['source_fingerprint'] ?? '');
        $limit = max(1, (int)($limits['max_rows'] ?? 50));

        foreach ((new PackageReader())->streamJsonLines($filePath, 'customers.jsonl') as $index => $record) {
            if ($index < $offset) {
                continue;
            }

            if ($processed >= $limit) {
                break;
            }

            $processed++;
            try {
                $customerId = $this->importCustomerRecord($jobId, $fingerprint, $record);
                if ($customerId > 0) {
                    $success++;
                }
            } catch (Throwable $e) {
                (new ErrorRepository())->record($jobId, 'customer', (string)($record['source_id'] ?? ''), $e->getMessage(), $record);
            }
        }

        $newOffset = $offset + $processed;
        $done = $processed < $limit;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => $newOffset,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $success,
            'error_count' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);
        $repo->updateCursor($jobId, [
            'offset' => $newOffset,
            'success' => ((int)($cursor['success'] ?? 0)) + $success,
            'errors' => ((int)($cursor['errors'] ?? 0)) + ($processed - $success),
        ]);

        return ['done' => $done, 'processed' => $processed, 'success' => $success];
    }

    public function importCustomerRecord(int $jobId, string $fingerprint, array $record): int
    {
        $email = strtolower(trim((string)($record['email'] ?? '')));
        if ($email === '' || !is_email($email)) {
            throw new \RuntimeException('Customer email is missing or invalid.');
        }

        $userId = $this->ensureUser($email, (string)($record['display_name'] ?? ''), (string)($record['login'] ?? ''));
        $class = self::YS_CUSTOMER;
        $customerId = $class::convert_wp_user($userId);

        if (!$customerId) {
            throw new \RuntimeException('Unable to create YS CART customer.');
        }

        if (method_exists($class, 'update')) {
            $class::update((int)$customerId, CustomerMapper::mapCustomer($record, $userId));
        }

        (new MapRepository())->upsert(
            $jobId,
            $fingerprint,
            'customer',
            (string)($record['source_id'] ?? $email),
            (int)$customerId,
            'ys_customer',
            ['user_id' => $userId, 'email' => $email]
        );

        return (int)$customerId;
    }

    public function ensureCustomerForEmail(int $jobId, string $fingerprint, string $email, array $source = []): array
    {
        $class = self::YS_CUSTOMER;
        $existing = method_exists($class, 'find_by_email') ? $class::find_by_email($email) : null;
        if ($existing) {
            return ['customer_id' => (int)$existing->id, 'user_id' => (int)$existing->user_id];
        }

        $record = [
            'source_id' => 'email:' . $email,
            'email' => $email,
            'display_name' => $source['name'] ?? $email,
            'billing' => $source,
            'shipping' => $source,
        ];
        $customerId = $this->importCustomerRecord($jobId, $fingerprint, $record);
        $customer = method_exists($class, 'find_by_email') ? $class::find_by_email($email) : null;

        return [
            'customer_id' => $customerId,
            'user_id' => $customer ? (int)$customer->user_id : (int)email_exists($email),
        ];
    }

    private function ensureUser(string $email, string $displayName, string $login): int
    {
        $userId = email_exists($email);
        if ($userId) {
            return (int)$userId;
        }

        $baseLogin = sanitize_user($login !== '' ? $login : strstr($email, '@', true));
        if ($baseLogin === '') {
            $baseLogin = 'ys-wc-imported';
        }

        $candidate = $baseLogin;
        $i = 1;
        while (username_exists($candidate)) {
            $candidate = $baseLogin . '-' . $i;
            $i++;
        }

        $userId = wp_insert_user([
            'user_login' => $candidate,
            'user_email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $email,
            'user_pass' => wp_generate_password(32, true, true),
            'role' => get_role('customer') ? 'customer' : 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            throw new \RuntimeException($userId->get_error_message());
        }

        update_user_meta((int)$userId, '_ys_wc_imported_user', 1);
        return (int)$userId;
    }
}
