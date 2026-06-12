<?php
declare(strict_types=1);

/**
 * v0.5.0 — pre-sale stability & UI hardening contract.
 *
 * 驗收：
 *   B1  C1: Transaction helper 存在且含 START/COMMIT/ROLLBACK。
 *   B2  C1: OrderImporter 在交易內建立訂單 + 品項 + map + source（用 Transaction::run）。
 *   B3  C1/H3: ProductImporter 的 importProduct 與 importVariant 都用 Transaction::run。
 *   B4  H2: JobController 有 SUPPORTED_ENTITIES allowlist，export/import 皆驗證並回 400。
 *   B5  H1: MediaSideloader 存在、用 media_handle_sideload、以 sha1 URL 在 maps dedup。
 *   B6  H1: ProductImporter 在交易「前」呼叫 sideloader resolveUrl/resolveUrls（可被 sideload_images 關閉）。
 *   B7  UI: AdminPage 偵測 YS CART → 條件式包 YSAdminApp::open/close；template 依 $ys_cwci_chrome 切換 .wrap。
 *   B8  M1: 單階段匯入（orders/customers）由 manifest 計數設定 total_count。
 *   B9  版本：header 與 YS_CWCI_VERSION 一致且 >= 0.5.0；CHANGELOG 記錄 0.5.0。
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function v050_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
}
$read = static function (string $rel) use ($root): string {
    $full = $root . '/' . $rel;
    return is_file($full) ? (string) file_get_contents($full) : '';
};

$transaction = $read('src/Database/Transaction.php');
$orderImp    = $read('src/YsCart/OrderImporter.php');
$productImp  = $read('src/YsCart/ProductImporter.php');
$customerImp = $read('src/YsCart/CustomerImporter.php');
$jobCtrl     = $read('src/Rest/JobController.php');
$sideloader  = $read('src/YsCart/MediaSideloader.php');
$adminPage   = $read('src/Admin/AdminPage.php');
$template    = $read('templates/admin/app.php');
$main        = $read('ys-cart-woocommerce-import.php');
$changelog   = $read('CHANGELOG.md');

// B1
v050_check('B1 Transaction helper exists with START/COMMIT/ROLLBACK',
    str_contains($transaction, "START TRANSACTION")
    && str_contains($transaction, "COMMIT")
    && str_contains($transaction, "ROLLBACK")
    && str_contains($transaction, 'public static function run'));

// B2
v050_check('B2 OrderImporter wraps create-path in Transaction::run',
    str_contains($orderImp, 'use YangSheep\\YsCartWooImport\\Database\\Transaction;')
    && str_contains($orderImp, 'Transaction::run('));

// B3
v050_check('B3 ProductImporter wraps importProduct + importVariant in Transaction::run',
    str_contains($productImp, 'use YangSheep\\YsCartWooImport\\Database\\Transaction;')
    && substr_count($productImp, 'Transaction::run(') >= 2);

// B4
v050_check('B4 JobController has entity allowlist + 400 on export & import',
    str_contains($jobCtrl, 'SUPPORTED_ENTITIES')
    && (bool) preg_match("/createExportJob[\\s\\S]{0,200}in_array\\(\\\$entity, self::SUPPORTED_ENTITIES/s", $jobCtrl)
    && (bool) preg_match("/createImportJob[\\s\\S]{0,200}in_array\\(\\\$entity, self::SUPPORTED_ENTITIES/s", $jobCtrl)
    && str_contains($jobCtrl, "'status' => 400"));

// B5
v050_check('B5 MediaSideloader sideloads + dedups by sha1 URL in maps',
    str_contains($sideloader, 'media_handle_sideload')
    && str_contains($sideloader, 'sha1(')
    && str_contains($sideloader, "self::MEDIA_ENTITY")
    && str_contains($sideloader, 'MapRepository'));

// B6 — sideloader called before the transaction, gated by sideload_images.
// 用「位置序」而非距離 regex（多位元組註解會撐爆字元上限，見 v25227 教訓）：
// resolveUrls( 必須出現在第一個 Transaction::run( 之前 = 圖片在交易外先落地。
$posSideload = strpos($productImp, 'resolveUrls(');
$posTxn      = strpos($productImp, 'Transaction::run(');
v050_check('B6 ProductImporter sideloads images (gated) before transaction',
    str_contains($productImp, 'MediaSideloader')
    && str_contains($productImp, 'resolveUrl')
    && str_contains($productImp, 'sideload_images')
    && str_contains($productImp, 'if ($sideloadImages)')
    && $posSideload !== false && $posTxn !== false && $posSideload < $posTxn);

// B7 — conditional chrome
v050_check('B7 AdminPage conditional YSAdminApp chrome + template gates .wrap',
    str_contains($adminPage, 'YS_ADMIN_APP')
    && str_contains($adminPage, 'hasYsCart()')
    && str_contains($adminPage, '::open(')
    && str_contains($adminPage, '::close()')
    && str_contains($template, '$ys_cwci_chrome')
    && (bool) preg_match("/if \\(!\\\$ys_cwci_chrome\\)[\\s\\S]{0,40}wrap ys-cwci-wrap/s", $template));

// B8 — M1
v050_check('B8 orders + customers seed total_count from manifest',
    str_contains($orderImp, "total_count")
    && str_contains($orderImp, 'readManifest')
    && str_contains($customerImp, "total_count")
    && str_contains($customerImp, 'readManifest'));

// B9 — version + changelog
preg_match('/Version:\s*([0-9.]+)/', $main, $vh);
preg_match("/YS_CWCI_VERSION', '([0-9.]+)'/", $main, $vc);
v050_check('B9 version header/constant match and >= 0.5.0 + CHANGELOG',
    '' !== ($vh[1] ?? '') && ($vh[1] ?? '') === ($vc[1] ?? '')
    && version_compare($vh[1] ?? '0', '0.5.0', '>=')
    && str_contains($changelog, '## [0.5.0]'));

echo "\nv0.5.0 stability: PASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    throw new RuntimeException("v0.5.0 stability hardening contract FAILED ({$fail})");
}
