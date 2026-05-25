<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminPage.php';

$slug = \YangSheep\YsCartWooImport\Admin\AdminPage::SLUG;
$adminPage = (string)file_get_contents(__DIR__ . '/../../src/Admin/AdminPage.php');
$template = (string)file_get_contents(__DIR__ . '/../../templates/admin/app.php');

if (!str_starts_with($slug, 'ys-')) {
    throw new RuntimeException('Admin page slug must use a YS-prefixed namespace.');
}

if (in_array($slug, ['ys-cart', 'ys-toolbox'], true)) {
    throw new RuntimeException('Admin page slug must not collide with parent admin pages.');
}

if (strpos($adminPage, 'add_submenu_page') === false
    || (strpos($adminPage, "'ys-toolbox'") === false && strpos($adminPage, '"ys-toolbox"') === false)) {
    throw new RuntimeException('Admin page must register under the YS Plugin menu.');
}

if (strpos($adminPage, 'add_management_page') === false) {
    throw new RuntimeException('Admin page must keep a Tools fallback for WooCommerce export-only sites.');
}

if (strpos($template, '\YangSheep\Ecommerce\Admin\YSAdminApp') !== false
    || strpos($template, '::open') !== false
    || strpos($template, '::close') !== false) {
    throw new RuntimeException('Admin template must not depend on the YS CART admin shell.');
}
