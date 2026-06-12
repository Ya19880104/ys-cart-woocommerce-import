# Changelog

All notable changes to **YS CART WC 匯入** (`ys-cart-woocommerce-import`) are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project
uses semantic-ish `MAJOR.MINOR.PATCH` versioning while pre-1.0.

## [0.5.1] - 2026-06-12 — URL & slug migration guidance

### Added

- **「網址與商品代稱（slug）」搬家指引卡** on the admin page (server-rendered, no JS
  dependency). It explains that imports keep Woo slugs/SKUs verbatim, shows the
  resulting YS CART product URL base, and adapts to the live environment:
  - When **WooCommerce is still active**: warns that YS CART's `?product=` query
    parameter is hijacked by the WooCommerce product post type (old product page
    or 404), with a stronger warning when the YS CART product URL base collides
    with WooCommerce's product permalink base (YS rules win → every Woo product
    URL 404s). Includes a shortcut to the plugins page to deactivate WooCommerce
    after the migration is verified.
  - When **YS CART is present**: explains how to keep the original
    `/product/{slug}/` URLs after deactivating WooCommerce via 商店設定 → 功能模組
    → 商品網址前綴 (requires YS CART 2.52.29+), with a shortcut button. Detects
    the configurable base via `YSShopRoutingBootstrap::route_base()` when
    available and falls back to `shop` on older cores.
  - Always: reminder that category/tag/archive URLs are not auto-redirected
    (plan 301s separately).

## [0.5.0] - 2026-06-12 — Stability & UI hardening

Pre-sale review pass (priority: security > performance > function). No data-model
or REST-contract breaking changes; importing an existing 0.4.x package still works.

### Fixed — data integrity (Critical/High)

- **C1 — Per-record DB transactions.** Order import (order + items + map + source)
  and product/variant import (product + attributes + map, variant + map) are now
  wrapped in a single `START TRANSACTION` / `COMMIT` / `ROLLBACK` each
  (`src/Database/Transaction.php`). Previously a mid-record failure (e.g. item 3 of
  5) left the order row + partial items persisted with **no map/source row**, so a
  re-run created a **duplicate order**. Now any failure rolls the whole record back
  and a re-run cleanly creates it exactly once. Mirrors the core
  `YSProduct::duplicate` transaction pattern; safe against nesting because core
  `create`/`update`/`add_item` do not open their own transactions.
- **H3 — Variant/attribute idempotency.** The variant insert + map upsert are now
  atomic, closing the window where an insert succeeded but the map write failed,
  causing a re-run to insert a duplicate variant.
- **H1 — Product/variant images are sideloaded into the target media library.**
  New `src/YsCart/MediaSideloader.php` downloads each source image URL into the
  destination site, dedupes by URL hash via the `maps` table, and rewrites
  `image_url` / `gallery_urls` to local URLs. Previously the source-site URLs were
  stored verbatim, so every product image 404'd once the old WooCommerce site was
  decommissioned (the normal end of a migration). Best-effort: a failed download
  keeps the original URL and never aborts the record; runs inside no DB
  transaction (network I/O happens before the transaction). Can be disabled with
  the import option `sideload_images=false` (e.g. to deliberately keep hot-links).

### Fixed — validation

- **H2 — `entity` allowlist.** `createExportJob` / `createImportJob` now reject any
  entity other than `customers` / `products` / `orders` with a `400` `WP_Error`
  instead of silently creating a no-op job that self-completes.

### Changed — UI

- **Conditional YS CART chrome.** When the destination site has YS CART active,
  the admin page now renders inside the core `YSAdminApp` takeover shell (sidebar +
  top bar + `.ysca-*` design tokens) so it looks native in the buyer's YS CART
  admin, and the bespoke palette is harmonized to the core tokens. On an
  export-only site without YS CART it keeps the existing standalone `.wrap` shell —
  the plugin still has **no hard dependency** on YS CART.
- **M1 — Accurate progress bar** for single-stage imports (customers / orders):
  `total_count` is now seeded from the package manifest's entity count on the first
  batch, so the progress bar shows a real percentage instead of a placeholder.

### Notes

- Product import remains two-stage (products then variants); its progress bar still
  uses the approximate fallback rather than a single denominator.
- The legacy `direct` job type endpoint remains unimplemented (dispatch only handles
  `export` / `import`); slated for a future release or removal.
