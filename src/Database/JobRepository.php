<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

final class JobRepository
{
    public function create(string $type, string $entity, array $options = [], int $userId = 0): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->insert(TableMaker::jobsTable(), [
            'type' => $type,
            'entity' => $entity,
            'status' => 'pending',
            'source_fingerprint' => (string)($options['source_fingerprint'] ?? ''),
            'file_path' => (string)($options['file_path'] ?? ''),
            'file_name' => (string)($options['file_name'] ?? ''),
            'cursor_json' => wp_json_encode($options['cursor'] ?? []),
            'options_json' => wp_json_encode($options),
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$wpdb->insert_id;
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $table = TableMaker::jobsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        return $row ?: null;
    }

    public function list(int $limit = 50): array
    {
        global $wpdb;
        $table = TableMaker::jobsTable();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        )) ?: [];
    }

    public function markRunning(int $id): void
    {
        $this->update($id, [
            'status' => 'running',
            'started_at' => current_time('mysql'),
        ]);
    }

    public function updateProgress(int $id, array $patch): void
    {
        $this->update($id, $patch);
    }

    public function updateCursor(int $id, array $cursor): void
    {
        $this->update($id, [
            'cursor_json' => wp_json_encode($cursor),
        ]);
    }

    public function complete(int $id): void
    {
        $this->update($id, [
            'status' => 'completed',
            'completed_at' => current_time('mysql'),
        ]);
    }

    public function cancel(int $id): void
    {
        $this->update($id, [
            'status' => 'cancelled',
            'completed_at' => current_time('mysql'),
        ]);
    }

    public function attachFile(int $id, string $filePath): void
    {
        $this->update($id, [
            'file_path' => $filePath,
            'file_name' => basename($filePath),
        ]);
    }

    public function fail(int $id, string $message): void
    {
        $this->update($id, [
            'status' => 'failed',
        ]);
        (new ErrorRepository())->record($id, 'job', '', $message);
    }

    private function update(int $id, array $patch): void
    {
        global $wpdb;
        $patch['updated_at'] = current_time('mysql');
        $wpdb->update(TableMaker::jobsTable(), $patch, ['id' => $id]);
    }
}
