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

    /**
     * Atomic transition pending → running.
     *
     * v0.2.4 fix (Reviewer #10):
     * 原 update() 無 WHERE status='pending'、若 cron + AS 同時觸發、兩 thread 都看到 pending
     * 都 flip 到 running、雙重 dispatch() 同 offset window → 重複 import 同筆訂單。
     *
     * Fix: 直接 SQL UPDATE 加 status='pending' 條件、check rows_affected==1 判斷是否真的
     * 由本 thread 拿到 lock。Return bool 讓 caller 決定是否繼續 dispatch。
     *
     * BC: 原 method 是 void、改 bool。Caller (JobRunner::runNext) 已會 check job status 後
     * 再 dispatch、即使忽略 return value 也只造成短暫的 status 跳一下、不影響 idempotency
     * (因為 atomic UPDATE 仍只有一個 thread 能成功 flip)。
     *
     * @return bool true 若成功拿到 lock（rows_affected=1）、false 若已被其他 thread 搶走
     */
    public function markRunning(int $id): bool
    {
        global $wpdb;
        $table = TableMaker::jobsTable();
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'running', started_at = %s, updated_at = %s
             WHERE id = %d AND status = 'pending'",
            $now,
            $now,
            $id
        ));
        // false on DB error、0 on no-match (already running/done)、1 on success
        return $result === 1;
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

    /**
     * Persist batch counters and replay cursor in one checked database write.
     */
    public function updateProgressAndCursor(int $id, array $progress, array $cursor): void
    {
        global $wpdb;

        $progress['cursor_json'] = wp_json_encode($cursor);
        $progress['updated_at'] = current_time('mysql');
        $result = $wpdb->update(TableMaker::jobsTable(), $progress, ['id' => $id]);
        if (false === $result) {
            throw new \RuntimeException(
                'Unable to persist import progress and cursor: ' . (string)$wpdb->last_error
            );
        }
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
