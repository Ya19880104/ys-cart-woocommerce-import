# YS CART WooCommerce Import

Standalone WordPress plugin for exporting WooCommerce data and importing it into YS CART.

## Modes

- WooCommerce export: available when WooCommerce is active. YS CART is not required.
- YS CART import: available when YS CART is active. WooCommerce is not required.
- Direct transfer: reserved for sites that have both WooCommerce and YS CART active.

## Current Scope

- Export WooCommerce customers, products, and orders into JSONL-based zip packages.
- Import customers, products, variants, and orders into YS CART.
- Run migration work through REST-triggered jobs.
- Use Action Scheduler when available and WP-Cron as fallback.
- Avoid WordPress admin request endpoints entirely.

## Important Mapping Rules

- WooCommerce orders are read through `wc_get_orders()` for HPOS compatibility.
- Cross-site missing users are auto-created with random passwords and `_ys_wc_imported_user = 1`.
- Woo order items do not require real product binding. If no migrated product map exists, the importer creates a hidden placeholder product named `WooCommerce Imported Item`.
- Product matching uses source map, SKU, then slug.
- Variable products create YS CART variable products plus variant rows.

## Admin UI

Open:

`Tools > YS CART Woo Import`

The UI calls REST routes under:

`/wp-json/ys-cart-wc-import/v1`

## Verification

Run:

```bash
php tests/run-static.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

