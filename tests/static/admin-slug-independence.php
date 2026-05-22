<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminPage.php';

$slug = \YangSheep\YsCartWooImport\Admin\AdminPage::SLUG;

if (str_starts_with($slug, 'ys-cart-') || str_starts_with($slug, 'ys-ec-')) {
    throw new RuntimeException('Admin page slug must not match YS CART admin shell prefixes.');
}

if ($slug === 'ys-cart') {
    throw new RuntimeException('Admin page slug must not collide with the YS CART main admin page.');
}
