<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

final class CustomerMapper
{
    public static function mapCustomer(array $record, int $userId): array
    {
        $billing = is_array($record['billing'] ?? null) ? OrderMapper::mapAddress($record['billing']) : [];
        $shipping = is_array($record['shipping'] ?? null) ? OrderMapper::mapAddress($record['shipping']) : [];

        return [
            'user_id' => $userId,
            'display_name' => (string)($record['display_name'] ?? $record['email'] ?? ''),
            'email' => strtolower((string)($record['email'] ?? '')),
            'phone' => (string)($billing['phone'] ?? ''),
            'country' => (string)($billing['country'] ?? 'TW'),
            'billing_name' => (string)($billing['name'] ?? ''),
            'billing_phone' => (string)($billing['phone'] ?? ''),
            'billing_country' => (string)($billing['country'] ?? ''),
            'billing_postcode' => (string)($billing['postcode'] ?? ''),
            'billing_state' => (string)($billing['state'] ?? ''),
            'billing_city' => (string)($billing['city'] ?? ''),
            'billing_district' => (string)($billing['district'] ?? ''),
            'billing_address' => (string)($billing['address'] ?? ''),
            'billing_address2' => (string)($billing['address2'] ?? ''),
            'shipping_name' => (string)($shipping['name'] ?? ''),
            'shipping_phone' => (string)($shipping['phone'] ?? ''),
            'shipping_country' => (string)($shipping['country'] ?? ''),
            'shipping_postcode' => (string)($shipping['postcode'] ?? ''),
            'shipping_state' => (string)($shipping['state'] ?? ''),
            'shipping_city' => (string)($shipping['city'] ?? ''),
            'shipping_district' => (string)($shipping['district'] ?? ''),
            'shipping_address' => (string)($shipping['address'] ?? ''),
            'shipping_address2' => (string)($shipping['address2'] ?? ''),
        ];
    }
}

