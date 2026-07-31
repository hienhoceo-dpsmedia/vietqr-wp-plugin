<?php
/**
 * Plugin Name: VietQR Generator
 * Plugin URI: https://dpsmedia.vn
 * Description: Enterprise, high-performance VietQR generator plugin with WP REST API proxying, IP security, rate limiting, DB request logging, and Google Sign-In authentication.
 * Version: 1.6.5
 * Author: DPS Media
 * Author URI: https://dpsmedia.vn
 * License: GPLv2 or later
 * Text Domain: vietqr-generator
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

define('VIETQR_VERSION', '1.6.5');
define('VIETQR_PATH', plugin_dir_path(__FILE__));
define('VIETQR_URL', plugin_dir_url(__FILE__));

// Include required security and core components
require_once VIETQR_PATH . 'includes/class-vietqr-db.php';
require_once VIETQR_PATH . 'includes/class-vietqr-api.php';
require_once VIETQR_PATH . 'includes/class-vietqr-admin.php';

// Activation and deactivation hooks
register_activation_hook(__FILE__, 'vietqr_activate_plugin');
function vietqr_activate_plugin()
{
	VietQR_DB::activate();
	if (!wp_next_scheduled('vietqr_daily_cleanup_cron')) {
		wp_schedule_event(time(), 'daily', 'vietqr_daily_cleanup_cron');
	}
}

register_deactivation_hook(__FILE__, 'vietqr_deactivate_plugin');
function vietqr_deactivate_plugin()
{
	wp_clear_scheduled_hook('vietqr_daily_cleanup_cron');
}

// Daily cleanup cron task
add_action('vietqr_daily_cleanup_cron', 'vietqr_daily_cleanup');
function vietqr_daily_cleanup()
{
	VietQR_DB::purge_old_logs(30);
}

// Initialize the plugin components
add_action('plugins_loaded', 'vietqr_init_plugin');
function vietqr_init_plugin()
{
	new VietQR_API();
	new VietQR_Admin();
}

/**
 * Register frontend assets.
 */
