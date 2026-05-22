# YS CART WooCommerce Import Assistant

Use this skill when assisting a WordPress administrator with WooCommerce to YS CART migration through the `ys-cart-woocommerce-import` plugin.

This is a reserved skill draft for future MCP or AI assistant integration. It is documentation only; the plugin does not currently install an MCP server or expose this file as an executable tool.

## Scope

- Detect whether the current WordPress site can export WooCommerce data, import YS CART data, or both.
- Help the administrator create WooCommerce export packages for customers, products, and orders.
- Help the administrator upload a package and create YS CART import jobs.
- Run migration jobs in bounded batches.
- Read job status and job errors, then explain the next operational step.

## Boundaries

- Do not handle YS CART licensing. Licensing belongs to YS CART core.
- Do not require WooCommerce on an import-only site.
- Do not require YS CART on an export-only site.
- Do not call `admin-ajax.php` for migration work.
- Do not directly modify the database.
- Do not read arbitrary server files.
- Use WordPress REST routes with nonce and capability checks.

## Preferred Workflow

1. Call capabilities first.
2. If exporting, create a small smoke-test export job before creating a full export.
3. If importing, upload the package, validate the manifest, then create the import job.
4. Run jobs through `run-next` in small batches until the job reaches `completed`, `failed`, or `cancelled`.
5. If importing all entities, use this order: customers, products, orders.
6. If order products cannot be matched, keep the order searchable and rely on placeholder/manual mapping behavior instead of blocking the import.

## REST Contract

Base namespace:

`/wp-json/ys-cart-wc-import/v1`

Required routes:

- `GET /capabilities`
- `POST /export-jobs`
- `POST /packages/upload`
- `POST /import-jobs`
- `POST /jobs/{id}/run-next`
- `GET /jobs/{id}`
- `GET /jobs/{id}/errors`

## Suggested MCP Tools

### `detect_capabilities`

Returns plugin capabilities and the safe actions currently available.

### `create_export_job`

Creates a bounded WooCommerce export job.

Inputs:

```json
{
  "entity": "customers|products|orders",
  "batch_size": 50,
  "max_total": 0
}
```

### `upload_package`

Uploads a migration package and returns the parsed manifest and server-side file path.

### `create_import_job`

Creates a bounded YS CART import job from a validated package.

Inputs:

```json
{
  "entity": "customers|products|orders",
  "file_path": "/absolute/path/to/package.zip",
  "source_fingerprint": "source-site-hash",
  "batch_size": 50
}
```

### `run_job_until_idle`

Runs a job in bounded steps and returns final status, processed count, success count, and error count.

### `list_job_errors`

Returns job errors for administrator review.

## Administrator-Facing Guidance

- Explain that export and import can be done on different sites.
- Warn before importing orders if customers were not imported first.
- Warn before importing orders if products were not imported first.
- Treat order import as lookup-first migration: price, payment method, shipping method, address, status, and searchable order history are more important than perfect Woo product binding.
- Preserve operational safety by using small batches on weak hosting.
