<?php
/**
 * Plugin Name: VietQR Generator
 * Plugin URI: https://dpsmedia.vn
 * Description: A secure, high-performance VietQR generator plugin embedded via shortcode.
 * Version: 1.4.6
 * Author: DPS Media
 * Author URI: https://dpsmedia.vn
 * License: GPLv2 or later
 * Text Domain: vietqr-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register frontend assets.
 */
function vietqr_generator_register_assets() {
	wp_register_style(
		'vietqr-font',
		'https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);

	wp_register_style(
		'vietqr-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
		array(),
		'1.4.6'
	);

	wp_register_script(
		'vietqr-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
		array( 'jquery' ),
		'1.4.6',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'vietqr_generator_register_assets' );

/**
 * Register plugin settings.
 */
function vietqr_generator_register_settings() {
	register_setting(
		'vietqr_generator_settings',
		'vietqr_generator_client_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'vietqr_generator_settings',
		'vietqr_generator_api_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);
}
add_action( 'admin_init', 'vietqr_generator_register_settings' );

/**
 * Add settings page.
 */
function vietqr_generator_add_settings_page() {
	add_options_page(
		esc_html__( 'VietQR Generator Settings', 'vietqr-generator' ),
		esc_html__( 'VietQR Generator', 'vietqr-generator' ),
		'manage_options',
		'vietqr-generator-settings',
		'vietqr_generator_render_settings_page'
	);
}
add_action( 'admin_menu', 'vietqr_generator_add_settings_page' );

/**
 * Get Client IP safely.
 */
function vietqr_generator_get_client_ip() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';

	if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$first_ip = trim( $ips[0] ?? '' );
		if ( filter_var( $first_ip, FILTER_VALIDATE_IP ) ) {
			$ip = $first_ip;
		}
	}

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Enforce Rate Limiting per IP using Transients.
 *
 * @param string $action Action name identifier.
 * @param int $max_requests Max allowed requests.
 * @param int $period_seconds Time window in seconds.
 * @return bool True if allowed, false if limit exceeded.
 */
function vietqr_generator_check_rate_limit( $action = 'generate_qr', $max_requests = 10, $period_seconds = 60 ) {
	$ip         = vietqr_generator_get_client_ip();
	$transient_key = 'vqg_rl_' . md5( $action . '_' . $ip );
	$current_count = (int) get_transient( $transient_key );

	if ( $current_count >= $max_requests ) {
		return false;
	}

	set_transient( $transient_key, $current_count + 1, $period_seconds );
	return true;
}

/**
 * Read VietQR credentials from options, constants, or optional local config file.
 *
 * @return array{client_id:string,api_key:string}
 */
function vietqr_generator_get_api_credentials() {
	$client_id = trim( (string) get_option( 'vietqr_generator_client_id', '' ) );
	$api_key   = trim( (string) get_option( 'vietqr_generator_api_key', '' ) );

	if ( ( '' === $client_id || '' === $api_key ) && defined( 'VIETQR_GENERATOR_CLIENT_ID' ) && defined( 'VIETQR_GENERATOR_API_KEY' ) ) {
		$client_id = trim( (string) VIETQR_GENERATOR_CLIENT_ID );
		$api_key   = trim( (string) VIETQR_GENERATOR_API_KEY );
	}

	$config_file = plugin_dir_path( __FILE__ ) . 'vietqr-config.php';
	if ( ( '' === $client_id || '' === $api_key ) && file_exists( $config_file ) ) {
		$config = include $config_file;
		if ( is_array( $config ) ) {
			if ( '' === $client_id && ! empty( $config['client_id'] ) ) {
				$client_id = trim( (string) $config['client_id'] );
			}
			if ( '' === $api_key && ! empty( $config['api_key'] ) ) {
				$api_key = trim( (string) $config['api_key'] );
			}
		}
	}

	return array(
		'client_id' => $client_id,
		'api_key'   => $api_key,
	);
}

/**
 * Render settings page.
 */
function vietqr_generator_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'VietQR Generator Settings', 'vietqr-generator' ); ?></h1>
		<p><?php esc_html_e( 'Enter your VietQR API credentials from My VietQR.', 'vietqr-generator' ); ?></p>
		<form action="options.php" method="post">
			<?php settings_fields( 'vietqr_generator_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vietqr_generator_client_id"><?php esc_html_e( 'Client ID', 'vietqr-generator' ); ?></label></th>
					<td>
						<input
							type="text"
							id="vietqr_generator_client_id"
							name="vietqr_generator_client_id"
							value="<?php echo esc_attr( get_option( 'vietqr_generator_client_id', '' ) ); ?>"
							class="regular-text"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_generator_api_key"><?php esc_html_e( 'API Key', 'vietqr-generator' ); ?></label></th>
					<td>
						<input
							type="password"
							id="vietqr_generator_api_key"
							name="vietqr_generator_api_key"
							value="<?php echo esc_attr( get_option( 'vietqr_generator_api_key', '' ) ); ?>"
							class="regular-text"
							autocomplete="new-password"
						/>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * AJAX: get bank list from VietQR with daily cache.
 */
function vietqr_generator_ajax_get_banks() {
	check_ajax_referer( 'vietqr_generator_nonce', 'nonce' );

	if ( ! vietqr_generator_check_rate_limit( 'get_banks', 30, 60 ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Too many requests. Please slow down.', 'vietqr-generator' ),
			),
			429
		);
	}

	$cached_banks = get_transient( 'vietqr_generator_banks_v2' );
	if ( false !== $cached_banks ) {
		wp_send_json_success(
			array(
				'banks' => $cached_banks,
			)
		);
	}

	$response = wp_remote_get(
		'https://api.vietqr.io/v2/banks',
		array(
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Failed to load bank list.', 'vietqr-generator' ),
			),
			500
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$decoded     = json_decode( $body, true );

	if ( 200 !== $status_code || ! is_array( $decoded ) || empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Invalid bank list response.', 'vietqr-generator' ),
			),
			500
		);
	}

	$banks = array_values(
		array_filter(
			$decoded['data'],
			static function ( $bank ) {
				return is_array( $bank ) && ! empty( $bank['bin'] ) && ! empty( $bank['shortName'] );
			}
		)
	);

	set_transient( 'vietqr_generator_banks_v2', $banks, DAY_IN_SECONDS );

	wp_send_json_success(
		array(
			'banks' => $banks,
		)
	);
}
add_action( 'wp_ajax_vietqr_generator_get_banks', 'vietqr_generator_ajax_get_banks' );
add_action( 'wp_ajax_nopriv_vietqr_generator_get_banks', 'vietqr_generator_ajax_get_banks' );

