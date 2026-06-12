<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

use Throwable;

/**
 * 單筆 entity 匯入的原子化包裹（v0.5.0 C1 修正）。
 *
 * 問題：原本 OrderImporter / ProductImporter 在「建立訂單 → 逐筆加品項 → 寫 map →
 * 寫 source 列」之間沒有交易。若中途（例：第 3 個品項）拋例外，訂單列與前 2 個品項
 * 已落地、但 map / source 從未寫入 → 下次重跑時 dedup 查無對應 → 產生「重複訂單」。
 *
 * 修法：把「建立 + 子列 + map + source」整段包在 MySQL/InnoDB 交易裡。任一步失敗即
 * ROLLBACK，整筆不落地、可安全重跑；成功才 COMMIT。
 *
 * 巢狀安全：核心 YSOrder::create/add_item、YSProduct::create/update 內部「不」開交易
 *（唯一用 START TRANSACTION 的是 YSProduct::duplicate，匯入流程不會呼叫），故此處外層
 * 包裹不會與內層 implicit-commit 衝突。
 *
 * 網路 I/O 不應放在交易內（圖片 sideload 等請在 run() 之前完成、只把解析後的資料帶進來），
 * 以免長時間持有列鎖。
 */
final class Transaction
{
    /**
     * 在單一交易內執行 $fn；成功 COMMIT 並回傳其結果，失敗 ROLLBACK 後原樣重拋。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function run(callable $fn)
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        try {
            $result = $fn();
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return $result;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            throw $e;
        }
    }
}
