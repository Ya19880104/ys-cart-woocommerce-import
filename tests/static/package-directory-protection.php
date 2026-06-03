<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$protectedPath = $root . '/src/Security/ProtectedDirectory.php';
$writerPath = $root . '/src/Packages/PackageWriter.php';
$controllerPath = $root . '/src/Rest/PackageController.php';

foreach ([$protectedPath, $writerPath, $controllerPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Missing package protection file: ' . basename($path));
    }
}

$protected = (string)file_get_contents($protectedPath);
$writer = (string)file_get_contents($writerPath);
$controller = (string)file_get_contents($controllerPath);

foreach ([
    'Options -Indexes',
    'Require all denied',
    'Deny from all',
    'web.config',
    'writeGuardFileIfNeeded',
    "'.zip'",
    "'.json'",
    "'.jsonl'",
] as $needle) {
    if (strpos($protected, $needle) === false) {
        throw new RuntimeException("ProtectedDirectory missing marker: {$needle}");
    }
}

if (substr_count($writer, 'ProtectedDirectory::ensure') < 2) {
    throw new RuntimeException('PackageWriter must protect both base and per-package working directories.');
}

if (strpos($controller, 'ProtectedDirectory::ensure($dir') === false) {
    throw new RuntimeException('Package upload directory must be protected before moving uploaded zip.');
}
