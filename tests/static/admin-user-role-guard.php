<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$importer = $root . '/src/YsCart/CustomerImporter.php';

if (!is_file($importer)) {
    throw new RuntimeException('Customer importer is missing.');
}

$contents = file_get_contents($importer);

if (strpos($contents, "user_can(\$userId, 'manage_options')") === false) {
    throw new RuntimeException('Customer importer must detect privileged users before converting them.');
}

if (strpos($contents, 'generate_member_no') === false) {
    throw new RuntimeException('Privileged user customer creation must create a YS member number without calling convert_wp_user().');
}

if (strpos($contents, "remove_role('ys_ec_customer')") === false) {
    throw new RuntimeException('Customer importer must remove ys_ec_customer from privileged users if a previous import added it.');
}
