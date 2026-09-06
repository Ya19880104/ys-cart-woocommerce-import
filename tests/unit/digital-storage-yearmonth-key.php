<?php
/**
 * ProductImporter::copyToDigitalStorage() 必須把匯入的數位檔交給 YS CART Core 的受保護儲存區，
 * 並在 Core 具備年月 API 時寫進 YYYY/MM，回傳含年月的相對 key（既有的 DB 寫入原樣搬用該 key）。
 *
 * 本檔用**真的 Core `YSProtectedStorage`**（2.59.9 / 97d3fbf 的 API）與真的檔案系統，只把 WordPress
 * helpers（`wp_upload_dir` / option / `wp_mkdir_p` / `wp_remote_*` / `wp_unique_filename`）換成替身。
 * 沒有真實 HTTP 往返：隔離判定由替身的「伺服器模式」決定，因此本檔不代表任何站台的實際隔離狀態。
 *
 * 舊 Core（沒有 `new_key()` / `ensure_for_key()`）的相容行為在**另一個行程**裡驗證：同一個類別名稱
 * 不能在一個行程內載入兩種版本，所以預設執行會在跑完新 API 的案例後，以 `old` 參數重新叫用自己。
 *
 * Run: php tests/unit/digital-storage-yearmonth-key.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'new';
if (!in_array($mode, ['new', 'old'], true)) {
    throw new RuntimeException('Unknown mode: ' . $mode);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

// ── 暫存的真檔案系統 ────────────────────────────────────────────────────────
$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/ys-wci-ym-' . bin2hex(random_bytes(6));
if (!mkdir($tmp . '/uploads', 0777, true) && !is_dir($tmp . '/uploads')) {
    throw new RuntimeException('Cannot create temp uploads dir.');
}

$GLOBALS['ys_wci_ym'] = [
    'uploads' => $tmp . '/uploads',
    'serve' => 'denied',   // denied | open
    'options' => [],
];

// ── WordPress 替身 ─────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        return [
            'basedir' => $GLOBALS['ys_wci_ym']['uploads'],
            'baseurl' => 'https://shop.example.test/wp-content/uploads',
        ];
    }
}
if (!function_exists('site_url')) {
    function site_url(): string
    {
        return 'https://shop.example.test';
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value)
    {
        return $value;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value): bool
    {
        $GLOBALS['ys_wci_ym']['options'][$key] = $value;
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['ys_wci_ym']['options'])
            ? $GLOBALS['ys_wci_ym']['options'][$key]
            : $default;
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
/** Core 的 canary：`denied` 模式回明確拒絕，`open` 模式把 canary 內容原樣送回。 */
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = [])
    {
        if ($GLOBALS['ys_wci_ym']['serve'] === 'open') {
            $base = 'https://shop.example.test/wp-content/uploads';
            if (str_starts_with($url, $base . '/')) {
                $path = $GLOBALS['ys_wci_ym']['uploads'] . substr($url, strlen($base));
                if (is_file($path)) {
                    return ['response' => ['code' => 200], 'body' => (string)file_get_contents($path)];
                }
            }
            return ['response' => ['code' => 404], 'body' => ''];
        }
        return ['response' => ['code' => 403], 'body' => 'Forbidden'];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int
    {
        return is_array($response) ? (int)($response['response']['code'] ?? 0) : 0;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return is_array($response) ? (string)($response['body'] ?? '') : '';
    }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $name): string
    {
        return (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    }
}
/** WordPress 的行為：檔名在**該目錄**已存在就補 -1 / -2 …。 */
if (!function_exists('wp_unique_filename')) {
    function wp_unique_filename(string $dir, string $filename, $callback = null): string
    {
        $dir = rtrim($dir, '/\\');
        if (!is_file($dir . '/' . $filename)) {
            return $filename;
        }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext === '' ? $filename : substr($filename, 0, -(strlen($ext) + 1));
        for ($i = 1; $i < 500; $i++) {
            $candidate = $ext === '' ? $base . '-' . $i : $base . '-' . $i . '.' . $ext;
            if (!is_file($dir . '/' . $candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('wp_unique_filename double exhausted.');
    }
}

// ── Core 儲存區：new 模式載入真 97d3 類別；old 模式先放一個只有舊 API 的替身 ──
$coreClass = '\\YangSheep\\Ecommerce\\Storage\\YSProtectedStorage';

if ($mode === 'new') {
    $candidates = array_filter([
        getenv('YS_CART_CORE_STORAGE') ?: null,
        dirname($root, 2) . '/worktrees/ys-cart-v2595-successor-20260905/src/Storage/YSProtectedStorage.php',
        dirname($root) . '/ys-cart/src/Storage/YSProtectedStorage.php',
    ]);
    $corePath = '';
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $corePath = $candidate;
            break;
        }
    }
    if ($corePath === '') {
        throw new RuntimeException('Real Core YSProtectedStorage not found; set YS_CART_CORE_STORAGE.');
    }
    require_once $corePath;
    if (!method_exists($coreClass, 'new_key') || !method_exists($coreClass, 'ensure_for_key')) {
        throw new RuntimeException('Core at ' . $corePath . ' lacks new_key()/ensure_for_key(); this test requires the 2.59.9 API.');
    }
} else {
    // 只有舊 API 的 Core：digital() / ensure() / resolve()，沒有 new_key()/ensure_for_key()。
    eval('
    namespace YangSheep\\Ecommerce\\Storage;
    final class YSProtectedStorage {
        public static function digital(): self { return new self(); }
        public function ensure(): string {
            $dir = $GLOBALS["ys_wci_ym"]["uploads"] . "/legacy-private/digital";
            if ($GLOBALS["ys_wci_ym"]["serve"] === "open") { return ""; }
            if (!is_dir($dir)) { mkdir($dir, 0777, true); }
            return $dir;
        }
        public function resolve(string $rel): string {
            return $GLOBALS["ys_wci_ym"]["uploads"] . "/legacy-private/digital/" . ltrim($rel, "/");
        }
    }
    ');
}

require_once $root . '/src/YsCart/ProductImporter.php';

$importerClass = 'YangSheep\\YsCartWooImport\\YsCart\\ProductImporter';
if (!class_exists($importerClass)) {
    throw new RuntimeException('ProductImporter class is missing.');
}

$importer = new $importerClass();
$method = new ReflectionMethod($importerClass, 'copyToDigitalStorage');

$copy = static function (string $src, string $fileName) use ($method, $importer) {
    return $method->invoke($importer, $src, 'p1', 'd1', $fileName);
};

$makeSource = static function (string $name, string $body) use ($tmp): string {
    $path = $tmp . '/src-' . $name;
    file_put_contents($path, $body);
    return $path;
};

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};

// ══════════════════════════════════════════════════════════════════════════
if ($mode === 'new') {
    // N1 — 隔離已證明時，新檔寫進 YYYY/MM，回傳含年月的相對 key，內容一致
    $GLOBALS['ys_wci_ym']['serve'] = 'denied';
    $src = $makeSource('a.txt', 'payload-A');
    $stored = $copy($src, 'a.txt');

    if (!is_array($stored)) {
        $fail('N1: copyToDigitalStorage must return an array when the storage area is usable.');
    }
    if (preg_match('#^[0-9]{4}/(0[1-9]|1[0-2])/[^/]+$#D', (string)$stored['key']) !== 1) {
        $fail('N1: returned key must be a YYYY/MM relative key, got: ' . var_export($stored['key'], true));
    }
    if (!is_file((string)$stored['path'])) {
        $fail('N1: returned path must exist on disk.');
    }
    $expectedDir = $GLOBALS['ys_wci_ym']['uploads'] . '/ys-ec-private/digital/' . dirname((string)$stored['key']);
    if (rtrim(str_replace('\\', '/', dirname((string)$stored['path'])), '/') !== rtrim($expectedDir, '/')) {
        $fail('N1: file must land in the current uploads root month directory, got: ' . dirname((string)$stored['path']));
    }
    if (hash_file('sha256', (string)$stored['path']) !== hash('sha256', 'payload-A')) {
        $fail('N1: stored file content must match the source byte for byte.');
    }

    // N1b — 回傳的 key 必須能被 Core resolve 回同一個實體檔（DB 只搬用這個 key）
    $resolved = $coreClass::digital()->resolve((string)$stored['key']);
    if ($resolved !== (string)$stored['path'] || !is_file($resolved)) {
        $fail('N1b: Core resolve() of the returned key must find the same file.');
    }

    // N2 — 同名兩檔：碰撞必須對「月份目錄」檢查，兩檔並存且都在同一個月份目錄
    $src2 = $makeSource('a2.txt', 'payload-B');
    $stored2 = $copy($src2, 'a.txt');
    if (!is_array($stored2)) {
        $fail('N2: second copy with the same file name must still succeed.');
    }
    if ($stored2['key'] === $stored['key']) {
        $fail('N2: second file must get a distinct key, not overwrite the first.');
    }
    if (dirname((string)$stored2['key']) !== dirname((string)$stored['key'])) {
        $fail('N2: both files must live under the same YYYY/MM directory.');
    }
    if (!is_file((string)$stored['path']) || hash_file('sha256', (string)$stored['path']) !== hash('sha256', 'payload-A')) {
        $fail('N2: the first file must survive untouched.');
    }
    if (hash_file('sha256', (string)$stored2['path']) !== hash('sha256', 'payload-B')) {
        $fail('N2: the second file must hold its own content.');
    }

    // N3 — 隔離未被證明時 fail-closed：回 null，且不留下任何新檔
    $GLOBALS['ys_wci_ym']['serve'] = 'open';
    $GLOBALS['ys_wci_ym']['options'] = [];   // 清掉已記錄的隔離狀態，強迫重新判定
    $before = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($GLOBALS['ys_wci_ym']['uploads'], FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $before[] = $f->getPathname();
    }
    $src3 = $makeSource('c.txt', 'payload-C');
    $blocked = $copy($src3, 'c.txt');
    if ($blocked !== null) {
        $fail('N3: copyToDigitalStorage must return null when the storage area refuses to be used.');
    }
    $after = [];
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($GLOBALS['ys_wci_ym']['uploads'], FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $f) {
        $after[] = $f->getPathname();
    }
    $added = array_values(array_diff($after, $before));
    $addedPayload = array_values(array_filter($added, static fn(string $p): bool => is_file($p) && file_get_contents($p) === 'payload-C'));
    if ($addedPayload !== []) {
        $fail('N3: no imported payload may be written while the area is refused: ' . implode(', ', $addedPayload));
    }

    // N4 — 舊的平面 key 仍讀得到（Core 三 root 相容；本修正不搬舊檔）
    $GLOBALS['ys_wci_ym']['serve'] = 'denied';
    $GLOBALS['ys_wci_ym']['options'] = [];
    $flatDir = $GLOBALS['ys_wci_ym']['uploads'] . '/ys-ec-private/digital';
    if (!is_dir($flatDir)) {
        mkdir($flatDir, 0777, true);
    }
    file_put_contents($flatDir . '/legacy-flat.txt', 'payload-legacy');
    $legacyResolved = $coreClass::digital()->resolve('legacy-flat.txt');
    if ($legacyResolved !== $flatDir . '/legacy-flat.txt' || !is_file($legacyResolved)) {
        $fail('N4: a pre-existing flat key must still resolve to its original location.');
    }

    // N5 — 不重複呼叫 ensure()：ensure_for_key() 內部已經呼叫過
    $source = (string)file_get_contents($root . '/src/YsCart/ProductImporter.php');
    $start = strpos($source, 'private function copyToDigitalStorage');
    // 切到方法真正的結尾（下一個方法宣告），不要用固定長度：方法變長時固定視窗會截斷，
    // 讓後面的斷言在看不到的程式碼上得到假結果。
    $end = $start === false ? false : preg_match('/\n    (?:private|public|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10);
    $bodyEnd = ($end === 1) ? $m[0][1] : strlen($source);
    $body = $start === false ? '' : substr($source, $start, $bodyEnd - $start);
    if ($body === '') {
        $fail('N5: cannot locate copyToDigitalStorage in the source.');
    }
    if (!str_contains($body, 'ensure_for_key(')) {
        $fail('N5: copyToDigitalStorage must use ensure_for_key() for partitioned writes.');
    }
    // ensure_for_key() 內部已經呼叫 ensure()，新路徑不得再叫一次；舊 Core 的相容分支仍然需要它，
    // 所以整個方法裡剛好只能有一個 ->ensure()。
    if (preg_match_all('/->ensure\(\s*\)/', $body) !== 1) {
        $fail('N5: exactly one ->ensure() is allowed (the legacy fallback); ensure_for_key() already calls it on the new path.');
    }

    // ── 舊 Core 相容案例在另一個行程執行 ──────────────────────────────────
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' old';
    $childOutput = [];
    $childRc = 1;
    exec($cmd . ' 2>&1', $childOutput, $childRc);
    if ($childRc !== 0) {
        $fail("old-Core compatibility child failed (rc={$childRc}):\n" . implode("\n", $childOutput));
    }
    echo "digital-storage-yearmonth-key: new-Core cases OK; old-Core child OK\n";
} else {
    // O1 — 舊 Core（沒有新 API）時保留既有行為：平放寫入、回平面 key
    $GLOBALS['ys_wci_ym']['serve'] = 'denied';
    $src = $makeSource('o.txt', 'payload-O');
    $stored = $copy($src, 'o.txt');
    if (!is_array($stored)) {
        $fail('O1: with an old Core the helper must still store the file.');
    }
    if (str_contains((string)$stored['key'], '/')) {
        $fail('O1: with an old Core the key must stay flat, got: ' . var_export($stored['key'], true));
    }
    if (!is_file((string)$stored['path']) || hash_file('sha256', (string)$stored['path']) !== hash('sha256', 'payload-O')) {
        $fail('O1: stored file must exist with the source content.');
    }

    // O2 — 舊 Core 的 ensure() 回空時仍 fail-closed
    $GLOBALS['ys_wci_ym']['serve'] = 'open';
    $src2 = $makeSource('o2.txt', 'payload-O2');
    if ($copy($src2, 'o2.txt') !== null) {
        $fail('O2: with an old Core returning an empty area the helper must return null.');
    }
    echo "digital-storage-yearmonth-key(old): OK\n";
}

// ── 清理暫存樹 ─────────────────────────────────────────────────────────────
$rm = static function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/*') ?: [] as $entry) {
        is_dir($entry) ? $rm($entry) : @unlink($entry);
    }
    @rmdir($dir);
};
$rm($tmp);
