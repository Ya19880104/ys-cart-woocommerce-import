<?php
/** Real Woo importer/maps/runner, finite Core receipts and WP I/O. No native SQL acceptance claim. */
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
    function current_time(string $kind): string { return '2031-01-01 00:00:00'; }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function add_option(string $key, mixed $value, string $unused = '', string $autoload = ''): bool {
        if (isset($GLOBALS['locks'][$key])) { return false; }
        $GLOBALS['locks'][$key] = $value; return true;
    }
    function get_option(string $key): mixed { return $GLOBALS['locks'][$key] ?? false; }
    function delete_option(string $key): bool { $GLOBALS['deleted_locks'][] = $key; unset($GLOBALS['locks'][$key]); return true; }
    final class SourceCommandDb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 1;
        public array $trace = [];
        public ?object $legacy = null;
        public object $job;
        public function __construct() {
            $this->job = (object)['id'=>41, 'status'=>'running', 'type'=>'import', 'entity'=>'products',
                'options_json'=>json_encode(['file_path'=>'finite', 'source_fingerprint'=>'opaque historical source', 'sideload_images'=>false]),
                'cursor_json'=>'{}'];
        }
        public function prepare(string $sql, mixed ...$args): string {
            foreach ($args as $arg) { $sql = preg_replace_callback('/%[sd]/', static fn(): string => is_int($arg) ? (string)$arg : "'".str_replace("'", "''", (string)$arg)."'", $sql, 1); }
            return $sql;
        }
        public function get_row(string $sql): ?object {
            $this->trace[] = ['read'=>$sql];
            if (str_contains($sql, 'ys_wc_migration_jobs')) { return $this->job; }
            if (str_contains($sql, 'ys_wc_migration_maps')) { return $this->legacy; }
            throw new \RuntimeException('unexpected finite read');
        }
        public function update(string $table, array $data, array $where): int {
            $this->trace[] = ['update'=>$table, 'data'=>$data, 'where'=>$where]; return 1;
        }
        public function insert(string $table, array $data): int {
            $this->trace[] = ['insert'=>$table, 'data'=>$data]; ++$this->insert_id; return 1;
        }
        public function delete(string $table, array $where): int { $this->trace[] = ['delete'=>$table]; return 1; }
        public function query(string $sql): int { $this->trace[] = ['query'=>$sql]; return 0; }
    }
}
namespace YangSheep\Ecommerce\Models {
    final class YSProduct {
        public static ?object $skuMatch = null;
        public static function find_by_sku(string $sku): ?object { return self::$skuMatch; }
        public static function find_by_slug(string $slug): ?object { return null; }
        public static function create(array $data): int { return 77; }
        public static function update(int $id, array $data): bool { return true; }
    }
}
namespace YangSheep\Ecommerce\Services\Projection {
    final class YSCatalogMutationUncertain extends \RuntimeException {}
    final class YSProductImportPayload {
        public function __construct(public array $input) {}
        public static function from_array(array $input): ?self { return new self($input); }
    }
    final class YSProductImportSource {
        public static array $receipt = ['status'=>'missing'];
        public static array $calls = [];
        public static function find(object $db, string $namespace, string $fingerprint, string $kind, string $owner, string $item = ''): array {
            self::$calls[] = compact('namespace', 'fingerprint', 'kind', 'owner', 'item'); return self::$receipt;
        }
    }
    final class YSCatalogMutationCoordinator {
        public static bool $poisoned = false;
        public static array $receipt = ['status'=>'unchanged', 'committed'=>true, 'product_id'=>77, 'skipped'=>false];
        public static array $calls = [];
        public function __construct(private object $db) {}
        public static function unavailable(object $db): bool { return self::$poisoned; }
        public function import_source_product(YSProductImportPayload $payload): array { self::$calls[] = $payload->input; return self::$receipt; }
    }
}
namespace YangSheep\YsCartWooImport\Packages {
    final class PackageReader {
        public static int $rows = 1;
        public static int $yielded = 0;
        public function streamJsonLines(string $path, string $entry): \Generator {
            for ($i = 0; $i < self::$rows; ++$i) {
                ++self::$yielded;
                yield ['source_id'=>'401', 'source_parent_id'=>'400', 'name'=>'Finite Product', 'sku'=>'finite',
                    'slug'=>'finite', 'attributes'=>[['name'=>'Color', 'options'=>['Blue', 'Green']]],
                    'downloads'=>[['source_download_id'=>'book:chapter-1', 'file'=>'https://example.invalid/book.pdf', 'name'=>'Book.pdf']]];
            }
        }
    }
}
namespace YangSheep\YsCartWooImport\Jobs {
    final class Scheduler {
        public static array $enqueued = [];
        public function enqueue(int $jobId): void { self::$enqueued[] = $jobId; }
    }
}
namespace YangSheep\YsCartWooImport\YsCart {
    final class MediaSideloader {
        public static int $calls = 0;
        public function resolveUrl(int $job, string $source, string $url): string { ++self::$calls; return $url; }
        public function resolveUrls(int $job, string $source, array $urls): array { ++self::$calls; return $urls; }
    }
}
namespace {
    use YangSheep\Ecommerce\Services\Projection\YSCatalogMutationCoordinator as Coordinator;
    use YangSheep\Ecommerce\Services\Projection\YSProductImportSource as Source;
    use YangSheep\Ecommerce\Services\Projection\YSCatalogMutationUncertain as Uncertain;
    use YangSheep\YsCartWooImport\Database\MapRepository;
    use YangSheep\YsCartWooImport\YsCart\ProductImporter;
    use YangSheep\YsCartWooImport\Packages\PackageReader;
    use YangSheep\YsCartWooImport\Jobs\JobRunner;
    use YangSheep\YsCartWooImport\Jobs\Scheduler;

