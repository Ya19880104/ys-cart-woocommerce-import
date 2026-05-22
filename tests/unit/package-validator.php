<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$validator = $root . '/src/Packages/ManifestValidator.php';
$reader = $root . '/src/Packages/PackageReader.php';

if (!is_file($validator)) {
    throw new RuntimeException('Manifest validator is missing.');
}

if (!is_file($reader)) {
    throw new RuntimeException('Package reader is missing.');
}

require_once $validator;
require_once $reader;

$validatorClass = 'YangSheep\\YsCartWooImport\\Packages\\ManifestValidator';
$readerClass = 'YangSheep\\YsCartWooImport\\Packages\\PackageReader';

if (!class_exists($validatorClass) || !class_exists($readerClass)) {
    throw new RuntimeException('Package classes are missing.');
}

$valid = [
    'schema_version' => '1.0',
    'source' => [
        'site_url_hash' => hash('sha256', 'https://example.test'),
    ],
    'created_at' => '2026-05-22T00:00:00+00:00',
    'entities' => [
        'customers' => 1,
        'products' => 1,
        'orders' => 1,
    ],
    'files' => [
        'customers' => 'customers.jsonl',
        'products' => 'products.jsonl',
        'orders' => 'orders.jsonl',
    ],
];

$errors = $validatorClass::validate($valid);
if ($errors !== []) {
    throw new RuntimeException('Valid manifest returned errors: ' . implode(', ', $errors));
}

$invalid = $valid;
unset($invalid['schema_version']);
$errors = $validatorClass::validate($invalid);
if ($errors === []) {
    throw new RuntimeException('Invalid manifest should return errors.');
}

foreach (['../evil.jsonl', '/tmp/evil.jsonl', 'nested/../../evil.jsonl'] as $entry) {
    if ($readerClass::isSafeEntryName($entry)) {
        throw new RuntimeException("Unsafe zip entry was accepted: {$entry}");
    }
}

foreach (['manifest.json', 'orders.jsonl', 'nested/file.jsonl'] as $entry) {
    if (!$readerClass::isSafeEntryName($entry)) {
        throw new RuntimeException("Safe zip entry was rejected: {$entry}");
    }
}

