<?php
/**
 * Real Woo importBatch + mapper + transaction + repositories; finite I/O only.
 * Core create/update refusal and one successful update are controlled inputs.
 * This proves importer handling, not a real Core/Woo/native-MySQL pairing.
 * No file, database, HTTP or mail writes.
 * Run: php tests/unit/product-core-write-refusal.php
 */
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
    function current_time(string $kind): string { return '2031-01-01 00:00:00'; }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }

    final class WooRefusalDb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public bool $in_transaction = false;
        public array $trace = [];
        public array $attributes = [['product_id' => 77, 'attribute_name' => 'Existing', 'attribute_values' => '["Old"]']];
        public array $maps = [];
        public array $errors = [];
        public array $progress = [];
        private array $snapshot = [];

        public function query(string $sql): int {
            $this->trace[] = ['query' => $sql];
            if ('START TRANSACTION' === $sql) {
                if ($this->in_transaction) { throw new \RuntimeException('unexpected nested fixture transaction'); }
                $this->snapshot = [$this->attributes, $this->maps];
                $this->in_transaction = true;
                return 0;
            }
            if ('ROLLBACK' === $sql) {
                [$this->attributes, $this->maps] = $this->snapshot;
                $this->in_transaction = false;
                return 0;
            }
            if ('COMMIT' === $sql) { $this->in_transaction = false; return 0; }
            throw new \RuntimeException('unexpected fixture query');
        }
        public function prepare(string $sql, mixed ...$args): string {
            foreach ($args as $arg) {
                $value = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
                $sql = preg_replace_callback('/%[sd]/', static fn(): string => $value, $sql, 1);
            }
            return $sql;
        }
        public function get_row(string $sql): ?object {
            if (!str_starts_with($sql, 'SELECT * FROM wp_ys_wc_migration_maps WHERE ')) {
                throw new \RuntimeException('unexpected fixture read');
            }
            $this->trace[] = ['map_read' => $sql];
            return null;
        }
        public function delete(string $table, array $where): int {
            if ('wp_ys_ec_product_attributes' !== $table || ['product_id' => 77] !== $where) {
                throw new \RuntimeException('unexpected fixture delete');
            }
            $this->trace[] = ['delete' => $table, 'where' => $where];
            $this->attributes = [];
            return 1;
        }
        public function insert(string $table, array $data): int {
            $this->trace[] = ['insert' => $table, 'data' => $data];
            if ('wp_ys_ec_product_attributes' === $table) { $this->attributes[] = $data; }
            elseif ('wp_ys_wc_migration_maps' === $table) { $this->maps[] = $data; }
            elseif ('wp_ys_wc_migration_errors' === $table) { $this->errors[] = $data; }
            else { throw new \RuntimeException('unexpected fixture insert'); }
            ++$this->insert_id;
            return 1;
        }
        public function update(string $table, array $data, array $where): int {
            if ('wp_ys_wc_migration_jobs' !== $table || ['id' => 41] !== $where) {
                throw new \RuntimeException('unexpected fixture update');
            }
            $this->trace[] = ['update' => $table, 'data' => $data];
            $this->progress = $data;
            return 1;
        }
    }
}

namespace YangSheep\Ecommerce\Models {
    final class YSProduct {
        public static bool $existing = true;
        public static bool $update_result = false;
        public static array $calls = [];
        public static function find_by_sku(string $sku): ?object {
            return self::$existing ? (object)['id' => 77] : null;
        }
        public static function find_by_slug(string $slug): ?object { return null; }
        public static function update(int $id, array $data): bool {
            self::$calls[] = ['operation' => 'update', 'id' => $id, 'in_transaction' => $GLOBALS['wpdb']->in_transaction];
            return self::$update_result;
        }
        public static function create(array $data): int|false {
            self::$calls[] = ['operation' => 'create', 'in_transaction' => $GLOBALS['wpdb']->in_transaction];
            return false;
        }
    }
}