function vietqr_generator_register_assets()
{
	wp_register_style(
		'vietqr-font',
		'https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);

	wp_register_style(
		'vietqr-style',
		VIETQR_URL . 'assets/css/style.css',
		array(),
		VIETQR_VERSION
	);

	wp_register_script(
		'google-gsi',
		'https://accounts.google.com/gsi/client',
		array(),
		null,
		true
	);

	wp_register_script(
		'vietqr-script',
		VIETQR_URL . 'assets/js/script.js',
		array('jquery'),
		VIETQR_VERSION,
		true
	);
}
add_action('wp_enqueue_scripts', 'vietqr_generator_register_assets');

/**
 * Render shortcode [vietqr_generator].
 */
function vietqr_generator_shortcode()
{
	wp_enqueue_style('vietqr-font');
	wp_enqueue_style('vietqr-style');

	$google_client_id = get_option('vietqr_google_client_id', '');
	if (!empty($google_client_id)) {
		wp_enqueue_script('google-gsi');
	}

	wp_enqueue_script('vietqr-script');

	$require_login = (bool) get_option('vietqr_require_login', false);

	wp_localize_script(
		'vietqr-script',
		'vietqrVars',
		array(
			'restUrl' => esc_url_raw(get_rest_url(null, '/vietqr-generator/v1')),
			'nonce' => wp_create_nonce('wp_rest'),
			'googleClientId' => esc_attr($google_client_id),
			'requireLogin' => $require_login ? '1' : '0',
			'isLoggedIn' => is_user_logged_in() ? '1' : '0',
		)
	);

	ob_start();
	?>
	<div id="vietqr-embed" class="vqg-scope">
		<div id="vietqr-toast" class="vietqr-toast">
			<?php esc_html_e('Đã tạo mã VietQR thành công', 'vietqr-generator'); ?>
		</div>
		<div class="vietqr-wrap">
			<div id="vqg-auth-box" class="vqg-auth-box"></div>

			<div id="vqg-app-grid" class="vietqr-grid">
				<section class="vietqr-panel vietqr-form-panel">
					<div class="vqg-panel-title" role="heading" aria-level="2">
						<?php esc_html_e('Thông tin thiết lập mã VietQR', 'vietqr-generator'); ?></div>
					<div class="vietqr-sub">
						<?php esc_html_e('Nhập đúng thông tin để tạo mã chuyển khoản chính xác.', 'vietqr-generator'); ?>
					</div>

					<form id="vqg-form" autocomplete="off">
						<div class="vq-field-wrap dropdown-wrap">
							<label class="vq-label"><?php esc_html_e('Ngân hàng thụ hưởng', 'vietqr-generator'); ?>
								*</label>
							<div class="custom-dropdown">
								<div id="vqg-bank-dropdown" class="dropdown-selected" tabindex="0" role="button"
									aria-expanded="false">
									<span
										class="selected-text"><?php esc_html_e('Chọn ngân hàng', 'vietqr-generator'); ?></span>
									<span class="caret">&#9662;</span>
								</div>
								<div id="vqg-bank-menu" class="dropdown-menu">
									<div class="dropdown-search-wrap">
										<input type="text" id="vqg-bank-search" class="form-control"
											placeholder="<?php esc_attr_e('Tìm nhanh ngân hàng...', 'vietqr-generator'); ?>">
									</div>
									<div class="dropdown-items-list"></div>
								</div>
								<input type="hidden" id="vqg-bank-id" required>
							</div>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-account-number"
								class="vq-label"><?php esc_html_e('Số tài khoản thụ hưởng', 'vietqr-generator'); ?>
								*</label>
							<input type="text" id="vqg-account-number" class="form-control underline" data-max="19"
								maxlength="19" required>
							<span class="char-counter" id="vqg-account-no-counter">0/19</span>
						</div>

						<div class="vq-toggle-row">
							<label class="switch">
								<input type="checkbox" id="vqg-show-account-full" checked>
								<span class="slider"></span>
							</label>
							<span
								class="toggle-label"><?php esc_html_e('Đồng ý hiển thị toàn bộ số tài khoản của tôi tại mã VietQR', 'vietqr-generator'); ?></span>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-account-name"
								class="vq-label"><?php esc_html_e('Tên chủ tài khoản', 'vietqr-generator'); ?> *</label>
							<input type="text" id="vqg-account-name" class="form-control underline uppercase" data-max="50"
								maxlength="50" required>
							<span class="char-counter" id="vqg-account-name-counter">0/50</span>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-amount"
								class="vq-label"><?php esc_html_e('Số tiền chuyển khoản', 'vietqr-generator'); ?></label>
							<input type="text" id="vqg-amount" class="form-control underline" inputmode="numeric"
								placeholder="VD: 500000">
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-description"
								class="vq-label"><?php esc_html_e('Nội dung chuyển khoản', 'vietqr-generator'); ?></label>
							<input type="text" id="vqg-description" class="form-control underline" data-max="99"
								maxlength="99">
							<span id="vqg-description-counter" class="char-counter">0/99</span>
						</div>

						<div id="vqg-extra-trigger" class="vq-extra-trigger">
							<?php esc_html_e('Tùy chọn thêm', 'vietqr-generator'); ?> <span class="arrow">&#9662;</span>
						</div>

						<div id="vqg-extra-fields" class="vq-extra-fields">
							<div class="vq-field-wrap">
								<label for="vqg-store-code"
									class="vq-label"><?php esc_html_e('Mã cửa hàng', 'vietqr-generator'); ?></label>
								<input type="text" id="vqg-store-code" class="form-control underline" data-max="25"
									maxlength="25">
								<span id="vqg-store-code-counter" class="char-counter">0/25</span>
							</div>

							<div class="vq-field-wrap">
								<label for="vqg-pos-code"
									class="vq-label"><?php esc_html_e('Mã điểm bán', 'vietqr-generator'); ?></label>
								<input type="text" id="vqg-pos-code" class="form-control underline" data-max="25"
									maxlength="25">
								<span id="vqg-pos-code-counter" class="char-counter">0/25</span>
							</div>
						</div>

						<button type="submit" id="vqg-generate-btn" class="btn-primary-official">
							<span class="btn-icon">⚡</span>
							<span class="btn-text"><?php esc_html_e('Tạo mã', 'vietqr-generator'); ?></span>
							<span class="btn-loader" aria-hidden="true"></span>
						</button>
						<div id="vqg-error-message" class="error-message"></div>
					</form>
				</section>

				<section class="vietqr-panel vietqr-preview-panel">
					<div class="vqg-panel-title" role="heading" aria-level="2">
						<?php esc_html_e('Mã QR của bạn', 'vietqr-generator'); ?></div>
					<div class="vietqr-sub">
						<?php esc_html_e('Ảnh xem trước và ảnh tải xuống sẽ giữ đầy đủ thông tin.', 'vietqr-generator'); ?>
					</div>
					<div id="vqg-qr-result" class="vietqr-result-container">
						<div class="vietqr-preview-placeholder">
							<div><?php esc_html_e('Nhập thông tin và bấm "Tạo mã" để xem QR.', 'vietqr-generator'); ?>
							</div>
						</div>
					</div>
				</section>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode('vietqr_generator', 'vietqr_generator_shortcode');
