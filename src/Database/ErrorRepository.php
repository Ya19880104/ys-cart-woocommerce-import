<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

final class ErrorRepository
{
    public function record(int $jobId, string $entity, string $sourceId, string $message, array $context = []): void
    {
        global $wpdb;
        $wpdb->insert(TableMaker::errorsTable(), [
            'job_id' => $jobId,
            'entity' => $entity,
            'source_id' => $sourceId,
            'message' => $message,
            'context_json' => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function listForJob(int $jobId, int $limit = 200): array
    {
        global $wpdb;
        $table = TableMaker::errorsTable();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC LIMIT %d",
            $jobId,
            $limit
        )) ?: [];
    }
}

