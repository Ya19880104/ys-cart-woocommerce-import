<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

final class OrderMapper
{
    /**
     * YS CART 訂單狀態白名單（鏡射核心 Enums\YSOrderStatus；匯入端驗證 status_map 用）。
     * v0.7.0
     */
    public const YS_STATUSES = [
        'pending', 'paid', 'processing', 'awaiting_ship', 'shipping', 'shipped',
        'completed', 'cancelled', 'refunded', 'offline_payment', 'failed',
        'timeout', 'abnormal', 'trash',
    ];

    /**
     * Woo → YS 的預設狀態對應（v0.7.0 抽成可查詢的表，狀態端點計算 mapped_to 用）。
     *
     * @return array<string,string>
     */
    public static function statusMap(): array
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
        ];
    }

    /**
     * @param array<string,string> $overrides 使用者自訂對應（woo status → ys status），優先於預設表。
     */
    public static function mapStatus(string $wooStatus, array $overrides = []): string
    {
        if (isset($overrides[$wooStatus]) && in_array($overrides[$wooStatus], self::YS_STATUSES, true)) {
            return $overrides[$wooStatus];
        }

        return self::statusMap()[$wooStatus] ?? 'pending';
    }

    public static function mapAddress(array $address): array
    {
        $name = trim((string)($address['first_name'] ?? '') . ' ' . (string)($address['last_name'] ?? ''));

        return [
            'name' => $name,
            'phone' => (string)($address['phone'] ?? ''),
            'email' => (string)($address['email'] ?? ''),
            'country' => self::countryCode((string)($address['country'] ?? '')),
            'postcode' => self::postcode((string)($address['postcode'] ?? '')),
            'state' => (string)($address['state'] ?? ''),
            'city' => (string)($address['city'] ?? ''),
            'district' => (string)($address['district'] ?? ''),
            'address' => (string)($address['address_1'] ?? ''),
            'address2' => (string)($address['address_2'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $statusOverrides v0.7.0 使用者自訂狀態對應。
     */
    public static function mapOrder(array $record, int $customerId, int $userId, array $statusOverrides = []): array
    {
        $prices = is_array($record['prices'] ?? null) ? $record['prices'] : [];
        $payment = is_array($record['payment'] ?? null) ? $record['payment'] : [];
        $billing = self::mapAddress(is_array($record['billing'] ?? null) ? $record['billing'] : []);
        $shipping = self::mapAddress(is_array($record['shipping'] ?? null) ? $record['shipping'] : []);
        $shippingMethods = is_array($record['shipping_methods'] ?? null) ? $record['shipping_methods'] : [];
        $shippingTitle = '';

        if ($shippingMethods !== []) {
            $first = reset($shippingMethods);
            if (is_array($first)) {
                $shippingTitle = (string)($first['name'] ?? '');
            }
        }

        return [
            'order_number' => 'WC-' . (string)($record['number'] ?? $record['source_id'] ?? ''),
            'customer_id' => $customerId,
            'user_id' => $userId,
            'status' => self::mapStatus((string)($record['status'] ?? 'pending'), $statusOverrides),
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

        try {
            $dt = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function countryCode(string $country): string
    {
        $normalized = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $normalized) === 1) {
            return $normalized;
        }

        // v0.7.10：擴充別名表，涵蓋常見亞洲／跨境國家全名，避免 Japan／Singapore 等真實國別
        //   被下方 TW fallback 靜默改成台灣（台灣店家跨境代購情境常見）。
        $aliases = [
            // 既有
            'AUSTRALIA' => 'AU',
            'MALAYSIA' => 'MY',
            'MYR' => 'MY',
            'NEW ZEALAND' => 'NZ',
            'UK' => 'GB',
            'UNITED KINGDOM' => 'GB',
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'USA' => 'US',
            // 台灣
            'TAIWAN' => 'TW',
            'TAIWAN, PROVINCE OF CHINA' => 'TW',
            'REPUBLIC OF CHINA' => 'TW',
            // 東亞
            'JAPAN' => 'JP',
            'SOUTH KOREA' => 'KR',
            'KOREA' => 'KR',
            'KOREA, REPUBLIC OF' => 'KR',
            'CHINA' => 'CN',
            "CHINA, PEOPLE'S REPUBLIC OF" => 'CN',
            'HONG KONG' => 'HK',
            'MACAU' => 'MO',
            'MACAO' => 'MO',
            // 東南亞
            'SINGAPORE' => 'SG',
            'THAILAND' => 'TH',
            'VIETNAM' => 'VN',
            'VIET NAM' => 'VN',
            'INDONESIA' => 'ID',
            'PHILIPPINES' => 'PH',
            'CAMBODIA' => 'KH',
            'MYANMAR' => 'MM',
            'BRUNEI' => 'BN',
            'LAOS' => 'LA',
            // 南亞
            'INDIA' => 'IN',
            // 其他常見
            'CANADA' => 'CA',
            'GERMANY' => 'DE',
            'FRANCE' => 'FR',
            'JAPAN (JP)' => 'JP',
        ];

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        if (strpos($normalized, 'TW') === 0) {
            return 'TW';
        }

        // Fallback：無法辨識者一律視為台灣（刻意保留 —— 處理台灣店家歷史髒資料
        // 如 TW450／email 字串等；見 0.7.5）。真實非台灣國別請補上別名表。
        return 'TW';
    }

    private static function postcode(string $postcode): string
    {
        $normalized = trim($postcode);

        return strlen($normalized) <= 10 ? $normalized : '';
    }
}
