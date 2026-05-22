# YS CART WooCommerce Import Design

Date: 2026-05-22
Status: Approved by user with "GO"
Target plugin: `ys-cart-woocommerce-import`

## Goal

Build an independent WordPress plugin that migrates WooCommerce customers, orders, and products into YS CART.

The plugin must support two standalone modes:

- WooCommerce export mode: works on a WooCommerce source site without requiring YS CART.
- YS CART import mode: works on a YS CART target site without requiring WooCommerce.

When WooCommerce and YS CART are both active on the same site, the plugin may expose direct transfer, but direct transfer must still use the same migration package and job pipeline so it is resumable and debuggable.

## Official References

- WooCommerce product CSV importer/exporter: https://woocommerce.com/document/product-csv-importer-exporter/
- WooCommerce HPOS: https://developer.woocommerce.com/docs/features/high-performance-order-storage
- `wc_get_orders()` / `WC_Order_Query`: https://developer.woocommerce.com/docs/extensions/core-concepts/wc-get-orders/
- WooCommerce REST API v3 docs repository: https://github.com/woocommerce/woocommerce-rest-api-docs
- Action Scheduler: https://actionscheduler.org/

## Constraints

- No hard dependency on WooCommerce at plugin boot.
- No hard dependency on YS CART at plugin boot.
- No `admin-ajax.php` endpoints.
- Admin UI triggers work through custom REST endpoints only.
- Long jobs must run in batches and be resumable.
- Imports must be idempotent by source site fingerprint, entity type, and source ID.
- Product/order/customer raw WooCommerce metadata is not fully migrated by default; keep only useful lookup metadata and ignore noisy extension fields unless explicitly mapped later.

## Runtime Modes

### Woo Export Mode

Visible when WooCommerce functions/classes are available.

Capabilities:

- Export customers.
- Export orders.
- Export simple and variable products.
- Export product attributes, variations, images as source URLs, categories, prices, stock, and dimensions.
- Download a migration package.

Woo order reads must use `wc_get_orders()` or `WC_Order_Query`, not direct SQL, so stores using HPOS remain compatible.

Woo product reads should use WooCommerce CRUD objects (`wc_get_products()`, `wc_get_product()`) and may optionally use the built-in product CSV exporter schema as a reference for columns. The canonical migration package is JSONL, not CSV.

### YS Import Mode

Visible when YS CART tables/classes are available.

Capabilities:

- Upload migration package.
- Validate manifest and schema version.
- Preview counts and conflicts.
- Preview mapping for users, addresses, statuses, products, and variants.
- Import customers.
- Import products.
- Import orders.
- Resume failed jobs.
- Download job error report.

### Direct Transfer Mode

Visible only when WooCommerce and YS CART are both active on the same site.

Direct transfer creates an internal package job and immediately imports from that package. It should not bypass mapping, conflict detection, or job state.

## Migration Package

Package format: zip archive.

Required files:

- `manifest.json`
- `customers.jsonl`
- `products.jsonl`
- `orders.jsonl`

Optional files:

- `product_variants.jsonl`
- `product_attributes.jsonl`
- `categories.jsonl`
- `media.jsonl`
- `errors.jsonl`

`manifest.json` includes:

- schema version
- source site URL hash/fingerprint
- WordPress version
- WooCommerce version, if available
- export time
- selected entity types
- entity counts
- package checksum

JSONL is chosen because it can be streamed line by line without loading the whole file into memory.

## Job Engine

The plugin owns lightweight job tables so it can work when WooCommerce is not installed.

Tables:

- `{$wpdb->prefix}ys_wc_migration_jobs`
- `{$wpdb->prefix}ys_wc_migration_maps`
- `{$wpdb->prefix}ys_wc_migration_errors`

Jobs store:

- type: export/import/direct
- entity: customers/products/orders/all
- status: pending/running/paused/completed/failed/cancelled
- source fingerprint
- file path
- total count
- processed count
- success count
- error count
- cursor JSON
- options JSON
- created/started/completed timestamps
- user ID

The runner processes small chunks with a time and memory guard. It uses Action Scheduler when available and otherwise falls back to WP-Cron single events. REST also exposes a `run-next` endpoint so the admin UI can nudge progress without relying on `admin-ajax.php`.

## REST API

Namespace: `ys-cart-wc-import/v1`

Admin-only endpoints:

- `GET /capabilities`
- `POST /export-jobs`
- `POST /import-jobs`
- `POST /direct-jobs`
- `POST /jobs/(?P<id>\d+)/run-next`
- `GET /jobs/(?P<id>\d+)`
- `POST /jobs/(?P<id>\d+)/cancel`
- `GET /jobs/(?P<id>\d+)/download`
- `GET /jobs/(?P<id>\d+)/errors`
- `POST /packages/upload`
- `POST /packages/(?P<id>\d+)/preview`

