<?php
defined('ABSPATH') || exit;
?>
<div class="wrap ys-cwci-admin">
    <h1><?php echo esc_html__('YS CART WooCommerce Import', 'ys-cart-woocommerce-import'); ?></h1>
    <div id="ys-cwci-app" class="ys-cwci-shell">
        <section class="ys-cwci-panel">
            <h2><?php echo esc_html__('Capabilities', 'ys-cart-woocommerce-import'); ?></h2>
            <pre data-ys-cwci-capabilities><?php echo esc_html__('Loading...', 'ys-cart-woocommerce-import'); ?></pre>
        </section>

        <section class="ys-cwci-panel">
            <h2><?php echo esc_html__('Export from WooCommerce', 'ys-cart-woocommerce-import'); ?></h2>
            <div class="ys-cwci-actions">
                <button type="button" class="button button-primary" data-ys-cwci-export="customers"><?php echo esc_html__('Export Customers', 'ys-cart-woocommerce-import'); ?></button>
                <button type="button" class="button button-primary" data-ys-cwci-export="products"><?php echo esc_html__('Export Products', 'ys-cart-woocommerce-import'); ?></button>
                <button type="button" class="button button-primary" data-ys-cwci-export="orders"><?php echo esc_html__('Export Orders', 'ys-cart-woocommerce-import'); ?></button>
            </div>
        </section>

        <section class="ys-cwci-panel">
            <h2><?php echo esc_html__('Import to YS CART', 'ys-cart-woocommerce-import'); ?></h2>
            <form data-ys-cwci-upload>
                <input type="file" name="package" accept=".zip" required>
                <select name="entity">
                    <option value="customers"><?php echo esc_html__('Customers', 'ys-cart-woocommerce-import'); ?></option>
                    <option value="products"><?php echo esc_html__('Products', 'ys-cart-woocommerce-import'); ?></option>
                    <option value="orders"><?php echo esc_html__('Orders', 'ys-cart-woocommerce-import'); ?></option>
                </select>
                <button type="submit" class="button button-primary"><?php echo esc_html__('Upload and Import', 'ys-cart-woocommerce-import'); ?></button>
            </form>
            <pre data-ys-cwci-upload-result></pre>
        </section>

        <section class="ys-cwci-panel">
            <h2><?php echo esc_html__('Jobs', 'ys-cart-woocommerce-import'); ?></h2>
            <button type="button" class="button" data-ys-cwci-refresh><?php echo esc_html__('Refresh', 'ys-cart-woocommerce-import'); ?></button>
            <div data-ys-cwci-jobs class="ys-cwci-jobs"></div>
        </section>
    </div>
</div>
