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
            'weight' => (float)(($record['weight'] ?? '') !== '' ? $record['weight'] : 0),
            'length' => (float)(($record['length'] ?? '') !== '' ? $record['length'] : 0),
            'width' => (float)(($record['width'] ?? '') !== '' ? $record['width'] : 0),
            'height' => (float)(($record['height'] ?? '') !== '' ? $record['height'] : 0),
        ];
    }

    public static function mapVariant(array $record): array
    {
        $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];

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
            'is_active' => 1,
        ];
    }
}
