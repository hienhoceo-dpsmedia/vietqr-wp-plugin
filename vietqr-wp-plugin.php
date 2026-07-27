<?php
/**
 * Plugin Name: VietQR Generator
 * Plugin URI: https://dpsmedia.vn
 * Description: A simple VietQR generator plugin embedded via shortcode.
 * Version: 1.0.0
 * Author: DPS Media
 * Author URI: https://dpsmedia.vn
 * License: GPLv2 or later
 * Text Domain: vietqr-generator
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Register scripts and styles (but do not enqueue yet).
 */
function vietqr_register_assets() {
    // Register CSS
    wp_register_style(
        'vietqr-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        array(),
        '1.0.0'
    );

    // Register JS
    wp_register_script(
        'vietqr-script',
        plugin_dir_url(__FILE__) . 'assets/js/script.js',
        array('jquery'),
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'vietqr_register_assets');

/**
 * Render the QR Generator Shortcode.
 * 
 * Usage: [vietqr_generator]
 */
function vietqr_generator_shortcode() {
    // Conditional Loading: Enqueue assets only when shortcode is used
    wp_enqueue_style('vietqr-style');
    wp_enqueue_script('vietqr-script');

    ob_start();
    ?>
    <div id="vietqr-embed">
        <div class="vietqr-container">
            <div class="vietqr-form">
                <h3 class="vietqr-title"><?php esc_html_e('Thiết lập mã VietQR', 'vietqr-generator'); ?></h3>
                <form id="vietqr-generator-form" autocomplete="off">
                    <div class="form-group">
                        <label class="label"><?php esc_html_e('Ngân hàng thụ hưởng', 'vietqr-generator'); ?> <span class="required">*</span></label>
                        <div class="custom-dropdown">
                            <div id="bankDropdown" class="dropdown-selected">
                                <span><?php esc_html_e('-- Chọn ngân hàng --', 'vietqr-generator'); ?></span> <span class="caret">&#9662;</span>
                            </div>
                            <div id="bankMenu" class="dropdown-menu">
                                <input type="text" id="bankSearch" class="form-control" placeholder="<?php esc_attr_e('Tìm ngân hàng...', 'vietqr-generator'); ?>">
                            </div>
                            <input type="hidden" id="bankId" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="label" for="accountNumber"><?php esc_html_e('Số tài khoản thụ hưởng', 'vietqr-generator'); ?> <span class="required">*</span></label>
                        <input type="text" id="accountNumber" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="label" for="accountName"><?php esc_html_e('Tên chủ tài khoản', 'vietqr-generator'); ?> <span class="required">*</span></label>
                        <input type="text" id="accountName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="label" for="amount"><?php esc_html_e('Số tiền chuyển khoản (tùy chọn)', 'vietqr-generator'); ?></label>
                        <input type="number" id="amount" class="form-control" placeholder="<?php esc_attr_e('Ví dụ: 50000', 'vietqr-generator'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="label" for="description"><?php esc_html_e('Nội dung chuyển khoản', 'vietqr-generator'); ?></label>
                        <input type="text" id="description" class="form-control"
                            placeholder="<?php esc_attr_e('Ví dụ: Thanh toán đơn hàng #123', 'vietqr-generator'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="label"><?php esc_html_e('Xác thực', 'vietqr-generator'); ?> <span class="required">*</span></label>
                        <div style="display: flex; align-items: center; margin-right: 10px;">
                            <div id="captchaQuestion" style="font-weight: 500; min-width: 120px;">5 + 3 = ?</div>
                            <input type="number" id="captchaAnswer" class="form-control" style="width: 100px;" required>
                            <button type="button" id="refreshCaptcha"
                                style="background: none; border: none; color: #007bff; cursor: pointer; font-size: 18px;" aria-label="<?php esc_attr_e('Làm mới captcha', 'vietqr-generator'); ?>">🔄</button>
                        </div>
                    </div>
                    <button type="submit" id="generateBtn" class="btn-submit"><?php esc_html_e('Tạo mã', 'vietqr-generator'); ?></button>
                    <div id="errorMessage" class="error-message"><?php esc_html_e('Đã có lỗi xảy ra. Vui lòng thử lại.', 'vietqr-generator'); ?></div>
                </form>
            </div>

            <div class="vietqr-display">
                <h3 class="vietqr-title"><?php esc_html_e('Mã QR của bạn', 'vietqr-generator'); ?></h3>
                <div id="qrCodeResult">
                    <div><?php esc_html_e('QR Code sẽ hiển thị ở đây', 'vietqr-generator'); ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('vietqr_generator', 'vietqr_generator_shortcode');