/**
 * AJAX: generate QR via VietQR API directly.
 */
function vietqr_generator_ajax_generate_qr() {
	check_ajax_referer( 'vietqr_generator_nonce', 'nonce' );

	if ( ! vietqr_generator_check_rate_limit( 'generate_qr', 10, 60 ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Too many requests. Please wait a minute before trying again.', 'vietqr-generator' ),
			),
			429
		);
	}

	$credentials = vietqr_generator_get_api_credentials();
	$client_id   = $credentials['client_id'];
	$api_key     = $credentials['api_key'];

	if ( '' === $client_id || '' === $api_key ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'VietQR API credentials are missing. Please configure them in Settings > VietQR Generator.', 'vietqr-generator' ),
			),
			400
		);
	}

	$account_no = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['accountNo'] ?? '' ) );
	$acq_id     = absint( wp_unslash( $_POST['acqId'] ?? 0 ) );
	$amount     = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['amount'] ?? '' ) );
	$add_info   = sanitize_text_field( wp_unslash( $_POST['addInfo'] ?? '' ) );

	$account_name_raw = sanitize_text_field( wp_unslash( $_POST['accountName'] ?? '' ) );
	$account_name     = strtoupper( trim( $account_name_raw ) );

	if ( strlen( $account_no ) < 6 || strlen( $account_no ) > 19 ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Invalid account number (must be between 6 and 19 digits).', 'vietqr-generator' ),
			),
			400
		);
	}

	if ( empty( $account_name ) || strlen( $account_name ) < 2 || strlen( $account_name ) > 50 ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Invalid account name.', 'vietqr-generator' ),
			),
			400
		);
	}

	if ( $acq_id < 100000 || $acq_id > 999999 ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Invalid bank BIN.', 'vietqr-generator' ),
			),
			400
		);
	}

	$payload = array(
		'accountNo'   => $account_no,
		'accountName' => $account_name,
		'acqId'       => $acq_id,
		'template'    => 'compact',
	);

	if ( '' !== $amount && is_numeric( $amount ) && (float) $amount > 0 ) {
		$payload['amount'] = substr( $amount, 0, 12 );
	}

	if ( '' !== $add_info ) {
		$payload['addInfo'] = substr( $add_info, 0, 99 );
	}

	$response = wp_remote_post(
		'https://api.vietqr.io/v2/generate',
		array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-client-id'  => $client_id,
				'x-api-key'    => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Failed to connect to VietQR API.', 'vietqr-generator' ),
			),
			500
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	$decoded     = json_decode( $body, true );

	if ( 200 !== $status_code || ! is_array( $decoded ) ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Invalid VietQR API response.', 'vietqr-generator' ),
			),
			500
		);
	}

	if ( empty( $decoded['code'] ) || '00' !== (string) $decoded['code'] ) {
		$desc = ! empty( $decoded['desc'] ) ? sanitize_text_field( (string) $decoded['desc'] ) : esc_html__( 'VietQR API returned an error.', 'vietqr-generator' );
		wp_send_json_error(
			array(
				'message' => $desc,
			),
			400
		);
	}

	$qr_data_url = '';
	$qr_code     = '';

	if ( ! empty( $decoded['data']['qrDataURL'] ) ) {
		$qr_data_url = (string) $decoded['data']['qrDataURL'];
	}

	if ( ! empty( $decoded['data']['qrCode'] ) ) {
		$qr_code = (string) $decoded['data']['qrCode'];
	}

	if ( '' === $qr_data_url ) {
		wp_send_json_error(
			array(
				'message' => esc_html__( 'VietQR response does not include qrDataURL.', 'vietqr-generator' ),
			),
			500
		);
	}

	wp_send_json_success(
		array(
			'qrDataURL' => $qr_data_url,
			'qrCode'    => $qr_code,
		)
	);
}
add_action( 'wp_ajax_vietqr_generator_generate_qr', 'vietqr_generator_ajax_generate_qr' );
add_action( 'wp_ajax_nopriv_vietqr_generator_generate_qr', 'vietqr_generator_ajax_generate_qr' );

