<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

final class ProductMapper
{
    public static function mapProduct(array $record): array
    {
        $type = (string)($record['type'] ?? 'simple');
        $ysType = $type === 'variable' ? 'variable' : 'simple';

        return [
            'title' => (string)($record['name'] ?? ''),
            'slug' => (string)($record['slug'] ?? ''),
            'type' => $ysType,
            'status' => ((string)($record['status'] ?? 'draft')) === 'publish' ? 'publish' : 'draft',
            'short_description' => (string)($record['short_description'] ?? ''),
            'description' => (string)($record['description'] ?? ''),
            'sku' => (string)($record['sku'] ?? ''),
            'price' => (float)(($record['regular_price'] ?? '') !== '' ? $record['regular_price'] : ($record['price'] ?? 0)),
            'sale_price' => (float)(($record['sale_price'] ?? '') !== '' ? $record['sale_price'] : 0),
            'stock_qty' => $ysType === 'variable' ? -1 : (int)($record['stock_quantity'] ?? -1),
            'image_url' => (string)($record['image_url'] ?? ''),
            'gallery_urls' => is_array($record['gallery_urls'] ?? null) ? $record['gallery_urls'] : [],
            'is_virtual' => self::boolFlag($record['is_virtual'] ?? false) ? 1 : 0,
            'download_limit' => (int)(($record['download_limit'] ?? '') !== '' ? $record['download_limit'] : -1),
            'download_expiry_days' => (int)(($record['download_expiry_days'] ?? '') !== '' ? $record['download_expiry_days'] : -1),
            'access_type' => self::accessType($record),
            'weight' => (float)(($record['weight'] ?? '') !== '' ? $record['weight'] : 0),
            'length' => (float)(($record['length'] ?? '') !== '' ? $record['length'] : 0),
            'width' => (float)(($record['width'] ?? '') !== '' ? $record['width'] : 0),
            'height' => (float)(($record['height'] ?? '') !== '' ? $record['height'] : 0),
        ];
    }

    public static function mapVariant(array $record): array
    {
        $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
        $isVirtual = self::boolFlag($record['is_virtual'] ?? false);
        $isDownloadable = self::boolFlag($record['is_downloadable'] ?? false);

        return [
            'attributes' => $attributes,
            'label' => implode(' / ', array_filter(array_map('strval', array_values($attributes)))),
            'sku' => (string)($record['sku'] ?? ''),
            'price' => (float)(($record['regular_price'] ?? '') !== '' ? $record['regular_price'] : ($record['price'] ?? 0)),
            'sale_price' => (float)(($record['sale_price'] ?? '') !== '' ? $record['sale_price'] : 0),
            'stock_qty' => (int)($record['stock_quantity'] ?? -1),
            'weight' => (float)(($record['weight'] ?? '') !== '' ? $record['weight'] : 0),
            'length' => (float)(($record['length'] ?? '') !== '' ? $record['length'] : 0),
            'width' => (float)(($record['width'] ?? '') !== '' ? $record['width'] : 0),
            'height' => (float)(($record['height'] ?? '') !== '' ? $record['height'] : 0),
            'image_url' => (string)($record['image_url'] ?? ''),
            'requires_shipping_override' => ($isVirtual || $isDownloadable) ? 0 : 1,
            'has_digital_override' => $isDownloadable ? 1 : 0,
            'is_active' => 1,
        ];
    }

    private static function boolFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function accessType(array $record): ?string
    {
        $raw = (string)($record['access_type'] ?? '');
        $accessType = function_exists('sanitize_key')
            ? sanitize_key($raw)
            : strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $raw) ?? '');
        if (in_array($accessType, ['download', 'stream', 'redirect', 'perpetual', 'timed'], true)) {
            return $accessType;
        }

        return self::boolFlag($record['is_downloadable'] ?? false) ? 'download' : null;
    }
}
