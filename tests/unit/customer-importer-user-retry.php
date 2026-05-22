<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$importer = $root . '/src/YsCart/CustomerImporter.php';

if (!is_file($importer)) {
    throw new RuntimeException('Customer importer is missing.');
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code, private string $message)
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

$GLOBALS['ys_cwci_user_retry_state'] = [
    'email_exists_calls' => 0,
    'username_exists_calls' => [],
    'insert_attempts' => [],
    'next_user_id' => 9876,
];

if (!function_exists('email_exists')) {
    function email_exists($email)
    {
        $GLOBALS['ys_cwci_user_retry_state']['email_exists_calls']++;
        return 0;
    }
}

if (!function_exists('sanitize_user')) {
    function sanitize_user($username)
    {
        return preg_replace('/[^a-zA-Z0-9_.@-]/', '', (string)$username);
    }
}

if (!function_exists('username_exists')) {
    function username_exists($username)
    {
        $GLOBALS['ys_cwci_user_retry_state']['username_exists_calls'][] = (string)$username;
        return false;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password()
    {
        return 'generated-password';
    }
}

if (!function_exists('get_role')) {
    function get_role($role)
    {
        return $role === 'customer' ? (object)['name' => 'customer'] : null;
    }
}

if (!function_exists('wp_insert_user')) {
    function wp_insert_user($data)
    {
        $GLOBALS['ys_cwci_user_retry_state']['insert_attempts'][] = $data;
        if (count($GLOBALS['ys_cwci_user_retry_state']['insert_attempts']) === 1) {
            return new WP_Error('existing_user_login', '很抱歉，這個使用者名稱已有其他使用者使用！');
        }

        return $GLOBALS['ys_cwci_user_retry_state']['next_user_id'];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($userId, $key, $value): bool
    {
        return true;
    }
}

require_once $importer;

$class = 'YangSheep\\YsCartWooImport\\YsCart\\CustomerImporter';
if (!class_exists($class)) {
    throw new RuntimeException('CustomerImporter class is missing.');
}

$instance = new $class();
$method = new ReflectionMethod($class, 'ensureUser');

$userId = $method->invoke($instance, 'codex-load-checkout-1778473497507-74@example.test', 'Codex Load74', '');

if ($userId !== 9876) {
    throw new RuntimeException('ensureUser must retry when wp_insert_user reports a username collision.');
}

$attempts = $GLOBALS['ys_cwci_user_retry_state']['insert_attempts'];
if (count($attempts) !== 2) {
    throw new RuntimeException('ensureUser must perform exactly one retry for the simulated username collision.');
}

if (($attempts[0]['user_login'] ?? '') === ($attempts[1]['user_login'] ?? '')) {
    throw new RuntimeException('ensureUser retry must use a different user_login candidate.');
}
