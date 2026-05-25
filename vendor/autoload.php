<?php
/**
 * Vendor autoloader.
 *
 * Loads the bundled YS Plugin Hub Client.
 *
 * @package YangSheep\YsCartWooImport
 */

defined('ABSPATH') || exit;

if (file_exists(__DIR__ . '/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php')) {
    require_once __DIR__ . '/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php';
}
