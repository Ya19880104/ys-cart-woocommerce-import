<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$repo = $root . '/src/YsCart/OrderSourceRepository.php';

if (!is_file($repo)) {
    throw new RuntimeException('Order source repository is missing.');
}

require_once $repo;

$class = 'YangSheep\\YsCartWooImport\\YsCart\\OrderSourceRepository';
if (!class_exists($class)) {
    throw new RuntimeException('OrderSourceRepository class is missing.');
}

$hash = hash('sha256', 'https://example.test');
$binary = $class::fingerprintForStorage($hash);
if (!is_string($binary) || strlen($binary) !== 32) {
    throw new RuntimeException('Hex sha256 fingerprint must be converted to 32 raw bytes.');
}

if (bin2hex($binary) !== $hash) {
    throw new RuntimeException('Fingerprint conversion must preserve the sha256 value.');
}

$fallback = $class::fingerprintForStorage('not-a-hex-fingerprint');
if (strlen($fallback) !== 32 || bin2hex($fallback) !== hash('sha256', 'not-a-hex-fingerprint')) {
    throw new RuntimeException('Non-hex fingerprints must be hashed into 32 raw bytes.');
}

$normalized = $class::normalizeOrderNumber("  WC #1001 \t\n");
if ($normalized !== 'wc#1001') {
    throw new RuntimeException("Order number normalized to {$normalized}; expected wc#1001.");
}
