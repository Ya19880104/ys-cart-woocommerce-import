<?php
declare(strict_types=1);

/**
 * v0.2.4 — 5 critical security/correctness fixes from Reviewer audit
 *
 * 驗收 (fail-closed + strip comments):
 *   A1: Scheduler.php #4 — as_enqueue_async_action 用 positional [$jobId]
 *   A2: PackageWriter.php #5 — zip() 內 packageId 走 safePackageId()
 *   A3: JobRepository.php #10 — markRunning 含 WHERE status='pending' atomic
 *   A4: markRunning return bool (BC: void → bool)
 *   A5: Permission.php #1 — safePackagePath() helper
 *   A6: Permission.php #2 — nonce check 移到 delegation 之上
 *   A7: Permission.php #3 — adminDownload() variant
 *   A8: RestController.php — /jobs/{id}/download 用 adminDownload
 *   A9: JobController createImportJob safePackagePath
 *   A10: JobController createDirectJob safePackagePath
 *   A11: JobController download defense-in-depth
 */

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function v024_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { ++$pass; echo "[PASS] {$label}\n"; return; }
    ++$fail; echo "[FAIL] {$label}\n";
}

$scheduler_raw = (string) file_get_contents($root . '/src/Jobs/Scheduler.php');
$writer_raw    = (string) file_get_contents($root . '/src/Packages/PackageWriter.php');
$jobRepo_raw   = (string) file_get_contents($root . '/src/Database/JobRepository.php');
$perm_raw      = (string) file_get_contents($root . '/src/Rest/Permission.php');
$rest_raw      = (string) file_get_contents($root . '/src/Rest/RestController.php');
$jobCtrl_raw   = (string) file_get_contents($root . '/src/Rest/JobController.php');

$strip = static function (string $s): string {
    return (string) preg_replace('~//[^\n]*~', '', (string) preg_replace('~/\*[\s\S]*?\*/~', '', $s));
};
$scheduler = $strip($scheduler_raw);
$writer    = $strip($writer_raw);
$jobRepo   = $strip($jobRepo_raw);
$perm      = $strip($perm_raw);
$rest      = $strip($rest_raw);
$jobCtrl   = $strip($jobCtrl_raw);

v024_check(
    'A1 #4 [CRITICAL]: as_enqueue_async_action 用 positional [$jobId]',
    (bool) preg_match('/as_enqueue_async_action\(\s*self::HOOK_RUN_JOB\s*,\s*\[\s*\$jobId\s*\]/s', $scheduler)
    && !preg_match("/as_enqueue_async_action\\(\\s*self::HOOK_RUN_JOB\\s*,\\s*\\[\\s*'job_id'/s", $scheduler)
);

v024_check(
    'A2 #5: PackageWriter::zip() 用 safePackageId',
    (bool) preg_match('/function\s+zip[\s\S]*?\$this->safePackageId\(\s*\$packageId\s*\)/s', $writer)
);

v024_check(
    "A3 #10: markRunning() 用 UPDATE ... WHERE status='pending' (atomic)",
    (bool) preg_match("/markRunning[\\s\\S]*?UPDATE\\s+\\{\\\$table\\}[\\s\\S]*?WHERE\\s+id\\s*=\\s*%d\\s+AND\\s+status\\s*=\\s*'pending'/s", $jobRepo)
);

v024_check(
    'A4 #10: markRunning return type bool',
    (bool) preg_match('/function\s+markRunning\(int\s+\$id\)\s*:\s*bool/', $jobRepo)
);

v024_check(
    'A5 #1: Permission::safePackagePath() helper 存在',
    (bool) preg_match('/public\s+static\s+function\s+safePackagePath\(\s*string\s+\$rawPath\s*\)\s*:\s*\?string/', $perm)
);

v024_check(
    'A6 #2: Permission nonce verify 在 YSAdminRestAuth class_exists 之上',
    (bool) preg_match('/wp_verify_nonce[\s\S]*?class_exists\(\s*\$ysAuth\s*\)/s', $perm)
);

v024_check(
    'A7 #3: Permission::adminDownload() variant 存在',
    (bool) preg_match('/public\s+static\s+function\s+adminDownload[\s\S]*?return\s+self::check\(\s*\$request\s*,\s*true\s*\)/s', $perm)
);

v024_check(
    'A8 #3: RestController /jobs/{id}/download 用 adminDownload',
    (bool) preg_match("/\\/jobs\\/\\(\\?P<id>\\\\d\\+\\)\\/download[\\s\\S]*?Permission::class\\s*,\\s*'adminDownload'/s", $rest)
);

v024_check(
    'A9 #1: createImportJob 套 Permission::safePackagePath',
    (bool) preg_match('/createImportJob[\s\S]*?Permission::safePackagePath/s', $jobCtrl)
);

v024_check(
    'A10 #1: createDirectJob 套 Permission::safePackagePath',
    (bool) preg_match('/createDirectJob[\s\S]*?Permission::safePackagePath/s', $jobCtrl)
);

v024_check(
    'A11 #1: download() defense-in-depth 套 Permission::safePackagePath',
    (bool) preg_match('/function\s+download[\s\S]*?Permission::safePackagePath\(\s*\(string\)\s*\$job->file_path\s*\)/s', $jobCtrl)
);

echo "\nPASS={$pass} FAIL={$fail}\n";
if ($fail > 0) {
    // v0.7.1: throw（勿 exit）— exit(0) 會吞掉 harness 先前累計的失敗
    throw new RuntimeException("v0.2.4 security hardening contract FAILED ({$fail})");
}
