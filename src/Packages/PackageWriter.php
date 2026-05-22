<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Packages;

defined('ABSPATH') || exit;

use RuntimeException;
use ZipArchive;

final class PackageWriter
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        if ($dir === null) {
            $upload = wp_upload_dir();
            $dir = trailingslashit((string)$upload['basedir']) . 'ys-cart-wc-import';
        }

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new RuntimeException('Unable to create migration package directory.');
        }

        $this->dir = rtrim($dir, '/\\');
    }

    public function appendJsonLine(string $packageId, string $fileName, array $record): void
    {
        if (!PackageReader::isSafeEntryName($fileName)) {
            throw new RuntimeException('Unsafe package file name.');
        }

        $path = $this->workingPath($packageId, $fileName);
        $line = wp_json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public function writeManifest(string $packageId, array $manifest): void
    {
        file_put_contents(
            $this->workingPath($packageId, 'manifest.json'),
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function ensureFile(string $packageId, string $fileName): void
    {
        if (!PackageReader::isSafeEntryName($fileName)) {
            throw new RuntimeException('Unsafe package file name.');
        }

        $path = $this->workingPath($packageId, $fileName);
        if (!is_file($path)) {
            file_put_contents($path, '');
        }
    }

    public function resetPackage(string $packageId): void
    {
        $base = $this->workingDir($packageId);
        foreach (glob($base . '/*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to reset package working file.');
            }
        }

        $zipPath = $this->dir . '/' . $this->safePackageId($packageId) . '.zip';
        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new RuntimeException('Unable to reset package zip.');
        }
    }

    public function zip(string $packageId): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to create migration packages.');
        }

        $base = $this->workingDir($packageId);
        $zipPath = $this->dir . '/' . $packageId . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create package zip.');
        }

        foreach (glob($base . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $zip->addFile($file, basename($file));
            }
        }

        $zip->close();
        return $zipPath;
    }

    private function workingPath(string $packageId, string $fileName): string
    {
        return $this->workingDir($packageId) . '/' . $fileName;
    }

    private function workingDir(string $packageId): string
    {
        $safe = $this->safePackageId($packageId);
        $path = $this->dir . '/' . $safe;

        if (!is_dir($path) && !wp_mkdir_p($path)) {
            throw new RuntimeException('Unable to create package working directory.');
        }

        return $path;
    }

    private function safePackageId(string $packageId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $packageId) ?: wp_generate_uuid4();
    }
}
