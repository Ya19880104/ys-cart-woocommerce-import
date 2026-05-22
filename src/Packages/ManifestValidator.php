<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Packages;

defined('ABSPATH') || exit;

final class ManifestValidator
{
    public const SCHEMA_VERSION = '1.0';

    public static function validate(array $manifest): array
    {
        $errors = [];

        if (($manifest['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $errors[] = 'Unsupported or missing schema_version.';
        }

        if (empty($manifest['source']['site_url_hash']) || !is_string($manifest['source']['site_url_hash'])) {
            $errors[] = 'Missing source.site_url_hash.';
        }

        if (empty($manifest['created_at']) || !is_string($manifest['created_at'])) {
            $errors[] = 'Missing created_at.';
        }

        foreach (['entities', 'files'] as $key) {
            if (empty($manifest[$key]) || !is_array($manifest[$key])) {
                $errors[] = "Missing {$key}.";
            }
        }

        foreach (['customers', 'products', 'orders'] as $entity) {
            if (!isset($manifest['files'][$entity])) {
                $errors[] = "Missing file mapping for {$entity}.";
                continue;
            }

            if (!PackageReader::isSafeEntryName((string)$manifest['files'][$entity])) {
                $errors[] = "Unsafe file mapping for {$entity}.";
            }
        }

        return $errors;
    }
}

