<?php
defined('ABSPATH') || exit;

// $ys_cwci_chrome 由 AdminPage::render() 設定：true = 已包在 YS CART 的 YSAdminApp
// takeover shell 內（不需自帶 .wrap，標題改由 page-head 顯示）；false = 獨立站 .wrap。
$ys_cwci_chrome = !empty($ys_cwci_chrome);

if (!$ys_cwci_chrome) {
    echo '<div class="wrap ys-cwci-wrap">';
}
?>
<div class="ys-cwci-admin<?php echo $ys_cwci_chrome ? ' ys-cwci-admin--ysca' : ''; ?>">
    <div id="ys-cwci-app" class="ys-cwci-shell">
        <header class="ys-cwci-hero">
            <div>
                <p class="ys-cwci-eyebrow"><?php echo esc_html__('YS Plugin', 'ys-cart-woocommerce-import'); ?></p>
                <h1><?php echo esc_html__('YS CART WC 匯入', 'ys-cart-woocommerce-import'); ?></h1>
                <p><?php echo esc_html__('將 WooCommerce 客戶、商品與訂單匯出成套件，並以可續跑批次匯入 YS CART。', 'ys-cart-woocommerce-import'); ?></p>
            </div>
            <div class="ys-cwci-status" role="status" aria-live="polite" data-ys-cwci-status>
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                <span data-ys-cwci-status-text><?php echo esc_html__('正在讀取目前狀態', 'ys-cart-woocommerce-import'); ?></span>
            </div>
        </header>

        <section class="ys-cwci-card">
            <div class="ys-cwci-card__head">
                <div>
                    <p class="ys-cwci-kicker"><?php echo esc_html__('目前網站', 'ys-cart-woocommerce-import'); ?></p>
                    <h2><?php echo esc_html__('移轉能力', 'ys-cart-woocommerce-import'); ?></h2>
                </div>
                <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-ys-cwci-refresh>
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php echo esc_html__('重新整理', 'ys-cart-woocommerce-import'); ?>
                </button>
            </div>
            <div class="ys-cwci-capabilities" data-ys-cwci-capabilities>
                <div class="ys-cwci-skeleton"></div>
                <div class="ys-cwci-skeleton"></div>
                <div class="ys-cwci-skeleton"></div>
            </div>
        </section>

        <?php
        // v0.5.1：網址與 slug 移轉指引（server-side 偵測、無 JS 依賴）。
        $ys_cwci_woo_active = class_exists('WooCommerce');
        $ys_cwci_has_yscart = class_exists('\YangSheep\Ecommerce\YSEcommerce')
            || defined('YS_ECOMMERCE_TABLE_PREFIX');
        $ys_cwci_ys_base = 'shop';
        if (
            class_exists('\YangSheep\Ecommerce\Bootstrap\YSShopRoutingBootstrap')
            && method_exists('\YangSheep\Ecommerce\Bootstrap\YSShopRoutingBootstrap', 'route_base')
        ) {
            // YS CART >= 2.52.29 才有可設定前綴；舊版固定 shop。
            $ys_cwci_ys_base = (string) \YangSheep\Ecommerce\Bootstrap\YSShopRoutingBootstrap::route_base();
        }
        $ys_cwci_woo_base = 'product';
        if ($ys_cwci_woo_active) {
            $ys_cwci_woo_permalinks = (array) get_option('woocommerce_permalinks', []);
            $ys_cwci_woo_base = trim((string) ($ys_cwci_woo_permalinks['product_base'] ?? 'product'), '/');
            if ($ys_cwci_woo_base === '') {
                $ys_cwci_woo_base = 'product';
            }
        }
        ?>
        <section class="ys-cwci-card<?php echo $ys_cwci_woo_active && $ys_cwci_has_yscart ? ' ys-cwci-card--accent' : ''; ?>">
            <div class="ys-cwci-card__head">
                <div>
                    <p class="ys-cwci-kicker"><?php echo esc_html__('搬家指引', 'ys-cart-woocommerce-import'); ?></p>
                    <h2><?php echo esc_html__('網址與商品代稱（slug）', 'ys-cart-woocommerce-import'); ?></h2>
                </div>
            </div>
            <ul class="ys-cwci-guidance">
                <li>
                    <?php echo esc_html__('匯入會原樣保留 WooCommerce 的商品代稱（slug）與 SKU，商品在 YS CART 的網址為 /', 'ys-cart-woocommerce-import'); ?><code><?php echo esc_html($ys_cwci_ys_base); ?></code><?php echo esc_html__('/{商品代稱}/。', 'ys-cart-woocommerce-import'); ?>
                </li>
                <?php if ($ys_cwci_woo_active) : ?>
                    <li class="ys-cwci-guidance__warn">
                        <strong><?php echo esc_html__('WooCommerce 目前仍啟用：', 'ys-cart-woocommerce-import'); ?></strong>
                        <?php echo esc_html__('YS CART 商品連結的 ?product= 查詢參數會被 WooCommerce 商品文章類型攔截（開到舊商品或 404）。完成匯入並驗證資料後，請停用 WooCommerce。', 'ys-cart-woocommerce-import'); ?>
                        <?php if ($ys_cwci_has_yscart && $ys_cwci_ys_base === $ys_cwci_woo_base) : ?>
                            <strong><?php echo esc_html__('另外：YS CART 商品網址前綴目前與 WooCommerce 商品路徑相同，YS CART 規則優先，所有 WooCommerce 商品網址將 404。', 'ys-cart-woocommerce-import'); ?></strong>
                        <?php endif; ?>
                    </li>
                <?php endif; ?>
                <?php if ($ys_cwci_has_yscart) : ?>
                    <li>
                        <?php
                        printf(
                            /* translators: %s = WooCommerce product permalink base */
                            esc_html__('想沿用 WooCommerce 的 /%s/{商品代稱}/ 商品網址：停用 WooCommerce 後，到「商店設定 → 功能模組 → 商品網址前綴」改為相同字串並儲存即可（需 YS CART 2.52.29+）。', 'ys-cart-woocommerce-import'),
                            esc_html($ys_cwci_woo_base)
                        );
                        ?>
                    </li>
                <?php endif; ?>
                <li><?php echo esc_html__('提醒：舊分類、標籤與封存頁網址不會自動轉址，請依 SEO 需求另行規劃 301。', 'ys-cart-woocommerce-import'); ?></li>
            </ul>
            <div class="ys-cwci-actions">
                <?php if ($ys_cwci_has_yscart) : ?>
                    <a class="ys-cwci-btn ys-cwci-btn--ghost" href="<?php echo esc_url(admin_url('admin.php?page=ys-ec-settings&tab=shop')); ?>">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <?php echo esc_html__('商店設定（商品網址前綴）', 'ys-cart-woocommerce-import'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($ys_cwci_woo_active) : ?>
                    <a class="ys-cwci-btn ys-cwci-btn--ghost" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                        <span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
                        <?php echo esc_html__('前往外掛頁停用 WooCommerce', 'ys-cart-woocommerce-import'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <div class="ys-cwci-grid">
            <section class="ys-cwci-card">
                <div class="ys-cwci-card__head">
                    <div>
                        <p class="ys-cwci-kicker"><?php echo esc_html__('步驟 1', 'ys-cart-woocommerce-import'); ?></p>
                        <h2><?php echo esc_html__('從 WooCommerce 匯出', 'ys-cart-woocommerce-import'); ?></h2>
                        <p><?php echo esc_html__('來源站不需要安裝 YS CART，可單獨匯出 ZIP 套件。', 'ys-cart-woocommerce-import'); ?></p>
                    </div>
                </div>
                <div class="ys-cwci-actions">
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-ys-cwci-export="customers">
                        <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                        <?php echo esc_html__('匯出客戶', 'ys-cart-woocommerce-import'); ?>
                    </button>
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-ys-cwci-export="products">
                        <span class="dashicons dashicons-products" aria-hidden="true"></span>
                        <?php echo esc_html__('匯出商品', 'ys-cart-woocommerce-import'); ?>
                    </button>
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-ys-cwci-export="orders">
                        <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                        <?php echo esc_html__('匯出訂單', 'ys-cart-woocommerce-import'); ?>
                    </button>
                </div>
            </section>

            <section class="ys-cwci-card ys-cwci-card--accent">
                <div class="ys-cwci-card__head">
                    <div>
                        <p class="ys-cwci-kicker"><?php echo esc_html__('匯入前建議', 'ys-cart-woocommerce-import'); ?></p>
                        <h2><?php echo esc_html__('SQL 備份', 'ys-cart-woocommerce-import'); ?></h2>
                        <p><?php echo esc_html__('建立目前 WordPress 資料表 SQL 備份，可下載、本地保留、刪除，必要時可輸入確認碼還原。', 'ys-cart-woocommerce-import'); ?></p>
                    </div>
                </div>
                <div class="ys-cwci-actions">
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-ys-cwci-backup-create>
                        <span class="dashicons dashicons-database-export" aria-hidden="true"></span>
                        <?php echo esc_html__('建立 SQL 備份', 'ys-cart-woocommerce-import'); ?>
                    </button>
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-ys-cwci-backup-refresh>
                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                        <?php echo esc_html__('查看備份', 'ys-cart-woocommerce-import'); ?>
                    </button>
                </div>
                <div class="ys-cwci-backups" data-ys-cwci-backups></div>
            </section>

            <section class="ys-cwci-card">
                <div class="ys-cwci-card__head">
                    <div>
                        <p class="ys-cwci-kicker"><?php echo esc_html__('步驟 2', 'ys-cart-woocommerce-import'); ?></p>
                        <h2><?php echo esc_html__('匯入到 YS CART', 'ys-cart-woocommerce-import'); ?></h2>
                        <p><?php echo esc_html__('建議順序：先客戶、再商品、最後訂單。', 'ys-cart-woocommerce-import'); ?></p>
                    </div>
                </div>
                <form class="ys-cwci-import-form" data-ys-cwci-upload>
                    <label class="ys-cwci-field">
                        <span><?php echo esc_html__('匯入套件', 'ys-cart-woocommerce-import'); ?></span>
                        <input type="file" name="package" accept=".zip" required>
                    </label>
                    <label class="ys-cwci-field">
                        <span><?php echo esc_html__('資料類型', 'ys-cart-woocommerce-import'); ?></span>
                        <select name="entity">
                            <option value="customers"><?php echo esc_html__('客戶', 'ys-cart-woocommerce-import'); ?></option>
                            <option value="products"><?php echo esc_html__('商品', 'ys-cart-woocommerce-import'); ?></option>
                            <option value="orders"><?php echo esc_html__('訂單', 'ys-cart-woocommerce-import'); ?></option>
                        </select>
                    </label>
                    <button type="submit" class="ys-cwci-btn ys-cwci-btn--primary">
                        <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                        <?php echo esc_html__('上傳並匯入', 'ys-cart-woocommerce-import'); ?>
                    </button>
                </form>
                <div class="ys-cwci-result" data-ys-cwci-upload-result hidden></div>
            </section>
        </div>

        <section class="ys-cwci-card">
            <div class="ys-cwci-card__head">
                <div>
                    <p class="ys-cwci-kicker"><?php echo esc_html__('佇列', 'ys-cart-woocommerce-import'); ?></p>
                    <h2><?php echo esc_html__('工作狀態', 'ys-cart-woocommerce-import'); ?></h2>
                    <p><?php echo esc_html__('可手動一次執行一批，也可讓瀏覽器用 REST 小批次自動續跑。', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <div data-ys-cwci-jobs class="ys-cwci-jobs"></div>
        </section>
    </div>
</div>
<?php
if (!$ys_cwci_chrome) {
    echo '</div>';
}
?>
