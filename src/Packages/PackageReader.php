<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Packages;

defined('ABSPATH') || exit;

use Generator;
use RuntimeException;
use ZipArchive;

final class PackageReader
{
    public static function isSafeEntryName(string $entry): bool
    {
        $entry = str_replace('\\', '/', $entry);

        if ($entry === '' || str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:\//', $entry)) {
            return false;
        }

        $segments = explode('/', $entry);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function readManifest(string $zipPath): array
    {
        $zip = $this->openZip($zipPath);
        $json = $zip->getFromName('manifest.json');
        $zip->close();

        if ($json === false) {
            throw new RuntimeException('Package manifest.json is missing.');
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Package manifest.json is invalid.');
        }

        $errors = ManifestValidator::validate($manifest);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        return $manifest;
    }

    public function streamJsonLines(string $zipPath, string $entryName): Generator
    {
        if (!self::isSafeEntryName($entryName)) {
            throw new RuntimeException('Unsafe package entry name.');
        }

        $zip = $this->openZip($zipPath);
        $stream = $zip->getStream($entryName);
        if (!$stream) {
            $zip->close();
            throw new RuntimeException("Package entry {$entryName} is missing.");
        }

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);
            if (!is_array($record)) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException("Invalid JSONL record in {$entryName}.");
            }

            yield $record;
        }

        fclose($stream);
        $zip->close();
    }

    private function openZip(string $zipPath): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read migration packages.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open migration package.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!self::isSafeEntryName((string)$name)) {
                $zip->close();
                throw new RuntimeException('Package contains unsafe entry.');
            }
        }

        return $zip;
    }
}
