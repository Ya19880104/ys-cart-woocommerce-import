<?php
declare(strict_types=1);

$script = (string)file_get_contents(__DIR__ . '/../../assets/js/admin.js');

if (strpos($script, "api('/ys-cart-wc-import/v1/export-jobs'") === false
    || strpos($script, 'autoRun(jobId, exportButton)') === false) {
    throw new RuntimeException('Admin export flow must auto-run the created export job.');
}

if (strpos($script, "api('/ys-cart-wc-import/v1/import-jobs'") === false
    || strpos($script, 'autoRun(jobId, submitButton)') === false) {
    throw new RuntimeException('Admin import flow must auto-run the created import job.');
}
