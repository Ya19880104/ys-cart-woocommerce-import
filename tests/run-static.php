<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
$tests = [
    'static/no-admin-ajax.php',
    'static/rest-permissions.php',
    'static/woo-order-query.php',
    'static/plugin-boot-guards.php',
    'static/admin-slug-independence.php',
    'static/admin-auto-run-flow.php',
    'static/job-runner-lock.php',
    'static/hub-client-integration.php',
    'static/admin-user-role-guard.php',
    'static/order-source-integration.php',
    'static/customer-export-excludes-imported-users.php',
    'static/order-existing-shipping-backfill.php',
    'static/backup-routes.php',
    'static/backup-manager-safety.php',
    'static/package-directory-protection.php',
    'unit/status-mapper.php',
    'unit/package-validator.php',
    'unit/order-source-repository.php',
    'unit/customer-importer-user-retry.php',
    'unit/package-writer-reset.php',
    'unit/order-mapper-shipping.php',
    'unit/sql-backup-parser.php',
    // NOTE: security-hardening-v024.php ends with exit() — it MUST stay last,
    // anything listed after it would be skipped. New harness-friendly tests
    // (which throw on failure instead of exit) go ABOVE this line.
    'static/stability-hardening-v050.php',
    'static/migration-guidance-v051.php',
    'static/wizard-mode-v060.php',
    'static/resume-retry-status-v070.php',
    'static/security-hardening-v024.php',
];

$failures = 0;

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    echo "Running {$test}... ";
    try {
        require $path;
        echo "OK\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL\n";
        echo $e->getMessage() . "\n";
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed.\n");
    exit(1);
}

echo "All tests passed.\n";
