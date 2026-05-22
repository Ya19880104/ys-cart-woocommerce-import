# YS CART WooCommerce Import Implementation Plan

Date: 2026-05-22
Design spec: `docs/superpowers/specs/2026-05-22-ys-cart-woocommerce-import-design.md`
Execution mode: inline `executing-plans`

## Phase 1 - Plugin Skeleton

1. Create WordPress plugin metadata and bootstrap.
   - Add `ys-cart-woocommerce-import.php`.
   - Add constants for version, file, dir, URL, basename.
   - Add Composer PSR-4 autoload and fallback autoloader.
   - Namespace: `YangSheep\YsCartWooImport`.
   - Register activation hook for custom tables.
   - Declare WooCommerce HPOS compatibility if WooCommerce utility class exists.

2. Add Composer metadata.
   - Add `composer.json`.
   - Require PHP `>=8.0`.
   - Configure PSR-4 autoload.

3. Add base plugin application class.
   - Add `src/Plugin.php`.
   - Register admin page, REST routes, assets, and job hooks.
   - Must not fatal when WooCommerce or YS CART is missing.

4. Add docs and basic repo files.
   - Add `README.md`.
   - Add `.gitignore`.
   - Add `index.php`.

Verification:

- `php -l ys-cart-woocommerce-import.php`
- `php -l src/Plugin.php`
- Static grep confirms no `wp_ajax_`.

## Phase 2 - Database and Job Engine

1. Add table maker.
   - Add `src/Database/TableMaker.php`.
   - Create `ys_wc_migration_jobs`.
   - Create `ys_wc_migration_maps`.
   - Create `ys_wc_migration_errors`.

2. Add repositories.
   - Add `src/Database/JobRepository.php`.
   - Add `src/Database/MapRepository.php`.
   - Add `src/Database/ErrorRepository.php`.

3. Add job runner.
   - Add `src/Jobs/JobRunner.php`.
   - Add `src/Jobs/Scheduler.php`.
   - Use Action Scheduler if `as_enqueue_async_action()` exists.
   - Fallback to WP-Cron single event.
   - Process bounded batches with max rows and max seconds.

4. Add job DTO helpers.
   - Add `src/DTO/JobRecord.php`.
   - Decode/encode JSON state safely.

Verification:

- Static syntax check for all PHP files.
- Static test verifies table names and indexes exist in TableMaker.
- Static test verifies scheduler has Action Scheduler and WP-Cron branches.

## Phase 3 - Package Streaming

1. Add package writer.
   - Add `src/Packages/PackageWriter.php`.
   - Write JSONL files incrementally.
   - Write manifest.
   - Zip package after export completion.

2. Add package reader.
   - Add `src/Packages/PackageReader.php`.
   - Validate zip entries.
   - Reject path traversal.
   - Stream JSONL records line by line.

3. Add manifest validator.
   - Add `src/Packages/ManifestValidator.php`.
   - Validate schema version, source fingerprint, entity counts, and required files.

Verification:

- Static tests for zip traversal rejection.
- Mapper-style tests using temp package fixture if local PHP can run without WordPress.

## Phase 4 - WooCommerce Export

1. Add Woo capability detector.
   - Add `src/Capabilities/CapabilityDetector.php`.
   - Detect WooCommerce and YS CART independently.

2. Add Woo customer exporter.
   - Add `src/Woo/CustomerExporter.php`.
   - Export WP users with Woo customer role and useful billing/shipping meta.
   - Include guest customer snapshots when exporting orders.

3. Add Woo order exporter.
   - Add `src/Woo/OrderExporter.php`.
   - Use `wc_get_orders()` with `return => ids`, `limit`, `paged`, and status filters.
   - Serialize order totals, payment, shipping, billing, shipping address, dates, line items, refunds.

4. Add Woo product exporter.
   - Add `src/Woo/ProductExporter.php`.
   - Use `wc_get_products()` and `wc_get_product()`.
   - Export simple products, variable parents, attributes, variations, images, categories, prices, stock, dimensions.

