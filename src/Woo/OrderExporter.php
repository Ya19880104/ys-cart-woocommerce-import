<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Woo;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\JobRepository;
use YangSheep\YsCartWooImport\Packages\PackageWriter;

final class OrderExporter
{
    public function exportBatch(int $jobId, array $options, array $cursor, array $limits): array
    {
        if (!function_exists('wc_get_orders')) {
            return ['done' => true, 'message' => 'WooCommerce is not available.'];
        }

        $page = max(1, (int)($cursor['page'] ?? 1));
        $limit = max(1, min(100, (int)($limits['max_rows'] ?? 50)));
        $packageId = $options['package_id'] ?? ('job-' . $jobId);

        $ids = wc_get_orders([
            'type' => 'shop_order',
            'status' => $options['statuses'] ?? array_keys(wc_get_order_statuses()),
            'limit' => $limit,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ]);

        $writer = new PackageWriter();
        foreach ($ids as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }

            $writer->appendJsonLine($packageId, 'orders.jsonl', $this->serializeOrder($order));
        }

        $processed = count($ids);
        $done = $processed < $limit;
        $repo = new JobRepository();
        $repo->updateProgress($jobId, [
            'processed_count' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);
        $repo->updateCursor($jobId, [
            'page' => $page + 1,
            'processed' => ((int)($cursor['processed'] ?? 0)) + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);

        return ['done' => $done, 'processed' => $processed];
    }

    private function serializeOrder($order): array
    {
        $items = [];
        foreach ($order->get_items() as $itemId => $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $items[] = [
                'source_item_id' => (string)$itemId,
                'source_product_id' => (string)$item->get_product_id(),
                'source_variation_id' => (string)$item->get_variation_id(),
                'name' => $item->get_name(),
                'sku' => $product && method_exists($product, 'get_sku') ? $product->get_sku() : '',
                'quantity' => (int)$item->get_quantity(),
                'subtotal' => (string)$item->get_subtotal(),
                'total' => (string)$item->get_total(),
                'tax_total' => (string)$item->get_total_tax(),
                'meta' => $this->itemMeta($item),
            ];
        }

        $shippingItems = [];
        foreach ($order->get_shipping_methods() as $method) {
            $shippingItems[] = [
                'method_id' => $method->get_method_id(),
                'instance_id' => $method->get_instance_id(),
                'name' => $method->get_name(),
                'total' => (string)$method->get_total(),
            ];
        }

        return [
            'source_id' => (string)$order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'customer_id' => (string)$order->get_customer_id(),
            'billing_email' => $order->get_billing_email(),
            'prices' => [
                'subtotal' => (string)$order->get_subtotal(),
                'shipping_total' => (string)$order->get_shipping_total(),
                'discount_total' => (string)$order->get_discount_total(),
                'total' => (string)$order->get_total(),
                'refunded_total' => (string)$order->get_total_refunded(),
            ],
            'payment' => [
                'method' => $order->get_payment_method(),
                'method_title' => $order->get_payment_method_title(),
                'transaction_id' => $order->get_transaction_id(),
                'paid_at' => $this->date($order->get_date_paid()),
            ],
            'shipping_methods' => $shippingItems,
            'billing' => $this->address($order, 'billing'),
            'shipping' => $this->address($order, 'shipping'),
            'customer_note' => $order->get_customer_note(),
            'customer_ip' => $order->get_customer_ip_address(),
            'customer_user_agent' => $order->get_customer_user_agent(),
            'created_at' => $this->date($order->get_date_created()),
            'completed_at' => $this->date($order->get_date_completed()),
            'items' => $items,
        ];
    }

    private function address($order, string $type): array
    {
        $prefix = $type === 'shipping' ? 'shipping' : 'billing';
        return [
            'first_name' => (string)$order->{"get_{$prefix}_first_name"}(),
            'last_name' => (string)$order->{"get_{$prefix}_last_name"}(),
            'company' => (string)$order->{"get_{$prefix}_company"}(),
            'address_1' => (string)$order->{"get_{$prefix}_address_1"}(),
            'address_2' => (string)$order->{"get_{$prefix}_address_2"}(),
            'city' => (string)$order->{"get_{$prefix}_city"}(),
            'state' => (string)$order->{"get_{$prefix}_state"}(),
            'postcode' => (string)$order->{"get_{$prefix}_postcode"}(),
            'country' => (string)$order->{"get_{$prefix}_country"}(),
            'email' => $prefix === 'billing' ? (string)$order->get_billing_email() : '',
            'phone' => $prefix === 'billing' ? (string)$order->get_billing_phone() : '',
        ];
    }

    private function itemMeta($item): array
    {
        $meta = [];
        foreach ($item->get_meta_data() as $entry) {
            $meta[] = [
                'key' => $entry->key,
                'value' => is_scalar($entry->value) ? (string)$entry->value : wp_json_encode($entry->value),
            ];
        }

        return $meta;
    }

    private function date($date): string
    {
        return $date && method_exists($date, 'date') ? $date->date(DATE_ATOM) : '';
    }
}

