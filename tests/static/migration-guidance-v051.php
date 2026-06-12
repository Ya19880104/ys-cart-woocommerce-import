<?php
declare(strict_types=1);

/**
 * v0.5.1 — URL & slug migration guidance contract.
 *
 * 驗收：
 *   G1 指引卡 markup 存在（ys-cwci-guidance + 標題）。
 *   G2 WooCommerce 偵測 + ?product= 劫持警告 + base 衝突強警告。
 *   G3 YS CART 偵測 + route_base()（method_exists 防舊版核心）+ fallback shop。
 *   G4 操作捷徑：商店設定（商品網址前綴）+ plugins.php。
 *   G5 指引卡樣式存在（admin.css）。
 *   G6 版本 >= 0.5.1 且 CHANGELOG 記錄。
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function v051_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
}
$tpl  = (string) file_get_contents($root . '/templates/admin/app.php');
$css  = (string) file_get_contents($root . '/assets/css/admin.css');
$main = (string) file_get_contents($root . '/ys-cart-woocommerce-import.php');
$log  = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';

v051_check('G1 guidance card markup present',
    str_contains($tpl, 'ys-cwci-guidance')
    && str_contains($tpl, '網址與商品代稱'));

v051_check('G2 Woo detection + ?product= hijack + base-collision warnings',
    str_contains($tpl, "class_exists('WooCommerce')")
    && str_contains($tpl, '?product=')
    && str_contains($tpl, 'ys_cwci_ys_base === $ys_cwci_woo_base'));

v051_check('G3 YS CART detection + route_base with method_exists guard + shop fallback',
    str_contains($tpl, 'YSShopRoutingBootstrap')
    && str_contains($tpl, "method_exists('\\YangSheep\\Ecommerce\\Bootstrap\\YSShopRoutingBootstrap', 'route_base')")
    && str_contains($tpl, "\$ys_cwci_ys_base = 'shop';"));

v051_check('G4 action shortcuts: shop settings + plugins page',
    str_contains($tpl, 'page=ys-ec-settings&tab=shop')
    && str_contains($tpl, "admin_url('plugins.php')"));

v051_check('G5 guidance styles present',
    str_contains($css, '.ys-cwci-guidance')
    && str_contains($css, '.ys-cwci-guidance__warn'));

preg_match('/Version:\s*([0-9.]+)/', $main, $vh);
preg_match("/YS_CWCI_VERSION', '([0-9.]+)'/", $main, $vc);
v051_check('G6 version header/constant match >= 0.5.1 + CHANGELOG',
    '' !== ($vh[1] ?? '') && ($vh[1] ?? '') === ($vc[1] ?? '')
    && version_compare($vh[1] ?? '0', '0.5.1', '>=')
    && str_contains($log, '## [0.5.1]'));

echo "\nv0.5.1 guidance: PASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    throw new RuntimeException("v0.5.1 migration guidance contract FAILED ({$fail})");
}
