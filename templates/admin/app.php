<?php
defined('ABSPATH') || exit;

echo '<div class="wrap">';
echo '<h1>' . esc_html__('YS CART WC 匯入', 'ys-cart-woocommerce-import') . '</h1>';
?>
<div class="ys-cwci-admin">
    <div id="ys-cwci-app" class="ys-cwci-shell">
        <div class="ys-cwci-status" role="status" aria-live="polite" data-ys-cwci-status>
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
            <span data-ys-cwci-status-text><?php echo esc_html__('正在讀取目前狀態', 'ys-cart-woocommerce-import'); ?></span>
        </div>

        <section class="ysca-card ys-cwci-panel ys-cwci-panel--compact">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('目前網站', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('移轉狀態', 'ys-cart-woocommerce-import'); ?></h2>
                </div>
                <button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm" data-ys-cwci-refresh title="<?php echo esc_attr__('重新整理狀態與工作', 'ys-cart-woocommerce-import'); ?>">
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

        <section class="ysca-card ys-cwci-panel">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('步驟 1', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('從 WooCommerce 匯出', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('此站不需要安裝 YS CART，也可以匯出 ZIP 套件給另一個網站匯入。', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <div class="ys-cwci-actions">
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="customers" title="<?php echo esc_attr__('匯出 WooCommerce 客戶使用者與帳單欄位。', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                    <?php echo esc_html__('匯出客戶', 'ys-cart-woocommerce-import'); ?>
                </button>
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="products" title="<?php echo esc_attr__('匯出簡單商品與多規格商品；訂閱商品保留給獨立引擎處理。', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-products" aria-hidden="true"></span>
                    <?php echo esc_html__('匯出商品', 'ys-cart-woocommerce-import'); ?>
                </button>
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="orders" title="<?php echo esc_attr__('匯出訂單總額、付款方式、物流名稱、地址與品項。', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    <?php echo esc_html__('匯出訂單', 'ys-cart-woocommerce-import'); ?>
                </button>
            </div>
        </section>

        <section class="ysca-card ys-cwci-panel">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('步驟 2', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('匯入到 YS CART', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('建議順序：先客戶、再商品、最後訂單。', 'ys-cart-woocommerce-import'); ?></p>
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
                <button type="submit" class="ysca-btn ysca-btn--primary">
                    <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                    <?php echo esc_html__('上傳並匯入', 'ys-cart-woocommerce-import'); ?>
                </button>
            </form>
            <div class="ys-cwci-result" data-ys-cwci-upload-result hidden></div>
        </section>

        <section class="ysca-card ys-cwci-panel">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('佇列', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('工作狀態', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('可手動一次執行一批，也可讓瀏覽器用 REST 小批次自動續跑。', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <div data-ys-cwci-jobs class="ys-cwci-jobs"></div>
        </section>
    </div>
</div>
<?php echo '</div>'; ?>
