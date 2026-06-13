<?php
declare(strict_types=1);

/**
 * v0.7.0 — 中斷繼續、全部重試（覆蓋/忽略）、跨站整合、訂單狀態選擇 contract。
 *
 * 驗收：
 *   R1 JS：精靈進度持久化（STATE_KEY ys_cwci_wizard_state、save/load/clear）。
 *   R2 JS：開機掃描未完成精靈工作（options.wizard 標記）+ resume/discard 動作。
 *   R3 JS：全部重試 autopilot（retry-all + retryMode + maybeAutopilot）。
 *   R4 JS：訂單狀態選擇（order-statuses 端點呼叫、預設全選＝include 空、未對應 select）
 *        — 精靈與手動模式皆有。
 *   R5 JS+模板：mode（skip/overwrite）radio — 精靈步驟 + 手動上傳表單（預設 skip）。
 *   R6 PHP：OrderImporter mode/status_include/status_map/skipped 計數
 *        （error = processed - success - skipped）+ overwriteOrder（交易、保留單號/建單時間、重建品項）。
 *   R7 PHP：OrderMapper YS_STATUSES 白名單 + statusMap() + mapStatus overrides。
 *   R8 PHP：JobController 驗證 mode/status_include/status_map（白名單）。
 *   R9 PHP：order-statuses 端點（safePackagePath + streamJsonLines 聚合）+ 路由註冊。
 *   R10 Product/Customer importer 支援 mode=skip（既有不更動）。
 *   R11 跨站說明文案（精靈 import 流程 + 手動表單 note）。
 *   R12 版本 >= 0.7.0 + CHANGELOG。
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function v070_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
}
$js   = (string) file_get_contents($root . '/assets/js/admin.js');
$tpl  = (string) file_get_contents($root . '/templates/admin/app.php');
$oi   = (string) file_get_contents($root . '/src/YsCart/OrderImporter.php');
$om   = (string) file_get_contents($root . '/src/YsCart/OrderMapper.php');
$pi   = (string) file_get_contents($root . '/src/YsCart/ProductImporter.php');
$ci   = (string) file_get_contents($root . '/src/YsCart/CustomerImporter.php');
$jc   = (string) file_get_contents($root . '/src/Rest/JobController.php');
$pc   = (string) file_get_contents($root . '/src/Rest/PackageController.php');
$rc   = (string) file_get_contents($root . '/src/Rest/RestController.php');
$main = (string) file_get_contents($root . '/ys-cart-woocommerce-import.php');
$log  = (string) file_get_contents($root . '/CHANGELOG.md');

v070_check('R1 wizard state persistence (save/load/clear + key)',
    str_contains($js, "'ys_cwci_wizard_state'")
    && str_contains($js, 'saveState()')
    && str_contains($js, 'loadState()')
    && str_contains($js, 'clearState()'));

v070_check('R2 resume scan + resume/discard actions (wizard-flagged jobs)',
    str_contains($js, 'bootWithResume')
    && str_contains($js, 'opts.wizard')
    && str_contains($js, "data-wz=\"resume\"")
    && str_contains($js, "'discard'")
    && str_contains($js, '/cancel'));

v070_check('R3 retry-all autopilot with mode',
    str_contains($js, "'retry-all'")
    && str_contains($js, 'retryAll()')
    && str_contains($js, 'maybeAutopilot')
    && str_contains($js, 'retryMode'));

v070_check('R4 order status selection in wizard AND manual (all-checked => no filter)',
    str_contains($js, 'packages/order-statuses')
    && str_contains($js, 'ordersStatusPrompt')
    && str_contains($js, 'manualRenderStatusPicker')
    && substr_count($js, 'checked.length === boxes.length') >= 2
    && str_contains($js, 'data-wz-map')
    && str_contains($js, 'data-manual-map'));

v070_check('R5 mode radios: wizard step + manual form (default skip)',
    str_contains($js, 'modeRadioHtml')
    && str_contains($js, 'panelMode()')
    && str_contains($tpl, 'name="mode" value="skip" checked')
    && str_contains($tpl, 'name="mode" value="overwrite"'));

v070_check('R6 OrderImporter mode/status filter/skipped + overwriteOrder',
    str_contains($oi, "\$options['mode']")
    && str_contains($oi, "status_include")
    && str_contains($oi, "status_map")
    && str_contains($oi, '$processed - $success - $skipped')
    && str_contains($oi, 'private function overwriteOrder(')
    && (bool) preg_match('/overwriteOrder\([\s\S]{0,900}Transaction::run/s', $oi)
    && str_contains($oi, "unset(\$data['order_number'], \$data['created_at'])")
    // 跨站整合：不同來源站同單號 → 短指紋後綴（uk_order_number 唯一鍵，跨站往返 T4 實測抓出）
    && str_contains($oi, 'private function uniqueOrderNumber('));

v070_check('R7 OrderMapper YS_STATUSES + statusMap() + overrides',
    str_contains($om, 'const YS_STATUSES')
    && str_contains($om, 'public static function statusMap()')
    && str_contains($om, 'array $overrides = []'));

v070_check('R8 JobController validates mode/status_include/status_map',
    (bool) preg_match("/createImportJob[\s\S]{0,3000}\['skip', 'overwrite', 'update'\]/s", $jc)
    && str_contains($jc, "OrderMapper::YS_STATUSES"));

v070_check('R9 order-statuses endpoint + route',
    str_contains($pc, 'public function orderStatuses(')
    && str_contains($pc, 'Permission::safePackagePath')
    && str_contains($pc, "streamJsonLines(\$filePath, 'orders.jsonl')")
    && str_contains($rc, "'/packages/order-statuses'"));

v070_check('R10 Product/Customer importers honor mode=skip',
    (bool) preg_match("/'skip' === \\\$mode[\s\S]{0,260}return \(int\)\\\$existing->id;/s", $pi)
    && str_contains($ci, '$existedBefore'));

v070_check('R11 cross-site guidance copy (wizard + manual)',
    str_contains($js, '來源站＋來源單號')
    && str_contains($tpl, '來源站＋來源單號'));

preg_match('/Version:\s*([0-9.]+)/', $main, $vh);
preg_match("/YS_CWCI_VERSION', '([0-9.]+)'/", $main, $vc);
v070_check('R12 version header/constant match >= 0.7.0 + CHANGELOG',
    '' !== ($vh[1] ?? '') && ($vh[1] ?? '') === ($vc[1] ?? '')
    && version_compare($vh[1] ?? '0', '0.7.0', '>=')
    && str_contains($log, '## [0.7.0]'));

echo "\nv0.7.0 resume/retry/status: PASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    throw new RuntimeException("v0.7.0 contract FAILED ({$fail})");
}
