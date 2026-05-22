<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

final class OrderMapper
{
    public static function mapStatus(string $wooStatus): string
    {
        return [
            'pending' => 'pending',
            'on-hold' => 'offline_payment',
            'processing' => 'processing',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            'trash' => 'trash',
        ][$wooStatus] ?? 'pending';
    }

    public static function mapAddress(array $address): array
    {
        $name = trim((string)($address['first_name'] ?? '') . ' ' . (string)($address['last_name'] ?? ''));

        return [
            'name' => $name,
            'phone' => (string)($address['phone'] ?? ''),
            'email' => (string)($address['email'] ?? ''),
            'country' => (string)($address['country'] ?? ''),
            'postcode' => (string)($address['postcode'] ?? ''),
            'state' => (string)($address['state'] ?? ''),
            'city' => (string)($address['city'] ?? ''),
            'district' => (string)($address['district'] ?? ''),
            'address' => (string)($address['address_1'] ?? ''),
            'address2' => (string)($address['address_2'] ?? ''),
        ];
    }

    public static function mapOrder(array $record, int $customerId, int $userId): array
    {
        $prices = is_array($record['prices'] ?? null) ? $record['prices'] : [];
        $payment = is_array($record['payment'] ?? null) ? $record['payment'] : [];
        $billing = self::mapAddress(is_array($record['billing'] ?? null) ? $record['billing'] : []);
        $shipping = self::mapAddress(is_array($record['shipping'] ?? null) ? $record['shipping'] : []);
        $shippingMethods = is_array($record['shipping_methods'] ?? null) ? $record['shipping_methods'] : [];
        $shippingTitle = '';

        if ($shippingMethods !== []) {
            $first = reset($shippingMethods);
            $shippingTitle = is_array($first) ? (string)($first['name'] ?? '') : '';
        }

        return [
            'order_number' => 'WC-' . (string)($record['number'] ?? $record['source_id'] ?? ''),
            'customer_id' => $customerId,
            'user_id' => $userId,
            'status' => self::mapStatus((string)($record['status'] ?? 'pending')),
            'subtotal' => (float)($prices['subtotal'] ?? 0),
            'shipping_total' => (float)($prices['shipping_total'] ?? 0),
            'discount_total' => (float)($prices['discount_total'] ?? 0),
            'total' => (float)($prices['total'] ?? 0),
            'currency' => (string)($record['currency'] ?? 'TWD'),
            'gateway_id' => (string)($payment['method'] ?? ''),
            'gateway_trade_no' => (string)($payment['transaction_id'] ?? ''),
            'payment_method' => (string)($payment['method_title'] ?? $payment['method'] ?? ''),
            'payment_detail' => [
                'source' => 'woocommerce',
                'method' => (string)($payment['method'] ?? ''),
                'method_title' => (string)($payment['method_title'] ?? ''),
            ],
            'paid_at' => self::mysqlDate((string)($payment['paid_at'] ?? '')),
            'shipping_provider' => $shippingTitle,
            'billing_name' => $billing['name'],
            'billing_phone' => $billing['phone'],
            'billing_email' => $billing['email'],
            'billing_country' => $billing['country'],
            'billing_postcode' => $billing['postcode'],
            'billing_state' => $billing['state'],
            'billing_city' => $billing['city'],
            'billing_district' => $billing['district'],
            'billing_address' => $billing['address'],
            'billing_address2' => $billing['address2'],
            'shipping_name' => $shipping['name'] !== '' ? $shipping['name'] : $billing['name'],
            'shipping_phone' => $shipping['phone'] !== '' ? $shipping['phone'] : $billing['phone'],
            'shipping_country' => $shipping['country'] !== '' ? $shipping['country'] : $billing['country'],
            'shipping_postcode' => $shipping['postcode'] !== '' ? $shipping['postcode'] : $billing['postcode'],
            'shipping_state' => $shipping['state'] !== '' ? $shipping['state'] : $billing['state'],
            'shipping_city' => $shipping['city'] !== '' ? $shipping['city'] : $billing['city'],
            'shipping_district' => $shipping['district'],
            'shipping_address' => $shipping['address'] !== '' ? $shipping['address'] : $billing['address'],
            'shipping_address2' => $shipping['address2'] !== '' ? $shipping['address2'] : $billing['address2'],
            'customer_note' => (string)($record['customer_note'] ?? ''),
            'customer_ip' => (string)($record['customer_ip'] ?? ''),
            'customer_ua' => (string)($record['customer_user_agent'] ?? ''),
            'orderer_name' => $billing['name'],
            'orderer_phone' => $billing['phone'],
            'orderer_email' => $billing['email'],
            'created_at' => self::mysqlDate((string)($record['created_at'] ?? '')),
        ];
    }

    public static function mapItem(array $item, int $productId, ?int $variantId = null): array
    {
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $lineTotal = (float)($item['total'] ?? 0);

        return [
            'product_id' => $productId,
            'variant_id' => $variantId ?: null,
            'product_title' => (string)($item['name'] ?? 'WooCommerce Item'),
            'variant_label' => '',
            'sku' => (string)($item['sku'] ?? ''),
            'quantity' => $quantity,
            'unit_price' => $quantity > 0 ? $lineTotal / $quantity : $lineTotal,
            'sale_unit_price' => $quantity > 0 ? $lineTotal / $quantity : $lineTotal,
            'line_total' => $lineTotal,
            'meta' => [
                'source' => 'woocommerce',
                'source_item_id' => (string)($item['source_item_id'] ?? ''),
                'source_product_id' => (string)($item['source_product_id'] ?? ''),
                'source_variation_id' => (string)($item['source_variation_id'] ?? ''),
                'raw_meta' => $item['meta'] ?? [],
            ],
        ];
    }

    private static function mysqlDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }
}

