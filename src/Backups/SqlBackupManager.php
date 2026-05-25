<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Backups;

defined('ABSPATH') || exit;

final class SqlBackupManager
{
    private const FILE_PREFIX = 'ys-cwci-backup-';
    private const BATCH_SIZE = 300;

    public function create(): array
    {
        global $wpdb;

        $dir = $this->ensureBackupDir();
        $filename = self::FILE_PREFIX . gmdate('Ymd-His') . '-' . substr(wp_hash((string)microtime(true)), 0, 16) . '.sql';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        $tmpPath = $path . '.tmp';
        $handle = fopen($tmpPath, 'wb');

        if (!is_resource($handle)) {
            throw new \RuntimeException(__('無法建立備份檔案。', 'ys-cart-woocommerce-import'));
        }

        try {
            $this->writeHeader($handle);
            foreach ($this->getTables() as $table) {
                $this->writeTable($handle, $table);
            }
            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(__('無法儲存備份檔案。', 'ys-cart-woocommerce-import'));
        }

        clearstatcache(true, $path);

        return $this->formatBackup($path);
    }

    public function list(): array
    {
        $dir = $this->ensureBackupDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*.sql') ?: [];
        $backups = [];

        foreach ($files as $path) {
            $name = basename($path);
            if (!$this->isValidBackupName($name)) {
                continue;
            }
            $backups[] = $this->formatBackup($path);
        }

        usort($backups, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));

        return $backups;
    }

    public function delete(string $file): bool
    {
        $path = $this->safeBackupPath($file);
        if ($path === null) {
            return false;
        }

        return @unlink($path);
    }

    public function restore(string $file, string $confirm): array
    {
        global $wpdb;

        if ($confirm !== 'RESTORE') {
            throw new \RuntimeException(__('請輸入 RESTORE 才能執行還原。', 'ys-cart-woocommerce-import'));
        }

        $path = $this->safeBackupPath($file);
        if ($path === null) {
            throw new \RuntimeException(__('找不到可還原的備份檔案。', 'ys-cart-woocommerce-import'));
        }

        $sql = file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new \RuntimeException(__('備份檔案是空的。', 'ys-cart-woocommerce-import'));
        }

        $statements = self::splitSqlStatements($sql);
        $executed = 0;

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $result = $wpdb->query($statement);
            if ($result === false) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: database error */
                    __('資料庫還原失敗：%s', 'ys-cart-woocommerce-import'),
                    (string)$wpdb->last_error
                ));
            }
            $executed++;
        }

        return [
            'file' => basename($path),
            'statements' => $executed,
        ];
    }

    public function safeBackupPath(string $file): ?string
    {
        $file = basename(trim($file));
        if (!$this->isValidBackupName($file)) {
            return null;
        }

        $dir = $this->ensureBackupDir();
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        $real = realpath($path);
        $realDir = realpath($dir);

        if ($real === false || $realDir === false) {
            return null;
        }

        if (!str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $escaped = false;
        $lineComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($quote === null && $char === '-' && $next === '-') {
                $lineComment = true;
                $i++;
                continue;
            }

            $buffer .= $char;

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim(substr($buffer, 0, -1));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private function ensureBackupDir(): string
    {
        $upload = wp_upload_dir();
        $base = isset($upload['basedir']) ? rtrim((string)$upload['basedir'], DIRECTORY_SEPARATOR . '/') : '';
        if ($base === '') {
            throw new \RuntimeException(__('無法取得 WordPress uploads 目錄。', 'ys-cart-woocommerce-import'));
        }

        $dir = $base . DIRECTORY_SEPARATOR . 'ys-cart-wc-import/backups';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException(__('無法建立備份目錄。', 'ys-cart-woocommerce-import'));
        }

        $index = $dir . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents(
                $htaccess,
                "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }

        $webConfig = $dir . DIRECTORY_SEPARATOR . 'web.config';
        if (!is_file($webConfig)) {
            file_put_contents(
                $webConfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions><add fileExtension=\".sql\" allowed=\"false\" /></fileExtensions></requestFiltering></security></system.webServer></configuration>\n"
            );
        }

        return $dir;
    }

    private function getTables(): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $tables = array_values(array_filter(array_map('strval', (array)$tables), [$this, 'isSafeIdentifier']));
        sort($tables);

        return $tables;
    }

    private function writeHeader($handle): void
    {
        fwrite($handle, "-- YS CART WC import SQL backup\n");
        fwrite($handle, '-- Created at UTC: ' . gmdate('c') . "\n");
        if (function_exists('home_url')) {
            fwrite($handle, '-- Site: ' . home_url('/') . "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
    }

    private function writeTable($handle, string $table): void
    {
        global $wpdb;

        $quotedTable = $this->quoteIdentifier($table);
        fwrite($handle, "\n-- Table {$table}\n");
        fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");

        $create = $wpdb->get_row('SHOW CREATE TABLE ' . $quotedTable, ARRAY_N);
        if (!is_array($create) || empty($create[1])) {
            return;
        }

        fwrite($handle, (string)$create[1] . ";\n");

        $offset = 0;
        do {
            $rows = $wpdb->get_results(
                'SELECT * FROM ' . $quotedTable . ' LIMIT ' . self::BATCH_SIZE . ' OFFSET ' . (int)$offset,
                ARRAY_A
            );
            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $row) {
                $columns = array_keys($row);
                $columnSql = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
                $valueSql = implode(', ', array_map([$this, 'sqlValue'], array_values($row)));
                fwrite($handle, "INSERT INTO {$quotedTable} ({$columnSql}) VALUES ({$valueSql});\n");
            }

            $count = count($rows);
            $offset += self::BATCH_SIZE;
        } while ($count === self::BATCH_SIZE);
    }

    private function sqlValue($value): string
    {
        global $wpdb;

        if ($value === null) {
            return 'NULL';
        }

        if (method_exists($wpdb, '_real_escape')) {
            return "'" . $wpdb->_real_escape((string)$value) . "'";
        }

        return "'" . addslashes((string)$value) . "'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!$this->isSafeIdentifier($identifier)) {
            throw new \RuntimeException(__('資料表或欄位名稱不安全。', 'ys-cart-woocommerce-import'));
        }

        return '`' . $identifier . '`';
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function isValidBackupName(string $file): bool
    {
        return preg_match('/^ys-cwci-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.sql$/', $file) === 1;
    }

    private function formatBackup(string $path): array
    {
        return [
            'file' => basename($path),
            'size' => is_file($path) ? (int)filesize($path) : 0,
            'created_at' => is_file($path) ? gmdate('c', (int)filemtime($path)) : '',
        ];
    }
}
