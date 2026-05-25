<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$managerPath = $root . '/src/Backups/SqlBackupManager.php';

if (!is_file($managerPath)) {
    throw new RuntimeException('SqlBackupManager must exist.');
}

$manager = (string)file_get_contents($managerPath);

foreach ([
    'wp_upload_dir()',
    'ys-cart-wc-import/backups',
    'ys-cwci-backup-',
    "preg_match('/^ys-cwci-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\\.sql$/",
    '.htaccess',
    'Deny from all',
    'web.config',
    "confirm !== 'RESTORE'",
] as $needle) {
    if (strpos($manager, $needle) === false) {
        throw new RuntimeException("Missing backup safety marker: {$needle}");
    }
}