    $root = dirname(__DIR__, 2);
    foreach (['Database/TableMaker.php','Database/Transaction.php','Database/MapRepository.php','Database/ErrorRepository.php',
        'Database/JobRepository.php','YsCart/ProductMapper.php','YsCart/ProductImporter.php','Jobs/JobRunner.php'] as $file) { require $root.'/src/'.$file; }
    $pass = 0; $fail = 0;
    function check(bool $ok, string $label): void {
        $ok ? ++$GLOBALS['pass'] : ++$GLOBALS['fail']; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
    }
    function fresh(): SourceCommandDb {
        $db = $GLOBALS['wpdb'] = new SourceCommandDb();
        Coordinator::$poisoned = false; Coordinator::$calls = [];
        Coordinator::$receipt = ['status'=>'unchanged', 'committed'=>true, 'product_id'=>77, 'skipped'=>false];
        Source::$calls = []; Source::$receipt = ['status'=>'missing'];
        PackageReader::$rows = 1; PackageReader::$yielded = 0;
        Scheduler::$enqueued = []; $GLOBALS['locks'] = []; $GLOBALS['deleted_locks'] = [];
        \YangSheep\YsCartWooImport\YsCart\MediaSideloader::$calls = 0;
        \YangSheep\Ecommerce\Models\YSProduct::$skuMatch = null;
        return $db;
    }
    function imports(array $cursor = []): array {
        return (new ProductImporter())->importBatch(41, ['file_path'=>'finite', 'source_fingerprint'=>'opaque historical source', 'sideload_images'=>false], $cursor, ['max_rows'=>50]);
    }
    $db = fresh(); $result = imports(); $payload = Coordinator::$calls[0] ?? [];
    check(count(Coordinator::$calls) === 1 && array_column($db->trace, 'query') === [], 'one typed Core command without Woo transaction');
    check(($payload['namespace'] ?? null) === 'woocommerce' && ($payload['fingerprint'] ?? null) === 'opaque historical source', 'opaque source identity reaches Core without hash conversion');
    check(($payload['attributes'][0]['attribute_values'] ?? null) === ['Blue','Green'] && ($payload['downloads'][0]['source_download_id'] ?? null) === 'book:chapter-1', 'prepared attributes and complete download ID reach Core together');
    check(($payload['downloads'][0]['metadata']['file_type'] ?? null) === 'url' && !isset($payload['downloads'][0]['metadata']['variant_id']), 'Core owns digital product relation');
    check(($result['success'] ?? null) === 1, 'committed unchanged still counts as success');
    $writes = array_values(array_filter($db->trace, static fn(array $r): bool => isset($r['insert']) || isset($r['update']) || isset($r['delete'])));
    check(count($writes) === 1 && ($writes[0]['update'] ?? '') === 'wp_ys_wc_migration_jobs', 'Core success needs no trailing Woo map or child write');

