<?php
defined('ABSPATH') || exit;

$ysAdminApp = '\YangSheep\Ecommerce\Admin\YSAdminApp';
$useYsShell = class_exists($ysAdminApp);

if ($useYsShell) {
    $ysAdminApp::open(
        __('WooCommerce Import', 'ys-cart-woocommerce-import'),
        __('電商系統 / WooCommerce Import', 'ys-cart-woocommerce-import')
    );
} else {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('YS CART WooCommerce Import', 'ys-cart-woocommerce-import') . '</h1>';
}
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
                    <p class="ysca-card__kicker"><?php echo esc_html__('Current Site', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('Migration Status', 'ys-cart-woocommerce-import'); ?></h2>
                </div>
                <button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm" data-ys-cwci-refresh title="<?php echo esc_attr__('Refresh status and jobs', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php echo esc_html__('Refresh', 'ys-cart-woocommerce-import'); ?>
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
                    <p class="ysca-card__kicker"><?php echo esc_html__('Step 1', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('Export from WooCommerce', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('Export packages can be imported on another site without YS CART installed here.', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <div class="ys-cwci-actions">
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="customers" title="<?php echo esc_attr__('Export WooCommerce customer users and billing profile fields.', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                    <?php echo esc_html__('Customers', 'ys-cart-woocommerce-import'); ?>
                </button>
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="products" title="<?php echo esc_attr__('Export simple and variable products. Subscription products are reserved for a separate engine.', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-products" aria-hidden="true"></span>
                    <?php echo esc_html__('Products', 'ys-cart-woocommerce-import'); ?>
                </button>
                <button type="button" class="ysca-btn ysca-btn--primary" data-ys-cwci-export="orders" title="<?php echo esc_attr__('Export orders with totals, payment, shipping name, addresses, and line items.', 'ys-cart-woocommerce-import'); ?>">
                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    <?php echo esc_html__('Orders', 'ys-cart-woocommerce-import'); ?>
                </button>
            </div>
        </section>

        <section class="ysca-card ys-cwci-panel">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('Step 2', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('Import to YS CART', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('Recommended order: customers, products, then orders.', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <form class="ys-cwci-import-form" data-ys-cwci-upload>
                <label class="ys-cwci-field">
                    <span><?php echo esc_html__('Package', 'ys-cart-woocommerce-import'); ?></span>
                    <input type="file" name="package" accept=".zip" required>
                </label>
                <label class="ys-cwci-field">
                    <span><?php echo esc_html__('Entity', 'ys-cart-woocommerce-import'); ?></span>
                    <select name="entity">
                        <option value="customers"><?php echo esc_html__('Customers', 'ys-cart-woocommerce-import'); ?></option>
                        <option value="products"><?php echo esc_html__('Products', 'ys-cart-woocommerce-import'); ?></option>
                        <option value="orders"><?php echo esc_html__('Orders', 'ys-cart-woocommerce-import'); ?></option>
                    </select>
                </label>
                <button type="submit" class="ysca-btn ysca-btn--primary">
                    <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                    <?php echo esc_html__('Upload and Import', 'ys-cart-woocommerce-import'); ?>
                </button>
            </form>
            <div class="ys-cwci-result" data-ys-cwci-upload-result hidden></div>
        </section>

        <section class="ysca-card ys-cwci-panel">
            <div class="ysca-card__head">
                <div>
                    <p class="ysca-card__kicker"><?php echo esc_html__('Queue', 'ys-cart-woocommerce-import'); ?></p>
                    <h2 class="ysca-card__title"><?php echo esc_html__('Jobs', 'ys-cart-woocommerce-import'); ?></h2>
                    <p class="ysca-card__sub"><?php echo esc_html__('Run one batch at a time or let the browser continue bounded REST steps.', 'ys-cart-woocommerce-import'); ?></p>
                </div>
            </div>
            <div data-ys-cwci-jobs class="ys-cwci-jobs"></div>
        </section>
    </div>
</div>
<?php
if ($useYsShell) {
    $ysAdminApp::close();
} else {
    echo '</div>';
}
?>