namespace YangSheep\YsCartWooImport\Packages {
    final class PackageReader {
        public static int $productRows = 1;
        public function streamJsonLines(string $path, string $entry): \Generator {
            if ('memory-only' === $path && 'product_variants.jsonl' === $entry) { return; }
            if ('memory-only' !== $path || 'products.jsonl' !== $entry) {
                throw new \RuntimeException('unexpected fixture package request');
            }
            for ($index = 0; $index < self::$productRows; ++$index) { yield [
                'source_id' => 'woo-fixture-77', 'name' => 'Requested new title',
                'sku' => 'FIXTURE-SKU-77', 'slug' => 'fixture-77', 'status' => 'publish',
                'attributes' => [['name' => 'Imported', 'options' => ['New']]],
                'downloads' => [],
            ]; }
        }
    }
}

namespace {
    use YangSheep\YsCartWooImport\YsCart\ProductImporter;
    use YangSheep\Ecommerce\Models\YSProduct;

    $root = realpath($argv[1] ?? dirname(__DIR__, 2));
    if (false === $root) { throw new \RuntimeException('importer checkout missing'); }
    $files = ['Database/TableMaker.php', 'Database/Transaction.php', 'Database/MapRepository.php',
        'Database/ErrorRepository.php', 'Database/JobRepository.php', 'YsCart/ProductMapper.php', 'YsCart/ProductImporter.php'];
    $source_hashes = [];
    foreach ($files as $file) {
        $path = $root . '/src/' . $file;
        if (!is_file($path)) { throw new \RuntimeException('required importer source missing'); }
        $source_hashes['src/' . $file] = hash_file('sha256', $path);
        require $path;
    }
    $pass = 0;
    $fail = 0;
    $cases = [];
    foreach (['update' => true, 'create' => false] as $operation => $existing) {
        $db = new WooRefusalDb();
        $GLOBALS['wpdb'] = $db;
        YSProduct::$existing = $existing;
        YSProduct::$update_result = false;
        YSProduct::$calls = [];
        $before = $db->attributes;
        $result = (new ProductImporter())->importBatch(41,
            ['file_path' => 'memory-only', 'source_fingerprint' => 'fixture', 'sideload_images' => false, 'mode' => 'update'],
            ['stage' => 'products'], ['max_rows' => 1]);
        $queries = array_column($db->trace, 'query');
        $attribute_dml = array_values(array_filter($db->trace, static fn(array $entry): bool =>
            'wp_ys_ec_product_attributes' === ($entry['delete'] ?? null)
            || 'wp_ys_ec_product_attributes' === ($entry['insert'] ?? null)));
        $map_dml = array_values(array_filter($db->trace, static fn(array $entry): bool =>
            'wp_ys_wc_migration_maps' === ($entry['insert'] ?? null)));
        $checks = [
            'real import reaches the explicit false Core boundary inside its outer transaction' =>
                1 === count(YSProduct::$calls) && $operation === YSProduct::$calls[0]['operation']
                && true === YSProduct::$calls[0]['in_transaction'],
            'Core refusal must not be reported or persisted as success' =>
                0 === ($result['success'] ?? null) && 0 === ($db->progress['success_count'] ?? null),
            'Core refusal must stop before attribute and map DML' => [] === $attribute_dml && [] === $map_dml,
            'Core refusal must leave existing attributes and maps unchanged' => $before === $db->attributes && [] === $db->maps,
            'Core refusal must roll back and record one failed product' =>
                !in_array('COMMIT', $queries, true) && in_array('ROLLBACK', $queries, true)
                && 1 === count($db->errors) && 1 === ($db->progress['error_count'] ?? null),
        ];
        foreach ($checks as $label => $ok) {
            if ($ok) { ++$pass; } else { ++$fail; }
            echo ($ok ? 'PASS ' : 'FAIL ') . $operation . ': ' . $label . "\n";
        }
        $cases[$operation] = ['result' => $result, 'core_calls' => YSProduct::$calls, 'checks' => $checks,
            'attributes_after' => $db->attributes, 'maps_after' => $db->maps, 'progress' => $db->progress, 'trace' => $db->trace];
    }
    $db = new WooRefusalDb();
    $GLOBALS['wpdb'] = $db;
    YSProduct::$existing = true;
    YSProduct::$update_result = true;
    YSProduct::$calls = [];
    $accepted = (new ProductImporter())->importBatch(41,
        ['file_path' => 'memory-only', 'source_fingerprint' => 'fixture', 'sideload_images' => false, 'mode' => 'update'],
        ['stage' => 'products'], ['max_rows' => 1]);
    $queries = array_column($db->trace, 'query');
    $accepted_ok = 1 === ($accepted['success'] ?? null) && 1 === ($db->progress['success_count'] ?? null)
        && 0 === ($db->progress['error_count'] ?? null) && [] === $db->errors
        && 1 === count($db->attributes) && 'Imported' === ($db->attributes[0]['attribute_name'] ?? null)
        && 1 === count($db->maps) && 77 === ($db->maps[0]['target_id'] ?? null)
        && 1 === count(array_filter($queries, static fn(string $sql): bool => 'COMMIT' === $sql))
        && !in_array('ROLLBACK', $queries, true);
    if ($accepted_ok) { ++$pass; } else { ++$fail; }
    echo ($accepted_ok ? 'PASS ' : 'FAIL ') . "accepted update still commits attributes/map and reports success\n";
    $cases['accepted_update'] = ['result' => $accepted, 'pass' => $accepted_ok,
        'attributes_after' => $db->attributes, 'maps_after' => $db->maps, 'progress' => $db->progress, 'trace' => $db->trace];