/**
 * Render shortcode.
 */
function vietqr_generator_shortcode() {
	wp_enqueue_style( 'vietqr-font' );
	wp_enqueue_style( 'vietqr-style' );
	wp_enqueue_script( 'vietqr-script' );

	wp_localize_script(
		'vietqr-script',
		'vietqrVars',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'vietqr_generator_nonce' ),
		)
	);

	ob_start();
	?>
	<div id="vietqr-embed" class="vqg-scope">
		<div id="vietqr-toast" class="vietqr-toast">
			<?php esc_html_e( 'Đã tạo mã VietQR thành công', 'vietqr-generator' ); ?>
		</div>
		<div class="vietqr-wrap">
			<div class="vietqr-grid">
				<section class="vietqr-panel vietqr-form-panel">
					<div class="vqg-panel-title" role="heading" aria-level="2"><?php esc_html_e( 'Thông tin thiết lập mã VietQR', 'vietqr-generator' ); ?></div>
					<div class="vietqr-sub"><?php esc_html_e( 'Nhập đúng thông tin để tạo mã chuyển khoản chính xác.', 'vietqr-generator' ); ?></div>

					<form id="vqg-form" autocomplete="off">
						<div class="vq-field-wrap dropdown-wrap">
							<label class="vq-label"><?php esc_html_e( 'Ngân hàng thụ hưởng', 'vietqr-generator' ); ?> *</label>
							<div class="custom-dropdown">
								<div id="vqg-bank-dropdown" class="dropdown-selected" tabindex="0" role="button" aria-expanded="false">
									<span class="selected-text"><?php esc_html_e( 'Chọn ngân hàng', 'vietqr-generator' ); ?></span>
									<span class="caret">&#9662;</span>
								</div>
								<div id="vqg-bank-menu" class="dropdown-menu">
									<div class="dropdown-search-wrap">
										<input type="text" id="vqg-bank-search" class="form-control" placeholder="<?php esc_attr_e( 'Tìm nhanh ngân hàng...', 'vietqr-generator' ); ?>">
									</div>
									<div class="dropdown-items-list"></div>
								</div>
								<input type="hidden" id="vqg-bank-id" required>
							</div>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-account-number" class="vq-label"><?php esc_html_e( 'Số tài khoản thụ hưởng', 'vietqr-generator' ); ?> *</label>
							<input type="text" id="vqg-account-number" class="form-control underline" data-max="19" maxlength="19" required>
							<span class="char-counter" id="vqg-account-no-counter">0/19</span>
						</div>

						<div class="vq-toggle-row">
							<label class="switch">
								<input type="checkbox" id="vqg-show-account-full" checked>
								<span class="slider"></span>
							</label>
							<span class="toggle-label"><?php esc_html_e( 'Đồng ý hiển thị toàn bộ số tài khoản của tôi tại mã VietQR', 'vietqr-generator' ); ?></span>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-account-name" class="vq-label"><?php esc_html_e( 'Tên chủ tài khoản', 'vietqr-generator' ); ?> *</label>
							<input type="text" id="vqg-account-name" class="form-control underline uppercase" data-max="50" maxlength="50" required>
							<span class="char-counter" id="vqg-account-name-counter">0/50</span>
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-amount" class="vq-label"><?php esc_html_e( 'Số tiền chuyển khoản', 'vietqr-generator' ); ?></label>
							<input type="text" id="vqg-amount" class="form-control underline" inputmode="numeric" placeholder="VD: 500000">
						</div>

						<div class="vq-field-wrap">
							<label for="vqg-description" class="vq-label"><?php esc_html_e( 'Nội dung chuyển khoản', 'vietqr-generator' ); ?></label>
							<input type="text" id="vqg-description" class="form-control underline" data-max="99" maxlength="99">
							<span id="vqg-description-counter" class="char-counter">0/99</span>
						</div>

						<div id="vqg-extra-trigger" class="vq-extra-trigger">
							<?php esc_html_e( 'Tùy chọn thêm', 'vietqr-generator' ); ?> <span class="arrow">&#9662;</span>
						</div>

						<div id="vqg-extra-fields" class="vq-extra-fields">
							<div class="vq-field-wrap">
								<label for="vqg-store-code" class="vq-label"><?php esc_html_e( 'Mã cửa hàng', 'vietqr-generator' ); ?></label>
								<input type="text" id="vqg-store-code" class="form-control underline" data-max="25" maxlength="25">
								<span id="vqg-store-code-counter" class="char-counter">0/25</span>
							</div>

							<div class="vq-field-wrap">
								<label for="vqg-pos-code" class="vq-label"><?php esc_html_e( 'Mã điểm bán', 'vietqr-generator' ); ?></label>
								<input type="text" id="vqg-pos-code" class="form-control underline" data-max="25" maxlength="25">
								<span id="vqg-pos-code-counter" class="char-counter">0/25</span>
							</div>
						</div>

						<div class="vq-captcha-row">
							<span class="vq-label"><?php esc_html_e( 'Xác thực', 'vietqr-generator' ); ?> *</span>
							<div class="vq-captcha-wrap">
								<span id="vqg-captcha-question" class="vq-q-badge">...</span>
								<input type="number" id="vqg-captcha-answer" class="vq-a-input" placeholder="?" required>
								<button type="button" id="vqg-refresh-captcha" class="vq-refresh-btn" title="<?php esc_attr_e( 'Đổi phép tính', 'vietqr-generator' ); ?>">↻</button>
							</div>
						</div>

						<div class="vq-agree-check">
							<input type="checkbox" id="vqg-agree-terms" required>
							<label for="vqg-agree-terms"><?php esc_html_e( 'Tôi đồng ý với các điều khoản và điều kiện', 'vietqr-generator' ); ?></label>
						</div>

						<button type="submit" id="vqg-generate-btn" class="btn-primary-official">
							<span class="btn-icon">⚡</span>
							<span class="btn-text"><?php esc_html_e( 'Tạo mã', 'vietqr-generator' ); ?></span>
							<span class="btn-loader" aria-hidden="true"></span>
						</button>
						<div id="vqg-error-message" class="error-message"></div>
					</form>
				</section>

				<section class="vietqr-panel vietqr-preview-panel">
					<div class="vqg-panel-title" role="heading" aria-level="2"><?php esc_html_e( 'Mã QR của bạn', 'vietqr-generator' ); ?></div>
					<div class="vietqr-sub"><?php esc_html_e( 'Ảnh xem trước và ảnh tải xuống sẽ giữ đầy đủ thông tin.', 'vietqr-generator' ); ?></div>
					<div id="vqg-qr-result" class="vietqr-result-container">
						<div class="vietqr-preview-placeholder">
							<div><?php esc_html_e( 'Nhập thông tin và bấm "Tạo mã" để xem QR.', 'vietqr-generator' ); ?></div>
						</div>
					</div>
				</section>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'vietqr_generator', 'vietqr_generator_shortcode' );
