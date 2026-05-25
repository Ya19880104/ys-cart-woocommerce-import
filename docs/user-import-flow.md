# YS CART WooCommerce 匯入工具使用者流程

更新日期：2026-05-22

這份文件說明一般管理員如何用外掛介面完成 WooCommerce 匯出套件匯入到 YS CART。外掛維持獨立模式：只有 WooCommerce 的網站可以只做匯出；只有 YS CART 的網站可以只做匯入；兩者都有時才可做同站測試或直接移轉。

## 進入頁面

在 WordPress 後台開啟：

`YS Plugin > WooCommerce 匯入`

頁面會先顯示「目前網站 / 移轉狀態」：

- `woocommerce: true` 代表此站可匯出 WooCommerce 資料。
- `ys_cart: true` 代表此站可匯入 YS CART 資料。
- `can_export` / `can_import` / `can_direct_transfer` 會依目前網站外掛狀態自動判斷。

## 匯出 WooCommerce 套件

在來源網站操作：

1. 點選「匯出客戶」、「匯出商品」或「匯出訂單」。
2. 下方「工作狀態」會新增一筆 `export/<entity>` job。
3. 介面會自動執行批次；需要手動重試時，可點該 job 的「執行下一批」。
4. 狀態變成 `completed` 後，點「下載」下載 ZIP 套件。

套件內容是 JSONL-based ZIP，包含 manifest 與對應 entity 檔案。訂單、商品、客戶可分開匯出，方便在不同網站分階段匯入。

## 匯入到 YS CART

在目標網站操作：

1. 開啟 `YS Plugin > WooCommerce 匯入`。
2. 在「匯入到 YS CART」區塊選擇 ZIP 套件。
3. 選擇資料類型：「客戶」、「商品」或「訂單」。
4. 點「上傳並匯入」。
5. 上傳成功後會顯示 manifest，並在「工作狀態」新增 `import/<entity>` job。
6. 介面會自動執行批次；需要手動重試時，可點「執行下一批」。
7. 若狀態還不是 `completed`，繼續使用「自動執行」或「執行下一批」，直到完成。

目前 UI 使用 REST 小批次執行並支援自動續跑，避免低階主機因單次匯入過大而 timeout；同時保留手動重試能力。

## 匯入順序建議

跨站移轉建議順序：

1. `customers` 客戶
2. `products` 商品
3. `orders` 訂單

原因：

- 客戶先匯入可讓訂單有機會對應到既有或新建使用者。
- 商品先匯入可讓訂單品項盡可能對應到商品 map。
- 訂單最後匯入，若找不到商品 map，外掛會建立隱藏 placeholder 商品，確保訂單查詢資訊仍保留。

## 客戶與使用者注意事項

同站移轉時，使用者通常已存在，外掛會盡量使用既有使用者。

跨站匯入時：

- 若 email 對應不到既有使用者，外掛會建立新 WordPress user，並用隨機密碼。
- 匯入產生的使用者會標記 `_ys_wc_imported_user = 1`。
- 若 email 對應到管理員，外掛不會把該管理員轉成 YS CART customer role，避免後台權限與 redirect 問題。

## 訂單匯入注意事項

訂單匯入以查詢與留存資料為主：

- 會保留 WooCommerce 訂單狀態、金額、付款方式、運輸方式、地址、品項摘要。
- 地址會用 YS CART 目前欄位格式合併。
- 不要求品項一定要綁定真實商品。
- 找不到商品對應時，會使用隱藏 placeholder 商品 `WooCommerce Imported Item`。
- 若新版 YS CART 提供 `ys_ec_order_sources` 來源訂單表，外掛會自動寫入 WooCommerce 舊訂單 ID 與舊訂單編號，用於防止重複匯入，並保留給 YS CART 的開關式查詢/顯示功能使用。

## 商品匯入注意事項

商品匯入會盡量保留：

- simple product
- variable product
- attributes
- variations
- SKU / slug 對應

商品 matching 順序：

1. 來源 map
2. SKU
3. slug

## 使用者回歸測試紀錄

測試站：`dev-checkout.wppro.cloud`

測試時間：2026-05-22

測試方式：

1. 用外掛 REST job 產生小型 Woo orders ZIP，限制 2 筆，避免對 10k+ Woo orders 造成壓力。
2. 用瀏覽器登入 WordPress 後台。
3. 開啟 `YS Plugin > WooCommerce 匯入`。
4. 在 UI 上傳 orders ZIP。
5. UI 建立 `import/orders` job。
6. 點「執行下一批」或「自動執行」完成匯入。

結果：

```json
{
  "capabilities": {
    "woocommerce": true,
    "ys_cart": true,
    "can_export": true,
    "can_import": true,
    "can_direct_transfer": true
  },
  "job": "#30 import/orders completed 2/0",
  "processed": 2,
  "success": 2,
  "error": 0,
  "rest_upload_status": 200,
  "rest_import_job_status": 200,
  "rest_run_next_status": 200,
  "browser_page_errors": 0
}
```

本次測試確認：使用者可以從 UI 上傳套件、建立 import job、手動執行批次並完成匯入。

## 常見問題

### 看不到匯入功能

確認目標網站有啟用 YS CART。此插件可單獨存在，但 `can_import` 只有在 YS CART 可用時才會是 `true`。

### 匯出網站沒有 YS CART

可以。匯出只需要 WooCommerce。

### 匯入網站沒有 WooCommerce

可以。匯入只需要 YS CART。

### 匯入中斷

重新進入頁面後，找到同一筆 job，再按「自動執行」或「執行下一批」。job cursor 會保存進度。

### 訂單商品沒有對上

訂單匯入不依賴商品一定存在。缺少商品 map 時會使用 placeholder 商品，訂單金額與查詢資訊仍保留。
