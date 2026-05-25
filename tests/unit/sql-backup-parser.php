<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Backups/SqlBackupManager.php';

use YangSheep\YsCartWooImport\Backups\SqlBackupManager;

$sql = "CREATE TABLE t (id int, note text);\n"
    . "INSERT INTO t VALUES (1, 'hello; world'), (2, 'quote\\'s ok');\n"
    . "-- comment;\n"
    . "INSERT INTO t VALUES (3, \"double; quote\");\n";

$statements = SqlBackupManager::splitSqlStatements($sql);

if (count($statements) !== 3) {
    throw new RuntimeException('SQL parser must ignore semicolons inside quoted strings and comments.');
}

if (!str_contains($statements[1], "hello; world")) {
    throw new RuntimeException('SQL parser corrupted quoted semicolon content.');
}

