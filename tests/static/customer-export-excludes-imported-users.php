<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$exporter = $root . '/src/Woo/CustomerExporter.php';

if (!is_file($exporter)) {
    throw new RuntimeException('Woo customer exporter is missing.');
}

$contents = (string)file_get_contents($exporter);

foreach (['meta_query', '_ys_wc_imported_user', 'NOT EXISTS'] as $needle) {
    if (strpos($contents, $needle) === false) {
        throw new RuntimeException("Woo customer exporter must exclude addon-created imported users via {$needle}.");
    }
}
