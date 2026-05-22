<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

final class MapRepository
{
    public function find(string $fingerprint, string $entity, string $sourceId): ?object
    {
        global $wpdb;
        $table = TableMaker::mapsTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_fingerprint = %s AND entity = %s AND source_id = %s",
            $fingerprint,
            $entity,
            $sourceId
        ));

        return $row ?: null;
    }

    public function upsert(int $jobId, string $fingerprint, string $entity, string $sourceId, int $targetId, string $targetType, array $extra = []): void
    {
        global $wpdb;
        $table = TableMaker::mapsTable();
        $now = current_time('mysql');
        $existing = $this->find($fingerprint, $entity, $sourceId);
        $data = [
            'job_id' => $jobId,
            'source_fingerprint' => $fingerprint,
            'entity' => $entity,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'target_type' => $targetType,
            'extra_json' => wp_json_encode($extra),
            'updated_at' => $now,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int)$existing->id]);
            return;
        }

        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
    }
}

