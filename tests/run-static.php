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
    'static/admin-user-role-guard.php',
    'unit/status-mapper.php',
    'unit/package-validator.php',
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
