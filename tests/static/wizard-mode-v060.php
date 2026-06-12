<?php
declare(strict_types=1);

/**
 * v0.6.0 — 精靈模式 & 手動模式 contract。
 *
 * 驗收：
 *   W1 模式切換器 markup（兩個 tab + 兩個 mode panel；manual 預設可見）。
 *   W2 精靈容器（rail/panel/actions/title hooks）。
 *   W3 JS：mode 記憶（localStorage ys_cwci_mode）+ applyMode。
 *   W4 JS：三流程（direct/export/import）步驟建構 + 順序 customers→products→orders。
 *   W5 JS：同站直轉用 PHP 下發的 siteFingerprint（不在 JS 算 hash）。
 *   W6 PHP：AdminPage localize 傳 homeUrl + siteFingerprint。
 *   W7 手動模式工程功能完整保留（jobs/LOG、備份建立、還原 RESTORE 確認、刪除）。
 *   W8 精靈樣式存在。
 *   W9 版本 >= 0.6.0 + CHANGELOG。
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function v060_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
}
$tpl  = (string) file_get_contents($root . '/templates/admin/app.php');
$js   = (string) file_get_contents($root . '/assets/js/admin.js');
$css  = (string) file_get_contents($root . '/assets/css/admin.css');
$php  = (string) file_get_contents($root . '/src/Admin/AdminPage.php');
$main = (string) file_get_contents($root . '/ys-cart-woocommerce-import.php');
$log  = (string) file_get_contents($root . '/CHANGELOG.md');

v060_check('W1 mode switcher + two mode panels (manual visible by default)',
    substr_count($tpl, 'data-ys-cwci-mode=') >= 2
    && str_contains($tpl, 'data-ys-cwci-mode-panel="wizard"')
    && str_contains($tpl, 'data-ys-cwci-mode-panel="manual"')
    && !preg_match('/data-ys-cwci-mode-panel="manual"[^>]*hidden/', $tpl));

v060_check('W2 wizard container hooks',
    str_contains($tpl, 'data-ys-cwci-wizard-rail')
    && str_contains($tpl, 'data-ys-cwci-wizard-panel')
    && str_contains($tpl, 'data-ys-cwci-wizard-actions')
    && str_contains($tpl, 'data-ys-cwci-wizard-title'));

v060_check('W3 JS mode persistence + applyMode',
    str_contains($js, "'ys_cwci_mode'")
    && str_contains($js, 'applyMode')
    && str_contains($js, 'localStorage'));

v060_check('W4 JS three flows + entity order customers->products->orders',
    str_contains($js, "can_direct_transfer ? 'direct'")
    && str_contains($js, "['customers', 'products', 'orders']")
    && str_contains($js, "buildSteps")
    && str_contains($js, "'backup'"));

v060_check('W5 direct flow uses PHP-provided siteFingerprint',
    str_contains($js, 'window.ysCwciAdmin.siteFingerprint'));

v060_check('W6 AdminPage localizes homeUrl + siteFingerprint',
    str_contains($php, "'homeUrl'")
    && str_contains($php, "'siteFingerprint' => hash('sha256', home_url())"));

v060_check('W7 manual engineering features intact (jobs LOG / backup / RESTORE / delete)',
    str_contains($tpl, 'data-ys-cwci-jobs')
    && str_contains($tpl, 'data-ys-cwci-backup-create')
    && str_contains($js, "data-ys-cwci-errors")
    && str_contains($js, "'RESTORE'")
    && str_contains($js, 'backupDeleteButton'));

v060_check('W8 wizard styles present',
    str_contains($css, '.ys-cwci-mode__tab')
    && str_contains($css, '.ys-cwci-wizard__rail')
    && str_contains($css, '.ys-cwci-wizard__log'));

preg_match('/Version:\s*([0-9.]+)/', $main, $vh);
preg_match("/YS_CWCI_VERSION', '([0-9.]+)'/", $main, $vc);
v060_check('W9 version header/constant match >= 0.6.0 + CHANGELOG',
    '' !== ($vh[1] ?? '') && ($vh[1] ?? '') === ($vc[1] ?? '')
    && version_compare($vh[1] ?? '0', '0.6.0', '>=')
    && str_contains($log, '## [0.6.0]'));

echo "\nv0.6.0 wizard-mode: PASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    throw new RuntimeException("v0.6.0 wizard mode contract FAILED ({$fail})");
}
