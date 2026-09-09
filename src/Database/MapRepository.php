<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

final class MapRepository
{
    public function find(string $fingerprint, string $entity, string $sourceId): ?object
    {
        global $wpdb;
        $source = '\\YangSheep\\Ecommerce\\Services\\Projection\\YSProductImportSource';
        $coordinator = '\\YangSheep\\Ecommerce\\Services\\Projection\\YSCatalogMutationCoordinator';
        if (in_array($entity, ['product', 'digital_file'], true) && method_exists($source, 'find')) {
            if (method_exists($coordinator, 'unavailable') && $coordinator::unavailable($wpdb)) {
                throw new \YangSheep\Ecommerce\Services\Projection\YSCatalogMutationUncertain('Product source connection requires reconciliation.');
            }
            // A variation owns its own source record; split only the first separator.
            [$owner, $item] = $entity === 'digital_file' ? array_pad(explode(':', $sourceId, 2), 2, '') : [$sourceId, ''];
            $result = $source::find($wpdb, 'woocommerce', $fingerprint, $entity, $owner, $item);
            if (($result['status'] ?? '') === 'found') {
                return (object)['target_id'=>(int)$result['target_id'], 'target_type'=>$result['target_type'],
                    'product_id'=>(int)$result['product_id']];
            }
            if (($result['status'] ?? '') !== 'missing') {
                throw new \RuntimeException('Core product source identity is unavailable.');
            }
        }
        return $this->findLegacy($fingerprint, $entity, $sourceId);
    }

    /** Legacy writers need a real Woo row id, never a Core authority row. */
    public function findLegacy(string $fingerprint, string $entity, string $sourceId): ?object
    {
        global $wpdb;
        $table = TableMaker::mapsTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_fingerprint = %s AND entity = %s AND source_id = %s",
            $fingerprint,
            $entity,
            $sourceId
        ));
        if ((string)($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Woo migration source map read failed.');
        }

        return $row ?: null;
    }

    public function upsert(int $jobId, string $fingerprint, string $entity, string $sourceId, int $targetId, string $targetType, array $extra = []): void
    {
        global $wpdb;
        $table = TableMaker::mapsTable();
        $now = current_time('mysql');
        $existing = $this->findLegacy($fingerprint, $entity, $sourceId);
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
