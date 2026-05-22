# AI / MCP Skill Contract Draft

更新日期：2026-05-22

這份文件是未來讓 AI assistant 透過 MCP 或自動化工具協助 WooCommerce -> YS CART 移轉的預留規格。它不是目前必須安裝的 MCP server，也不是授權功能；只是外掛端提供穩定 REST contract 與操作邊界，讓未來 AI 能安全接上。

## 設計目標

- AI 先偵測能力，再決定可執行的工作。
- 匯出端不依賴 YS CART。
- 匯入端不依賴 WooCommerce。
- 所有大量工作都走 job + batch，不走 `admin-ajax.php`。
- AI 不直接改 DB，不直接讀寫任意檔案。
- AI 只透過 WordPress REST + nonce/capability 執行。

## 能力偵測

AI 或 MCP client 第一個呼叫：

`GET /wp-json/ys-cart-wc-import/v1/capabilities`

回應欄位：

```json
{
  "woocommerce": true,
  "ys_cart": true,
  "can_export": true,
  "can_import": true,
  "can_direct_transfer": true
}
```

行為規則：

- `can_export = true` 才顯示 Woo export 操作。
- `can_import = true` 才顯示 YS import 操作。
- `can_direct_transfer = true` 才能提供同站直接移轉建議。
- 若只有 WooCommerce，AI 只能協助匯出。
- 若只有 YS CART，AI 只能協助匯入。

## REST 工作流程

### 建立匯出 job

`POST /wp-json/ys-cart-wc-import/v1/export-jobs`

```json
{
  "entity": "orders",
  "options": {
    "package_id": "example-orders-20260522",
    "batch_size": 50,
    "max_total": 500
  }
}
```

`max_total` 是測試用限量參數。正式匯出可省略，但 AI 應先用小批次做 smoke test。

### 建立匯入 job

`POST /wp-json/ys-cart-wc-import/v1/import-jobs`

```json
{
  "entity": "orders",
  "options": {
    "file_path": "/absolute/path/to/package.zip",
    "source_fingerprint": "source-site-hash",
    "batch_size": 50
  }
}
```

一般瀏覽器 UI 應先呼叫 `/packages/upload`，取得安全存放後的 `file_path`，再建立 import job。

### 執行下一批

`POST /wp-json/ys-cart-wc-import/v1/jobs/{id}/run-next`

AI 應重複呼叫直到 job status 為：

- `completed`
- `failed`
- `cancelled`

### 查詢 job

`GET /wp-json/ys-cart-wc-import/v1/jobs/{id}`

### 查詢錯誤

`GET /wp-json/ys-cart-wc-import/v1/jobs/{id}/errors`

## 建議 AI Skill 行為

Skill 名稱建議：

`ys-cart-woocommerce-import-assistant`

預留草稿位置：

`docs/mcp-skills/ys-cart-woocommerce-import-assistant/SKILL.md`

使用時機：

- 使用者要從 WooCommerce 匯出 YS CART 移轉套件。
- 使用者要把 WooCommerce 套件匯入 YS CART。
- 使用者要做跨站移轉前檢查。
- 使用者要檢查 migration job 狀態或錯誤。

Skill 必須遵守：

- 先呼叫 capabilities。
- 先用小批次 smoke test，再建議大批量正式 job。
- 每次只操作一個 entity，避免不必要的主機壓力。
- 匯入順序預設：customers -> products -> orders。
- 對 orders 要提醒：不需要商品完全綁定，主要保留查詢資料、金額、付款、物流與狀態。
- 不處理 YS CART 授權；授權屬於 YS CART core 領域。

## MCP Server 預留工具

未來 MCP server 可以包裝以下工具：

### `detect_capabilities`

輸入：

```json
{}
```

輸出：capabilities endpoint 的 JSON。

### `create_export_job`

輸入：

```json
{
  "entity": "customers|products|orders",
  "batch_size": 50,
  "max_total": 0
}
```

輸出：job id。

### `upload_package`

輸入：

```json
{
  "file": "package.zip"
}
```

輸出：manifest + server-side file path。

### `create_import_job`

輸入：

```json
{
  "entity": "customers|products|orders",
  "file_path": "/absolute/path/to/package.zip",
  "source_fingerprint": "hash",
  "batch_size": 50
}
```

輸出：job id。

### `run_job_until_idle`

輸入：

```json
{
  "job_id": 123,
  "max_steps": 20
}
```

輸出：job final status、processed、success、error。

### `list_job_errors`

輸入：

```json
{
  "job_id": 123
}
```

輸出：錯誤列表。

## 安全邊界

- MCP client 必須用已登入管理員的 WordPress REST nonce，或由網站端提供正式應用密鑰方案。
- 不接受未授權 public import/export。
- 不提供任意路徑下載。
- ZIP upload 必須通過 manifest validation。
- AI 回覆中不應顯示絕對主機路徑、nonce、cookie、完整授權碼或其他 secret。

## 未來可加強項目

- 正式 MCP server metadata。
- Job progress streaming。
- UI 自動輪詢但保留手動 `Run Next`。
- package preview 顯示 entity counts、來源站 hash、WooCommerce version、風險提示。
- 匯入前 dry-run：顯示將建立/更新的 customers/products/orders 數量。
- 跨站 user mapping 報告：列出已存在、會新建、email 衝突的使用者統計。
