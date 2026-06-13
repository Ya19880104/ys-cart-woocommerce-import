# Changelog

All notable changes to **YS CART WC 匯入** (`ys-cart-woocommerce-import`) are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project
uses semantic-ish `MAJOR.MINOR.PATCH` versioning while pre-1.0.

## [0.7.2] - 2026-06-13 — 整備清理（公開 repo 衛生 + 註解鎖定）

無執行期行為變更。本版為售前複查後的整備：

### Changed

- **整合測試移除內部 staging 站名**：`tests/integration/` 4 支煙霧測試的檔名與套件 ID
  前綴原帶一個內部測試站名。本 repo 為公開下載用，已改為中性命名（檔名去除站名前綴、
  套件 ID 改 `wci-…`），並更新一處靜態測試註解。純命名調整，測試邏輯與涵蓋範圍不變。

### Notes

- **覆蓋模式金額權威性（以註解鎖定，非行為變更）**：覆蓋既有訂單時，訂單表頭金額
  （`subtotal` / `total` / `shipping_fee` / `discount` …）一律採套件 `mapOrder` 的值
  —— 即來源站（Woo）結算後、已含運費／折扣／稅的權威金額，並非品項單純加總。核心
  `YSOrder::add_item` 為純 INSERT、不回算表頭，故重建品項不會改動已寫入金額；此行為與
  建立路徑一致，**刻意不依品項重算**（重算會丟失運費／折扣／稅且造成兩路徑不一致）。

## [0.7.1] - 2026-06-13 — 回歸獨立 UI

### Changed

- **移除 v0.5.0 的條件式 YS CART 外框（YSAdminApp）包裹**：匯入工具是
  **獨立外掛** — 可在未啟用 YS CART 的網站使用、不屬於 YS CART 的後台選單，
  因此一律以自身獨立介面渲染，不再於 YS CART 站上載入其側欄／頂列外框。
  配合 YS CART core 2.52.31 將本頁列入 WP-native 排除清單（不載入 YS CSS、
  不套 takeover 樣式），新舊核心皆顯示一致的獨立 UI。

## [0.7.0] - 2026-06-13 — 中斷繼續、全部重試、跨站整合、訂單狀態選擇

### Added

- **中斷繼續**：精靈進度持久化於瀏覽器（步驟、結果、選項），開機自動偵測
  「上次中斷的進度＋未完成的精靈工作」並顯示繼續橫幅 —「繼續上次進度」會把
  未完成的批次工作接續跑完（同站匯出完成後自動接續匯入），「放棄並重新開始」
  會取消未完成工作並清除進度。工作本身原本就以 cursor 續跑，瀏覽器中斷不會
  遺失伺服器端進度。
- **全部重試（覆蓋／忽略已匯入）**：完成頁新增「全部重試」，可選擇
  - 忽略已匯入（`mode=skip`）：既有客戶／商品／訂單完全不更動，只補對應；
  - 覆蓋已匯入（`mode=overwrite`）：以套件資料更新既有資料 — 訂單會更新全部
    欄位（保留 YS 單號與原建單時間）並依套件重建品項，全程包在交易內。
  每個搬移步驟與手動模式上傳表單也都有同樣的覆蓋／忽略選項（預設忽略）。
- **跨站整合匯入**：明確支援依序匯入多個不同來源站的套件 — 訂單以
  `ys_ec_order_sources` 的「來源站指紋＋來源單號」辨識，不同站的同號訂單
  不會互相覆蓋（精靈與手動模式皆加上說明）。
- **訂單狀態選擇（預設全選）＋未對應詢問**：訂單匯入前自動掃描套件
  （新端點 `POST /packages/order-statuses`，串流統計各狀態筆數），列出
  狀態清單供勾選（預設全選）；WooCommerce 自訂狀態等**無法自動對應**的
  狀態會標示 ⚠ 並要求選擇要對應到哪個 YS CART 狀態（自訂對應經白名單驗證）。
  被取消勾選的狀態計為「狀態略過」，不算錯誤。精靈（同站／上傳）與手動模式
  上傳皆支援。

### Changed

- 精靈／手動建立的匯入工作現在一律明確帶 `mode`（預設 `skip`＝忽略已匯入）。
  直接呼叫 REST 且未帶 `mode` 的舊整合不受影響（維持原行為：訂單略過、商品更新）。

## [0.6.0] - 2026-06-12 — 精靈模式 & 手動模式

### Added

- **雙操作模式**，頁面頂部一鍵切換（選擇記憶於瀏覽器）：
  - **精靈模式（預設）** — 步驟式引導。依環境自動選擇流程：
    - **同站搬家**（Woo + YS CART 同站）：環境檢查 → SQL 備份（建議、可略過）→
      依「客戶 → 商品 → 訂單」順序逐項匯出＋直接匯入，每步即時進度條與
      成功/錯誤計數，完成後總結 + WooCommerce 停用提醒。已搬過的資料自動
      去重，可重複執行。
    - **匯出精靈**（來源站只有 Woo）：逐項打包 ZIP，完成頁附下載連結與
      下一步指引。
    - **匯入精靈**（目標站只有 YS CART）：備份 → 逐項上傳套件並匯入。
  - **手動模式** — 既有工程化介面原封保留：移轉能力、搬家指引、匯出/上傳
    匯入、**工作紀錄（LOG）**、錯誤檢視、**SQL 備份／還原（回溯）**。
  - 精靈執行的工作同樣寫入工作紀錄，隨時可切到手動模式查 LOG 或還原。
- 精靈同站匯入所需的 source fingerprint 由 PHP 端預先計算下發
  （`hash('sha256', home_url())`），避免 JS 端因子目錄/尾斜線差異算錯。

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
