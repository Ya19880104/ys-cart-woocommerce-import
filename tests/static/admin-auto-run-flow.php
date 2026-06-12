<?php
declare(strict_types=1);

$script = (string)file_get_contents(__DIR__ . '/../../assets/js/admin.js');

if (strpos($script, "api('/ys-cart-wc-import/v1/export-jobs'") === false
    || strpos($script, 'autoRun(jobId, exportButton)') === false) {
    throw new RuntimeException('Admin export flow must auto-run the created export job.');
}

// v0.7.0：手動匯入統一走 manualCreateImport()（訂單先經狀態選擇再匯入），
// auto-run 行為不變 — 建立 import job 後立即自動執行。
if (strpos($script, "api('/ys-cart-wc-import/v1/import-jobs'") === false
    || strpos($script, 'manualCreateImport') === false
    || strpos($script, 'autoRun(jobId, button)') === false) {
    throw new RuntimeException('Admin import flow must auto-run the created import job.');
}