    fresh();
    (new ProductImporter())->importBatch(41, ['file_path'=>'finite','source_fingerprint'=>'source','sideload_images'=>false,'mode'=>'overwrite'], [], ['max_rows'=>1]);
    check((Coordinator::$calls[0]['mode'] ?? null) === 'update', 'retained overwrite product option maps to typed update semantics');

    fresh(); \YangSheep\Ecommerce\Models\YSProduct::$skuMatch = (object)['id'=>88];
    (new ProductImporter())->importBatch(41, ['file_path'=>'finite','source_fingerprint'=>'source','sideload_images'=>false,'mode'=>'skip'], [], ['max_rows'=>1]);
    $prepared = Coordinator::$calls[0] ?? [];
    check(($prepared['legacy_product_id'] ?? null) === null && count($prepared['attributes'] ?? []) === 1 && count($prepared['downloads'] ?? []) === 1,
        'SKU-only skip precheck cannot omit children before Core reselects the target');
    $db = fresh(); $db->legacy = (object)['id'=>9,'target_id'=>88,'target_type'=>'ys_product'];
    (new ProductImporter())->importBatch(41, ['file_path'=>'finite','source_fingerprint'=>'source','sideload_images'=>false,'mode'=>'skip'], [], ['max_rows'=>1]);
    $prepared = Coordinator::$calls[0] ?? [];
    check(($prepared['legacy_product_id'] ?? null) === 88 && ($prepared['attributes'] ?? null) === [] && ($prepared['downloads'] ?? null) === [],
        'skip may omit children only with a source target hint that Core revalidates');

    $db = fresh(); Coordinator::$receipt = ['status'=>'rejected','committed'=>false,'reason'=>'finite_refusal'];
    $result = imports();
    check(($result['processed'] ?? null) === 1 && ($result['success'] ?? null) === 0, 'definite refusal retains processed-error semantics');
    check(count(array_filter($db->trace, static fn(array $r): bool => ($r['insert'] ?? '') === 'wp_ys_wc_migration_errors')) === 1, 'definite refusal records exactly one product error');

    $db = fresh(); Coordinator::$receipt = ['status'=>'unknown','committed'=>null,'reason'=>'finite_unknown']; PackageReader::$rows = 2;
    $stopped = false; try { imports(); } catch (Uncertain $e) { $stopped = true; }
    check($stopped && count(Coordinator::$calls) === 1 && PackageReader::$yielded === 1, 'unknown bypasses both importer catches before next row');
    check(array_filter($db->trace, static fn(array $r): bool => isset($r['update']) || isset($r['insert']) || isset($r['query'])) === [], 'unknown writes no error, counter, cursor or transaction');

    $db = fresh(); Coordinator::$poisoned = true;
    $stopped = false; try { imports(['stage'=>'variants']); } catch (Uncertain $e) { $stopped = true; }
    check($stopped && array_filter($db->trace, static fn(array $r): bool => isset($r['update']) || isset($r['insert'])) === [], 'poisoned variant parent read is not reported as no variants file');

