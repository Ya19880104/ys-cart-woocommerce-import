<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Woo;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Packages\PackageWriter;

final class CustomerExporter
{
    public function exportBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!class_exists('\WP_User_Query')) {
            return ['done' => true, 'message' => 'WordPress user query is not available.'];
        }

        $page = max(1, (int)($cursor['page'] ?? 1));
        $alreadyProcessed = (int)($cursor['processed'] ?? 0);
        $maxTotal = max(0, (int)($options['max_total'] ?? 0));
        if ($maxTotal > 0 && $alreadyProcessed >= $maxTotal) {
            return ['done' => true, 'processed' => 0];
        }

        $limit = max(1, min(100, (int)($limits['max_rows'] ?? 50)));
        if ($maxTotal > 0) {
            $limit = min($limit, $maxTotal - $alreadyProcessed);
        }
        $packageId = $options['package_id'] ?? ('job-' . $jobId);

        $query = new \WP_User_Query([
            'role__in' => ['customer', 'subscriber'],
            'meta_query' => [
                [
                    'key' => '_ys_wc_imported_user',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'number' => $limit,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $users = $query->get_results();
        $writer = new PackageWriter();

        foreach ($users as $user) {
            $writer->appendJsonLine($packageId, 'customers.jsonl', $this->serializeUser($user));
        }

        $processed = count($users);
        $done = $processed < $limit;
        $repo = new JobRepository();
        $repo->updateProgressAndCursor($jobId, [
            'processed_count' => $alreadyProcessed + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $processed,
        ], [
            'page' => $page + 1,
            'processed' => $alreadyProcessed + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);

        return ['done' => $done || ($maxTotal > 0 && $alreadyProcessed + $processed >= $maxTotal), 'processed' => $processed];
    }

    private function serializeUser(\WP_User $user): array
    {
        return [
            'source_id' => (string)$user->ID,
            'email' => strtolower((string)$user->user_email),
            'login' => (string)$user->user_login,
            'display_name' => (string)$user->display_name,
            'registered_at' => (string)$user->user_registered,
            'billing' => $this->metaAddress($user->ID, 'billing'),
            'shipping' => $this->metaAddress($user->ID, 'shipping'),
        ];
    }

    private function metaAddress(int $userId, string $prefix): array
    {
        return [
            'first_name' => (string)get_user_meta($userId, "{$prefix}_first_name", true),
            'last_name' => (string)get_user_meta($userId, "{$prefix}_last_name", true),
            'company' => (string)get_user_meta($userId, "{$prefix}_company", true),
            'address_1' => (string)get_user_meta($userId, "{$prefix}_address_1", true),
            'address_2' => (string)get_user_meta($userId, "{$prefix}_address_2", true),
            'city' => (string)get_user_meta($userId, "{$prefix}_city", true),
            'state' => (string)get_user_meta($userId, "{$prefix}_state", true),
            'postcode' => (string)get_user_meta($userId, "{$prefix}_postcode", true),
            'country' => (string)get_user_meta($userId, "{$prefix}_country", true),
            'email' => $prefix === 'billing' ? (string)get_user_meta($userId, 'billing_email', true) : '',
            'phone' => $prefix === 'billing' ? (string)get_user_meta($userId, 'billing_phone', true) : '',
        ];
    }
}
