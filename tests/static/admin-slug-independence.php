<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminPage.php';

$slug = \YangSheep\YsCartWooImport\Admin\AdminPage::SLUG;
$adminPage = (string)file_get_contents(__DIR__ . '/../../src/Admin/AdminPage.php');
$template = (string)file_get_contents(__DIR__ . '/../../templates/admin/app.php');

if (!str_starts_with($slug, 'ys-ec-')) {
    throw new RuntimeException('Admin page slug must use a YS CART admin prefix so YS CART admin assets are applied.');
}

if ($slug === 'ys-cart') {
    throw new RuntimeException('Admin page slug must not collide with the YS CART main admin page.');
}

if (strpos($adminPage, 'add_submenu_page') === false
    || (strpos($adminPage, "'ys-cart'") === false && strpos($adminPage, '"ys-cart"') === false)) {
    throw new RuntimeException('Admin page must register under the YS CART ecommerce menu when YS CART is available.');
}

if (strpos($adminPage, 'add_management_page') === false) {
    throw new RuntimeException('Admin page must keep a Tools fallback for WooCommerce export-only sites.');
}

if (strpos($template, '\YangSheep\Ecommerce\Admin\YSAdminApp') === false
    || strpos($template, '::open') === false
    || strpos($template, '::close') === false) {
    throw new RuntimeException('Admin template must use the YS CART admin shell when available.');
}