    $db = fresh(); Coordinator::$poisoned = true; $stopped = false;
    try { (new ProductImporter())->importBatch(41, ['file_path'=>'finite','source_fingerprint'=>'source','sideload_images'=>true], [], ['max_rows'=>1]); }
    catch (Uncertain $e) { $stopped = true; }
    check($stopped && \YangSheep\YsCartWooImport\YsCart\MediaSideloader::$calls === 0, 'known poisoned product stops before media preparation');

    $db = fresh(); Coordinator::$receipt = ['status'=>'unknown','committed'=>null,'reason'=>'finite_unknown']; PackageReader::$rows = 2;
    $result = (new JobRunner())->runNext(41);
    check(($result['status'] ?? null) === 'reconciliation_required' && ($result['done'] ?? null) === false, 'runner returns stopped reconciliation result for unknown');
    check(isset($GLOBALS['locks']['ys_cwci_job_lock_41']) && $GLOBALS['deleted_locks'] === [] && Scheduler::$enqueued === [], 'runner retains existing lock without scheduling on unknown');
    check(array_filter($db->trace, static fn(array $r): bool => isset($r['update']) || isset($r['insert'])) === [], 'runner avoids automatic failed/error/cursor writes on uncertain connection');

    $db = fresh(); $db->legacy = (object)['id'=>9,'target_id'=>33,'target_type'=>'ys_product'];
    Source::$receipt = ['status'=>'found','product_id'=>77,'target_id'=>77,'target_type'=>'ys_product'];
    $map = (new MapRepository())->find('opaque historical source','product','401');
    check(($map->target_id ?? null) === 77 && !isset($map->id) && count($db->trace) === 0, 'Core identity wins and exposes no Woo map row id');
    (new MapRepository())->upsert(41,'opaque historical source','product','401',88,'ys_product');
    $updates = array_values(array_filter($db->trace, static fn(array $r): bool => isset($r['update'])));
    check(($updates[0]['where']['id'] ?? null) === 9, 'legacy upsert updates actual Woo row instead of Core identity id');
    Source::$receipt = ['status'=>'missing']; $map = (new MapRepository())->find('opaque historical source','product','401');
    check(($map->target_id ?? null) === 33, 'proven Core identity missing may use retained legacy map');
    Source::$receipt = ['status'=>'unavailable']; $before = count($db->trace); $refused = false;
    try { (new MapRepository())->find('opaque historical source','product','401'); } catch (\RuntimeException $e) { $refused = true; }
    check($refused && count($db->trace) === $before, 'Core read unavailable never falls back to legacy');

    $db = fresh(); Source::$receipt = ['status'=>'found','product_id'=>77,'target_id'=>555,'target_type'=>'ys_digital_file'];
    (new MapRepository())->find('source','digital_file','400:book:chapter-1');
    (new MapRepository())->find('source','digital_file','401:book:chapter-1');
    check((Source::$calls[0]['owner'] ?? null) === '400' && (Source::$calls[1]['owner'] ?? null) === '401'
        && (Source::$calls[1]['item'] ?? null) === 'book:chapter-1', 'download bridge separates current source owner and retains all remaining item bytes');
    $db->legacy = (object)['id'=>7,'target_id'=>444,'target_type'=>'ys_digital_file']; $before = count(Source::$calls);
    $method = new \ReflectionMethod(ProductImporter::class, 'syncDigitalFiles');
    $method->invoke(new ProductImporter(),41,'source',77,22,['source_id'=>'401','downloads'=>[['source_download_id'=>'book:chapter-1','file'=>'https://example.invalid/book.pdf']]]);
    $fileWrites = array_values(array_filter($db->trace, static fn(array $r): bool => ($r['update'] ?? '') === 'wp_ys_ec_digital_files'));
    check(count(Source::$calls) === $before && ($fileWrites[0]['where']['id'] ?? null) === 444
        && ($fileWrites[0]['data']['variant_id'] ?? null) === 22, 'legacy variant digital writer cannot mutate Core authoritative product download');

    echo "Woo typed source command: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
