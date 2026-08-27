# YS CART WC 匯入

獨立 WordPress 外掛，用於將 WooCommerce 客戶、商品與訂單匯出成可搬移套件，並匯入到 YS CART。

## 使用模式

- WooCommerce 匯出：來源站只要啟用 WooCommerce 即可，該站不需要安裝 YS CART。
- YS CART 匯入：目標站只要啟用 YS CART 即可，該站不需要安裝 WooCommerce。
- 同站直接移轉：保留給 WooCommerce 與 YS CART 都啟用的網站使用。

## 目前範圍

- 匯出 WooCommerce 客戶、商品與訂單為 JSONL-based ZIP 套件。
- 匯入客戶、商品、多規格商品、變體與訂單到 YS CART。
- 一般商品引擎支援簡單商品、多規格商品、屬性與變體。
- 訂閱商品不放在一般商品引擎內，後續以獨立可選引擎處理。
- 移轉工作透過 REST 小批次執行，可續跑並降低單次請求壓力。
- 可使用 Action Scheduler；未安裝時使用 WP-Cron 作為備援。
- 匯入/匯出流程不依賴 WordPress admin-ajax.php。
- 提供匯入前 SQL 備份，可本地保留、下載、刪除，並以確認碼執行還原。
- 內建 YS Plugin Hub Client，可透過 YS Hub 安裝與更新。

## 重要對應規則

- WooCommerce 訂單使用 `wc_get_orders()` 讀取，以相容 HPOS。
- 跨站匯入時若找不到使用者，會建立隨機密碼使用者並標記 `_ys_wc_imported_user = 1`。
- Woo 訂單品項不強制綁定真實商品；若找不到商品 map，會建立隱藏 placeholder 商品 `WooCommerce Imported Item`。
- Woo 訂單物流行保留物流名稱為 `shipping_provider`；不寫入 Woo `method_id` 到 YS CART `shipping_method_id`，避免誤對應。
- 商品對應順序為來源 map、SKU、slug。
- 多規格商品會建立 YS CART 變體商品與變體資料列。

## 後台位置

主要入口：

`YS Plugin > YS CART WC 匯入`

若 YS Plugin menu 尚未建立，備援位置為：

`工具 > YS CART WC 匯入`

管理介面使用 REST 路由。YS CART current Core 會透過共用 admin registrar 掛載在：

`/wp-json/ys-ecommerce-headless/v1/admin/woocommerce-import`

未載入 YS CART 或 Core 尚未提供 admin registrar 時，才使用相容 fallback：

`/wp-json/ys-cart-wc-import/v1`

兩者使用相同的 endpoint suffix、nonce、權限與 response contract；每個 request
只會註冊其中一套 routes。

## SQL 備份

後台可建立目前 WordPress 資料表的 SQL 備份。備份檔會存放在 uploads 內的外掛私有目錄：

`wp-content/uploads/ys-cart-wc-import/backups`

備份列表支援下載、刪除與還原。還原會覆蓋目前資料表資料，必須輸入 `RESTORE` 才會執行。

## 上架與更新

外掛包含 `vendor/yangsheep/ys-plugin-hub-client`，並於 `plugins_loaded` priority `5` 註冊 slug：

`ys-cart-woocommerce-import`

發布套件需包含已追蹤的 Hub Client runtime 檔案，並排除本機建置產物、log、暫存檔與產生的 zip 檔。

## 文件

- 使用者匯入流程：`docs/user-import-flow.md`
- AI/MCP 協助合約草稿：`docs/ai-mcp-skill-contract.md`
- 預留 AI skill 草稿：`docs/mcp-skills/ys-cart-woocommerce-import-assistant/SKILL.md`

## 驗證

```bash
php tests/run-static.php
node --check assets/js/admin.js
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```