    foreach (['refused' => false, 'accepted' => true, 'skip' => true] as $kind => $accept) {
        $db = new WooRefusalDb();
        $GLOBALS['wpdb'] = $db;
        YSProduct::$existing = true;
        YSProduct::$update_result = $accept;
        YSProduct::$calls = [];
        \YangSheep\YsCartWooImport\Packages\PackageReader::$productRows = 2;
        $prior = ['stage' => 'products', 'offset' => 1, 'processed' => 1, 'success' => 0, 'errors' => 1];
        $result = (new ProductImporter())->importBatch(41,
            ['file_path' => 'memory-only', 'source_fingerprint' => 'fixture', 'sideload_images' => false,
                'mode' => $kind === 'skip' ? 'skip' : 'update'], $prior, ['max_rows' => 50]);
        $cursor = json_decode($db->progress['cursor_json'] ?? '{}', true);
        $checks = [
            'last product batch retains current and previous counters' =>
                2 === ($db->progress['processed_count'] ?? null)
                && (int)$accept === ($db->progress['success_count'] ?? null)
                && (2 - (int)$accept) === ($db->progress['error_count'] ?? null)
                && 2 === ($cursor['processed'] ?? null)
                && (int)$accept === ($cursor['success'] ?? null)
                && (2 - (int)$accept) === ($cursor['errors'] ?? null),
            'stage transition resets only the variants offset' =>
                'variants' === ($cursor['stage'] ?? null) && 0 === ($cursor['offset'] ?? null)
                && 1 === ($result['processed'] ?? null) && (int)$accept === ($result['success'] ?? null),
            'last-batch outcome remains a truthful success or error' =>
                ($accept ? 0 : 1) === count($db->errors) && ($accept ? 1 : 0) === count($db->maps),
        ];
        foreach ($checks as $label => $ok) {
            $ok ? ++$pass : ++$fail;
            echo ($ok ? 'PASS ' : 'FAIL ') . "EOF {$kind}: {$label}\n";
        }
        $cases['eof_' . $kind] = ['checks' => $checks, 'result' => $result, 'progress' => $db->progress];
        // Empty variants resume must preserve that stage's offset and all totals.
        $cursor['offset'] = 7;
        (new ProductImporter())->importBatch(41,
            ['file_path' => 'memory-only', 'sideload_images' => false], $cursor, ['max_rows' => 50]);
        $after = json_decode($db->progress['cursor_json'] ?? '{}', true);
        $ok = $cursor === $after;
        $ok ? ++$pass : ++$fail;
        echo ($ok ? 'PASS ' : 'FAIL ') . "EOF {$kind}: existing variants offset and counters remain intact\n";
    }

    echo json_encode(['php' => PHP_VERSION, 'source_hashes' => $source_hashes,
        'scope' => 'real importer; Core refusal and all I/O are finite doubles; no native pairing',
        'cases' => $cases, 'pass' => $pass, 'fail' => $fail], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    echo "Woo false-result contract: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
