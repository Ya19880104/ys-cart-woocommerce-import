<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    '/src/Admin/LicenseNag.php',
    '/src/Licensing/LicenseState.php',
    '/assets/js/license-nag.js',
    '/assets/css/license-nag.css',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . $file)) {
        throw new RuntimeException("Missing license nag file: {$file}");
    }
}

$plugin = file_get_contents($root . '/src/Plugin.php') ?: '';
if (strpos($plugin, 'LicenseNag') === false || strpos($plugin, "add_action('admin_footer'") === false) {
    throw new RuntimeException('Plugin must register the independent license nag hooks.');
}

$nag = file_get_contents($root . '/src/Admin/LicenseNag.php') ?: '';
foreach (['wp_doing_ajax()', 'wp_doing_cron()', 'WP_CLI', "current_user_can('manage_options')", 'LicenseState::isAllowed()'] as $needle) {
    if (strpos($nag, $needle) === false) {
        throw new RuntimeException("License nag guard missing: {$needle}");
    }
}

$js = file_get_contents($root . '/assets/js/license-nag.js') ?: '';
if (strpos($js, 'ys-cwci-license-nag') === false || strpos($js, 'data-ys-cwci-license-modal') === false) {
    throw new RuntimeException('License nag JavaScript must render the expected modal selectors.');
}

foreach (['localStorage', 'sessionStorage', 'document.cookie'] as $storage) {
    if (strpos($js, $storage) !== false) {
        throw new RuntimeException("License nag must not persist dismissal with {$storage}.");
    }
}

$css = file_get_contents($root . '/assets/css/license-nag.css') ?: '';
if (strpos($css, '.ys-cwci-license-nag') === false || strpos($css, 'position: fixed') === false) {
    throw new RuntimeException('License nag CSS must style the body-mounted fixed modal.');
}

$template = file_get_contents($root . '/templates/admin/app.php') ?: '';
if (strpos($template, 'ys-cwci-license') === false || strpos($template, 'LicenseState::publicState()') === false) {
    throw new RuntimeException('Admin page must expose a license target section for the nag CTA.');
}
