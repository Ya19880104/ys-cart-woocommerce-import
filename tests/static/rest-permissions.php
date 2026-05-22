<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = $root . '/src/Rest/RestController.php';

if (!is_file($controller)) {
    throw new RuntimeException('REST controller is missing.');
}

$contents = file_get_contents($controller);

if (strpos($contents, "register_rest_route(") === false) {
    throw new RuntimeException('REST controller must register routes.');
}

$routeCalls = substr_count($contents, 'register_rest_route(');
$permissionCallbacks = substr_count($contents, 'permission_callback');

if ($permissionCallbacks < $routeCalls) {
    throw new RuntimeException("Every REST route must include permission_callback. routes={$routeCalls}, permissions={$permissionCallbacks}");
}

if (strpos($contents, "ys-cart-wc-import/v1") === false) {
    throw new RuntimeException('REST namespace ys-cart-wc-import/v1 is missing.');
}