Verification:

- Static test confirms order exporter uses `wc_get_orders`.
- Static test confirms no direct Woo order table SQL.
- Static test confirms exporters are guarded by function/class checks.

## Phase 5 - YS CART Import Mappers

1. Add customer mapper/importer.
   - Add `src/YsCart/CustomerMapper.php`.
   - Add `src/YsCart/CustomerImporter.php`.
   - Same-site maps source user ID when safe.
   - Cross-site matches by email.
   - Missing users can be auto-created with generated password and `_ys_wc_imported_user` meta.
   - Use YS CART customer conversion when available.

2. Add product mapper/importer.
   - Add `src/YsCart/ProductMapper.php`.
   - Add `src/YsCart/ProductImporter.php`.
   - Match by source map, SKU, then slug.
   - Create/update simple and variable products.
   - Create/update attributes and variants.

3. Add order mapper/importer.
   - Add `src/YsCart/OrderMapper.php`.
   - Add `src/YsCart/OrderImporter.php`.
   - Map statuses.
   - Map billing/shipping addresses.
   - Map payment/shipping totals.
   - Create/find placeholder product for unbound order items.
   - Store original Woo item metadata.

Verification:

- Static tests for status mapping.
- Static tests for customer missing user behavior.
- Static tests for placeholder product path.

## Phase 6 - REST API

1. Add REST controller.
   - Add `src/Rest/RestController.php`.
   - Namespace: `ys-cart-wc-import/v1`.
   - Register endpoints from design spec.

2. Add permission helper.
   - Add `src/Rest/Permission.php`.
   - Prefer YS CART admin auth when available.
   - Fallback to `manage_options`.

3. Add upload/download handlers.
   - Add `src/Rest/PackageController.php`.
   - Verify nonce/capability.
   - Store packages in uploads subdirectory.

4. Add job controller.
   - Add `src/Rest/JobController.php`.
   - Create export/import/direct jobs.
   - Run next batch.
   - Return status and errors.

Verification:

- Static test confirms every `register_rest_route` has `permission_callback`.
- Static test confirms no `admin-ajax.php` usage.

## Phase 7 - Admin UI

1. Add admin page.
   - Add `src/Admin/AdminPage.php`.
   - Add `templates/admin/app.php`.

2. Add assets.
   - Add `assets/js/admin.js`.
   - Add `assets/css/admin.css`.
   - UI calls REST only.

3. UI sections.
   - Capabilities summary.
   - Woo export form.
   - YS import package upload.
   - Direct transfer form.
   - Jobs table.
   - Missing users and mapping preview.

Verification:

- Static grep confirms no `ajaxurl`, no `admin-ajax.php`.
- Browser/manual check on local admin when environment is available.

## Phase 8 - Tests and Regression Guardrails

1. Add static regression tests.
   - `tests/static/no-admin-ajax.php`
   - `tests/static/rest-permissions.php`
   - `tests/static/woo-order-query.php`
   - `tests/static/plugin-boot-guards.php`

2. Add mapper tests where WordPress bootstrap is not required.
   - `tests/unit/status-mapper.php`
   - `tests/unit/package-validator.php`

3. Add test runner.
   - `tests/run-static.php`

Verification:

- Run all static/unit tests.
- Run PHP syntax check across plugin.

## Phase 9 - Manual Integration

1. Package and install on dev-checkout source site.
2. Export a small sample:
   - 5 customers
   - 5 orders
   - 5 products including one variable product if available
3. Install on YS CART target dev site.
4. Upload package and preview.
5. Import customers, products, orders.
6. Verify:
   - customers created/mapped
   - orders visible with status, totals, payment/shipping, addresses
   - products and variants present
   - no `admin-ajax.php` requests
   - job can resume after interruption

Manual integration may require environment credentials/target URL confirmation before writing to remote sites.