Permissions:

- Prefer YS CART admin permission classes when available.
- Fallback to `current_user_can( 'manage_options' )`.
- All mutating endpoints require nonce validation through WordPress REST nonce flow.

## Admin UI

Use a normal WordPress admin page with a small JavaScript app.

Tabs:

- Export from WooCommerce
- Import to YS CART
- Direct Transfer
- Jobs

The UI must show:

- detected capabilities
- selected entities
- batch progress
- conflict counts
- missing users
- mapping choices
- downloadable error report

No UI action may call `admin-ajax.php`.

## Customer Migration

Source:

- WooCommerce customer users
- Woo billing/shipping fields
- order customer details for guest orders when needed

Same-site migration:

- If the source fingerprint matches the target site and source user ID exists, use that WordPress user.
- If YS customer does not exist, call YS CART customer conversion where available.

Cross-site migration:

- Match users by email.
- If a matching WordPress user exists, convert/create YS customer from that user.
- If no matching user exists, preview lists missing users.
- Default approved behavior: continue import may create WordPress users with generated random passwords, no notification email, and marker meta `_ys_wc_imported_user = 1`.
- The created WP user is then converted into a YS CART customer.

Guest orders:

- If an order has no Woo customer ID but has billing email, treat the email as the customer key.
- If no email exists, assign to a generated imported guest user bucket keyed by package/job.

## Order Migration

Orders are imported for lookup/history. They do not require binding to real YS CART products.

Status mapping:

- `pending` -> `pending`
- `on-hold` -> `offline_payment`
- `processing` -> `processing`
- `completed` -> `completed`
- `cancelled` -> `cancelled`
- `refunded` -> `refunded`
- `failed` -> `failed`
- `trash` -> `trash`

Core fields:

- order number
- customer/user
- status
- currency
- subtotal
- shipping total
- discount total
- total
- refunded amount
- payment method ID/title
- transaction ID
- shipping method title/provider
- billing address
- shipping address
- customer note
- customer IP/user agent
- created/paid/completed timestamps

Address mapping:

- Woo first/last name becomes YS recipient/orderer name.
- Woo address_1/address_2 map to YS address/address2.
- Woo city/state/postcode/country map to YS city/state/postcode/country.
- YS district is blank unless a known Taiwan address plugin field is detected.
- UI preview shows native mapping and lets the user accept before import.

Order items:

- If product migration produced a map, use mapped product/variant IDs.
- Otherwise create/find one hidden placeholder YS product named `WooCommerce Imported Item`.
- Store original Woo product ID, variation ID, SKU, and item meta in the YS order item `meta` JSON.

## Product Migration

Product matching:

1. Existing source ID mapping.
2. SKU.
3. Slug.

Simple products:

- Woo simple -> YS simple.
- Map title, slug, description, short description, SKU, price, sale price, stock, dimensions, featured image URL, gallery URLs, categories.

Variable products:

- Woo variable parent -> YS variable product.
- Woo attributes -> YS product attributes.
- Woo variations -> YS product variants.
- Variation attributes become variant attributes JSON and label.
- Variation SKU, prices, stock, dimensions, and image URL are preserved.

Images:

- First version stores remote source URLs in YS fields.
- Media sideload is deferred to a later optional batch because it is server-heavy.

## Failure Handling

- Every row-level failure is written to `ys_wc_migration_errors`.
- A failed row does not fail the whole job unless the package is invalid.
- Jobs can resume from the last cursor.
- Imports are idempotent through `ys_wc_migration_maps`.
- Re-running an import updates existing mapped entities instead of duplicating them where safe.

## Security

- Admin-only capability checks.
- REST nonce validation for mutating operations.
- Uploaded package must be zip/jsonl/json only.
- Reject path traversal entries in zip files.
- Store packages under WordPress uploads with plugin-specific subdirectory and generated filenames.
- Do not expose package downloads without permission check.
- Never trust package user IDs during cross-site import; match by site fingerprint plus email.

## Testing Strategy

Static tests:

- Plugin does not register `wp_ajax_` or `wp_ajax_nopriv_` hooks.
- Plugin boots without WooCommerce.
- Plugin boots without YS CART.
- REST routes are registered with permission callbacks.

Mapper tests:

- Woo customer to YS customer DTO.
- Woo order status to YS status.
- Woo address to YS address.
- Woo simple product to YS product DTO.
- Woo variable product and variations to YS product/variant DTOs.

Package tests:

- Manifest validation.
- JSONL streaming read/write.
- Zip path traversal rejection.
- Import map idempotency.

Manual integration:

- Export sample package from dev-checkout WooCommerce.
- Import package into a YS CART dev site.
- Verify no `admin-ajax.php` calls from the migration UI.

