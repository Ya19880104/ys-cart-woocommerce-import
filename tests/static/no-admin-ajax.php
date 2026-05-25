<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$violations = [];

foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));

    if (str_starts_with($relative, '.git/')) {
        continue;
    }

    if (!preg_match('/\.(php|js)$/', $relative)) {
        continue;
    }

    if (str_starts_with($relative, 'tests/')) {
        continue;
    }

    if (str_starts_with($relative, 'vendor/yangsheep/ys-plugin-hub-client/')) {
        continue;
    }

    $contents = file_get_contents($path);
    foreach (['wp_ajax_', 'wp_ajax_nopriv_', 'admin-ajax.php', 'ajaxurl'] as $needle) {
        if (strpos($contents, $needle) !== false) {
            $violations[] = "{$relative} contains {$needle}";
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("admin-ajax usage is forbidden:\n" . implode("\n", $violations));
}
