<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Security;

defined('ABSPATH') || exit;

final class ProtectedDirectory
{
    /**
     * Ensure a local storage directory exists and is not web-browsable.
     *
     * @param array<int, string> $blockedExtensions
     */
    public static function ensure(string $dir, array $blockedExtensions = ['.zip', '.json', '.jsonl', '.sql']): void
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException('Unable to create protected directory.');
        }

        $index = rtrim($dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $htaccess = rtrim($dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . '.htaccess';
        self::writeGuardFileIfNeeded(
            $htaccess,
            "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            ['Require all denied', 'Deny from all']
        );

        $extensions = array_values(array_unique(array_filter(array_map(static function (string $ext): string {
            $ext = strtolower(trim($ext));
            return $ext === '' ? '' : (str_starts_with($ext, '.') ? $ext : '.' . $ext);
        }, $blockedExtensions))));

        $rules = '';
        foreach ($extensions as $ext) {
            $rules .= '<add fileExtension="' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . '" allowed="false" />';
        }

        $webConfig = rtrim($dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'web.config';
        self::writeGuardFileIfNeeded(
            $webConfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions>{$rules}</fileExtensions></requestFiltering></security></system.webServer></configuration>\n",
            array_map(static fn (string $ext): string => 'fileExtension="' . $ext . '"', $extensions)
        );
    }

    /**
     * Existing upgrade directories may already contain weak guard files.
     *
     * @param array<int, string> $requiredMarkers
     */
    private static function writeGuardFileIfNeeded(string $path, string $body, array $requiredMarkers): void
    {
        $current = is_file($path) ? ((string)file_get_contents($path)) : '';
        $valid = $current !== '';

        foreach ($requiredMarkers as $marker) {
            if (strpos($current, $marker) === false) {
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            file_put_contents($path, $body);
        }
    }
}
