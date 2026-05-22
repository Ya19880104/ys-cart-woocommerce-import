<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$writerFile = $root . '/src/Packages/PackageWriter.php';
$readerFile = $root . '/src/Packages/PackageReader.php';

if (!is_file($writerFile) || !is_file($readerFile)) {
    throw new RuntimeException('Package writer or reader is missing.');
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path): bool
    {
        return is_dir($path) || mkdir((string)$path, 0777, true);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0): string
    {
        return (string)json_encode($data, (int)$flags);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

require_once $readerFile;
require_once $writerFile;

$writerClass = 'YangSheep\\YsCartWooImport\\Packages\\PackageWriter';
if (!class_exists($writerClass)) {
    throw new RuntimeException('PackageWriter class is missing.');
}

$dir = sys_get_temp_dir() . '/ys-cwci-package-writer-reset-' . bin2hex(random_bytes(4));
$writer = new $writerClass($dir);
$packageId = 'same-second-package';

$writer->appendJsonLine($packageId, 'orders.jsonl', ['source_id' => 'stale']);
file_put_contents($dir . '/' . $packageId . '.zip', 'stale zip');

if (!method_exists($writer, 'resetPackage')) {
    throw new RuntimeException('PackageWriter must expose resetPackage().');
}

$writer->resetPackage($packageId);
$writer->appendJsonLine($packageId, 'orders.jsonl', ['source_id' => 'fresh']);

$lines = file($dir . '/' . $packageId . '/orders.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false || count($lines) !== 1 || strpos($lines[0], 'fresh') === false) {
    throw new RuntimeException('resetPackage must remove stale package JSONL content before a new export starts.');
}

if (is_file($dir . '/' . $packageId . '.zip')) {
    throw new RuntimeException('resetPackage must remove stale package zip files.');
}
