<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rest = (string)file_get_contents($root . '/src/Rest/RestController.php');
$controllerPath = $root . '/src/Rest/BackupController.php';

if (!is_file($controllerPath)) {
    throw new RuntimeException('BackupController must exist.');
}

foreach ([
    "use YangSheep\\YsCartWooImport\\Rest\\BackupController;",
    "new BackupController()",
    "'/backups'",
    "'/backups/(?P<file>[A-Za-z0-9._-]+)/download'",
    "'/backups/(?P<file>[A-Za-z0-9._-]+)/restore'",
    "'permission_callback' => [Permission::class, 'adminDownload']",
] as $needle) {
    if (strpos($rest, $needle) === false) {
        throw new RuntimeException("Missing backup REST route marker: {$needle}");
    }
}

