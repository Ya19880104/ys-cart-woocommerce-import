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
        $alreadyProcessed = (int)($cursor['processed'] ?? 0);
        $maxTotal = max(0, (int)($options['max_total'] ?? 0));
        if ($maxTotal > 0 && $alreadyProcessed >= $maxTotal) {
            return ['done' => true, 'processed' => 0];
        }

        $limit = max(1, min(100, (int)($limits['max_rows'] ?? 50)));
        if ($maxTotal > 0) {
            $limit = min($limit, $maxTotal - $alreadyProcessed);
        }
        $packageId = $options['package_id'] ?? ('job-' . $jobId);

        $ids = wc_get_orders([
            'type' => 'shop_order',
            'status' => $options['statuses'] ?? 'any',
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
        $repo->updateProgressAndCursor($jobId, [
            'processed_count' => $alreadyProcessed + $processed,
            'success_count' => ((int)($cursor['success'] ?? 0)) + $processed,
        ], [
            'page' => $page + 1,
            'processed' => $alreadyProcessed + $processed,
            'success' => ((int)($cursor['success'] ?? 0)) + $processed,
        ]);

        return ['done' => $done || ($maxTotal > 0 && $alreadyProcessed + $processed >= $maxTotal), 'processed' => $processed];
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
            'download_permissions' => (new DownloadPermissionExporter())->forOrder((int)$order->get_id()),
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
        if (!$date) {
            return '';
        }

        if (method_exists($date, 'getTimestamp')) {
            $timestamp = (int)$date->getTimestamp();
            return $timestamp > 0 ? gmdate(DATE_ATOM, $timestamp) : '';
        }

        return method_exists($date, 'date') ? $date->date(DATE_ATOM) : '';
    }
}
