<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class VietQR_API
{

	const REST_NAMESPACE = 'vietqr-generator/v1';

	public function __construct()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
		add_filter('rest_post_dispatch', array($this, 'set_no_store_headers'), 10, 3);
	}

	/**
	 * Prevent CDN/page caches from storing responses from this plugin's REST API.
	 *
	 * The bank list is cached at the WordPress transient layer, so it does not
	 * need to be cached as an HTTP response by Cloudflare or a page cache.
	 */
	public function set_no_store_headers($response, $server, $request)
	{
		$route_prefix = '/' . trim(self::REST_NAMESPACE, '/') . '/';
		$route = $request instanceof WP_REST_Request ? $request->get_route() : '';

		if (0 !== strpos($route, $route_prefix)) {
			return $response;
		}

		if (is_object($response) && method_exists($response, 'header')) {
			$cache_control = 'no-store, no-cache, must-revalidate, max-age=0';
			$response->header('Cache-Control', $cache_control);
			$response->header('Pragma', 'no-cache');
			$response->header('Expires', '0');
		}

		return $response;
	}

	public function register_routes(): void
	{
		register_rest_route(
			self::REST_NAMESPACE,
			'/google-login',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_google_login'),
				'permission_callback' => '__return_true', // Nonce checked manually inside callback
				'args' => array(
					'credential' => array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/logout',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_logout'),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/bank-list',
			array(
				'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
				'callback' => array($this, 'handle_bank_list'),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/generate-qr',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_generate_qr'),
				'permission_callback' => '__return_true',
				'args' => array(
					'accountNo' => array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'accountName' => array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'acqId' => array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'amount' => array(
						'required' => false,
						'type' => array('string', 'number', 'null'),
						'sanitize_callback' => array($this, 'sanitize_nullable_amount'),
					),
					'addInfo' => array(
						'required' => false,
						'type' => array('string', 'null'),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function sanitize_nullable_amount($value)
	{
		if (null === $value || '' === $value) {
			return null;
		}
		return is_numeric($value) ? (string) ((float) $value) : null;
	}

	private function verify_nonce(WP_REST_Request $request): bool
	{
		if (!is_user_logged_in()) {
			return true;
		}
		$nonce = $request->get_header('X-WP-Nonce');
		if (empty($nonce)) {
			$nonce = $request->get_param('_wpnonce');
		}
		return !empty($nonce) && wp_verify_nonce($nonce, 'wp_rest');
	}

	private function get_client_ip(): string
	{
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';

		if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$first_ip = trim($ips[0] ?? '');
			if (filter_var($first_ip, FILTER_VALIDATE_IP)) {
				$ip = $first_ip;
			}
		}

		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	private function is_ip_blocked(string $ip): bool
	{
		$blocklist = get_option('vietqr_ip_blocklist', '');
		if (empty($blocklist)) {
			return false;
		}
		$blocked_ips = array_map('trim', explode("\n", $blocklist));
		return in_array($ip, $blocked_ips, true);
	}

	private function is_rate_limited(string $ip, string $action = 'generate_qr'): bool
	{
		$max_requests = (int) get_option('vietqr_rate_limit_max', 10);
		$window_seconds = (int) get_option('vietqr_rate_limit_window', 60);

		$transient_key = 'vietqr_rl_' . md5($action . '_' . $ip);
		$current = (int) get_transient($transient_key);

		if ($current >= $max_requests) {
			return true;
		}

		set_transient($transient_key, $current + 1, $window_seconds);
		return false;
	}

	private function get_user_identifier(): string
	{
		if (is_user_logged_in()) {
			$user = wp_get_current_user();
			return $user->user_email ?: $user->user_login;
		}
		return 'Guest';
	}

	public function handle_google_login(WP_REST_Request $request)
	{
		$ip = $this->get_client_ip();
		$ip_for_log = $ip ?: 'unknown';

		if (!$this->verify_nonce($request)) {
			VietQR_DB::log($ip_for_log, 'Guest', 'google_login', 'blocked', 'Invalid REST Nonce on Google Sign-In.');
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Security check failed. Please refresh the page.', 'vietqr-generator')),
				403
			);
		}

		$client_id = get_option('vietqr_google_client_id', '');
		if (empty($client_id)) {
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Google Client ID is not configured on server.', 'vietqr-generator')),
				400
			);
		}

		$credential = $request->get_param('credential');
		$verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

		$response = wp_remote_get($verify_url, array('timeout' => 15));
		if (is_wp_error($response)) {
			VietQR_DB::log($ip_for_log, 'Guest', 'google_login', 'failed', 'Could not reach Google verification servers.');
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Failed to verify Google token with Google servers.', 'vietqr-generator')),
				500
			);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (200 !== $status_code || empty($data) || isset($data['error'])) {
			$error_msg = $data['error_description'] ?? ($data['error'] ?? __('Invalid Google token.', 'vietqr-generator'));
			VietQR_DB::log($ip_for_log, 'Guest', 'google_login', 'failed', $error_msg);
			return new WP_REST_Response(array('success' => false, 'message' => $error_msg), 400);
		}

		// 1. Validate audience (Client ID)
		$aud = $data['aud'] ?? '';
		if ($aud !== $client_id) {
			VietQR_DB::log($ip_for_log, 'Guest', 'google_login', 'failed', 'Audience mismatch.');
			return new WP_REST_Response(array('success' => false, 'message' => __('Google Client ID audience verification failed.', 'vietqr-generator')), 400);
		}

		// 2. Validate issuer
		$iss = $data['iss'] ?? '';
		if (!in_array($iss, array('https://accounts.google.com', 'accounts.google.com'), true)) {
			return new WP_REST_Response(array('success' => false, 'message' => __('Issuer verification failed.', 'vietqr-generator')), 400);
		}

		// 3. Validate email_verified
		$email_verified = $data['email_verified'] ?? false;
		if (true !== $email_verified && 'true' !== $email_verified) {
			return new WP_REST_Response(array('success' => false, 'message' => __('Google email is not verified.', 'vietqr-generator')), 400);
		}

		// 4. Validate expiration
		$exp = intval($data['exp'] ?? 0);
		if ($exp <= time()) {
			return new WP_REST_Response(array('success' => false, 'message' => __('Google token has expired.', 'vietqr-generator')), 400);
		}

		$email = strtolower(trim($data['email'] ?? ''));
		$name = trim($data['name'] ?? '');
		$sub = trim($data['sub'] ?? '');

		if (empty($email) || empty($sub)) {
			return new WP_REST_Response(array('success' => false, 'message' => __('Invalid Google profile payload.', 'vietqr-generator')), 400);
		}

		// 5. Look up user by Google 'sub' meta key
		$user = null;
		$users_query = get_users(array(
			'meta_key' => 'vietqr_google_sub',
			'meta_value' => $sub,
			'number' => 1,
		));

		if (!empty($users_query)) {
			$user = $users_query[0];
		} else {
			// Find user by email
			$user = get_user_by('email', $email);
			if ($user) {
				// Block silent linking of privileged accounts
				if (user_can($user, 'edit_posts') || user_can($user, 'manage_options')) {
					VietQR_DB::log($ip_for_log, $email, 'google_login', 'blocked', 'Attempted silent linking to privileged account.');
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => __('For security reasons, administrative accounts cannot be linked silently.', 'vietqr-generator'),
						),
						403
					);
				}
				update_user_meta($user->ID, 'vietqr_google_sub', $sub);
			} else {
				// Provision new subscriber account
				$username = sanitize_user($email, true);
				if (username_exists($username)) {
					$username = $username . '_' . wp_rand(100, 999);
				}
				$password = wp_generate_password();
				$user_id = wp_insert_user(array(
					'user_login' => $username,
					'user_email' => $email,
					'user_pass' => $password,
					'role' => 'subscriber',
					'display_name' => $name ? sanitize_text_field($name) : $email,
				));

				if (is_wp_error($user_id)) {
					VietQR_DB::log($ip_for_log, $email, 'google_login', 'failed', $user_id->get_error_message());
					return new WP_REST_Response(array('success' => false, 'message' => $user_id->get_error_message()), 500);
				}

				$user = get_user_by('id', $user_id);
				update_user_meta($user->ID, 'vietqr_google_sub', $sub);
			}
		}

		// Log user in
		wp_clear_auth_cookie();
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID, true);

		VietQR_DB::log($ip_for_log, $email, 'google_login', 'success', 'Successfully authenticated via Google Sign-In.');

		return new WP_REST_Response(
			array(
				'success' => true,
				'nonce' => wp_create_nonce('wp_rest'),
				'user' => array(
					'display_name' => $user->display_name,
					'email' => $user->user_email,
				),
			),
			200
		);
	}

	public function handle_logout(WP_REST_Request $request)
	{
		wp_logout();
		return new WP_REST_Response(
			array(
				'success' => true,
				'nonce' => wp_create_nonce('wp_rest'),
			),
			200
		);
	}

	public function handle_bank_list(WP_REST_Request $request)
	{
		$ip = $this->get_client_ip();
		$ip_for_log = $ip ?: 'unknown';
		$user_id = $this->get_user_identifier();

		// 1. Nonce verification
		if (!$this->verify_nonce($request)) {
			VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'blocked', 'Invalid or missing REST Nonce.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Security check failed. Please refresh the page.', 'vietqr-generator'),
				),
				403
			);
		}

		// 2. IP Blocklist check
		if ($ip && $this->is_ip_blocked($ip)) {
			VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'blocked', 'Blocked IP address attempted bank list fetch.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Access denied. Your IP has been blocked.', 'vietqr-generator'),
				),
				403
			);
		}

		// Check transient cache
		$cache_key = 'vietqr_bank_list_cache_v2';
		$cached = get_transient($cache_key);
		if (false !== $cached && is_array($cached)) {
			VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'success', 'Served bank list from cache.');
			return new WP_REST_Response(array('success' => true, 'banks' => $cached), 200);
		}

		// Fetch from upstream
		$response = wp_remote_get(
			'https://api.vietqr.io/v2/banks',
			array('timeout' => 15)
		);

		if (is_wp_error($response)) {
			VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'failed', $response->get_error_message());
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Failed to fetch bank list from VietQR API.', 'vietqr-generator')),
				500
			);
		}

		$body = wp_remote_retrieve_body($response);
		$decoded = json_decode($body, true);

		if (empty($decoded) || empty($decoded['data']) || !is_array($decoded['data'])) {
			VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'failed', 'Invalid json from upstream.');
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Invalid bank list response format.', 'vietqr-generator')),
				500
			);
		}

		$banks = array_values(
			array_filter(
				$decoded['data'],
				static function ($bank) {
					return is_array($bank) && !empty($bank['bin']) && !empty($bank['shortName']);
				}
			)
		);

		set_transient($cache_key, $banks, DAY_IN_SECONDS);
		VietQR_DB::log($ip_for_log, $user_id, 'bank_list', 'success', 'Bank list fetched successfully.');

		return new WP_REST_Response(array('success' => true, 'banks' => $banks), 200);
	}

	public function handle_generate_qr(WP_REST_Request $request)
	{
		$ip = $this->get_client_ip();
		$ip_for_log = $ip ?: 'unknown';
		$user_id = $this->get_user_identifier();

		// 0. Login Requirement Check
		if (get_option('vietqr_require_login', false) && !is_user_logged_in()) {
			VietQR_DB::log($ip_for_log, 'Guest', 'generate_qr', 'blocked', 'Unauthenticated user attempted QR generation.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Please sign in with Google to generate QR codes.', 'vietqr-generator'),
				),
				401
			);
		}

		// 1. Nonce verification
		if (!$this->verify_nonce($request)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'blocked', 'Invalid or missing REST Nonce.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Security check failed. Please refresh the page.', 'vietqr-generator'),
				),
				403
			);
		}

		// 2. IP Blocklist check
		if ($ip && $this->is_ip_blocked($ip)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'blocked', 'Blocked IP address attempted QR generation.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Access denied. Your IP has been blocked.', 'vietqr-generator'),
				),
				403
			);
		}

		// 3. Rate limiting check
		if ($ip && $this->is_rate_limited($ip, 'generate_qr')) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'rate_limited', 'Rate limit exceeded.');
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Too many requests. Please wait a moment before trying again.', 'vietqr-generator'),
				),
				429
			);
		}

		// Credentials
		$client_id = get_option('vietqr_generator_client_id', '');
		$api_key = get_option('vietqr_generator_api_key', '');

		if (('' === $client_id || '' === $api_key) && defined('VIETQR_GENERATOR_CLIENT_ID') && defined('VIETQR_GENERATOR_API_KEY')) {
			$client_id = (string) VIETQR_GENERATOR_CLIENT_ID;
			$api_key = (string) VIETQR_GENERATOR_API_KEY;
		}

		$config_file = plugin_dir_path(dirname(__FILE__)) . 'vietqr-config.php';
		if (('' === $client_id || '' === $api_key) && file_exists($config_file)) {
			$config = include $config_file;
			if (is_array($config)) {
				$client_id = $client_id ?: ($config['client_id'] ?? '');
				$api_key = $api_key ?: ($config['api_key'] ?? '');
			}
		}

		if (empty($client_id) || empty($api_key)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Missing API credentials.');
			return new WP_REST_Response(
				array('success' => false, 'message' => __('VietQR API credentials are not configured.', 'vietqr-generator')),
				400
			);
		}

		$account_no = preg_replace('/\D+/', '', (string) $request->get_param('accountNo'));
		$acq_id = absint($request->get_param('acqId'));
		$amount = preg_replace('/\D+/', '', (string) ($request->get_param('amount') ?? ''));
		$add_info = sanitize_text_field((string) ($request->get_param('addInfo') ?? ''));
		$account_name = strtoupper(trim(sanitize_text_field((string) $request->get_param('accountName'))));

		if (strlen($account_no) < 6 || strlen($account_no) > 19) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Invalid account number length.');
			return new WP_REST_Response(array('success' => false, 'message' => __('Invalid account number.', 'vietqr-generator')), 400);
		}

		if (empty($account_name) || strlen($account_name) < 2 || strlen($account_name) > 50) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Invalid account name.');
			return new WP_REST_Response(array('success' => false, 'message' => __('Invalid account name.', 'vietqr-generator')), 400);
		}

		if ($acq_id < 100000 || $acq_id > 999999) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Invalid bank BIN.');
			return new WP_REST_Response(array('success' => false, 'message' => __('Invalid bank BIN.', 'vietqr-generator')), 400);
		}

		$payload = array(
			'accountNo' => $account_no,
			'accountName' => $account_name,
			'acqId' => $acq_id,
			'template' => 'compact',
		);

		if ('' !== $amount && is_numeric($amount) && (float) $amount > 0) {
			$payload['amount'] = substr($amount, 0, 12);
		}

		if ('' !== $add_info) {
			$payload['addInfo'] = substr($add_info, 0, 99);
		}

		$response = wp_remote_post(
			'https://api.vietqr.io/v2/generate',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-client-id' => $client_id,
					'x-api-key' => $api_key,
				),
				'body' => wp_json_encode($payload),
			)
		);

		if (is_wp_error($response)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', $response->get_error_message());
			return new WP_REST_Response(array('success' => false, 'message' => __('Failed to connect to VietQR API.', 'vietqr-generator')), 500);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$decoded = json_decode($body, true);

		if (200 !== $status_code || !is_array($decoded)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Invalid VietQR API response HTTP ' . $status_code);
			return new WP_REST_Response(array('success' => false, 'message' => __('Invalid VietQR API response.', 'vietqr-generator')), 500);
		}

		if (empty($decoded['code']) || '00' !== (string) $decoded['code']) {
			$desc = !empty($decoded['desc']) ? sanitize_text_field((string) $decoded['desc']) : __('VietQR API returned an error.', 'vietqr-generator');
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', $desc);
			return new WP_REST_Response(array('success' => false, 'message' => $desc), 400);
		}

		$qr_data_url = (string) ($decoded['data']['qrDataURL'] ?? '');
		$qr_code = (string) ($decoded['data']['qrCode'] ?? '');

		if (empty($qr_data_url)) {
			VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'failed', 'Missing qrDataURL in payload.');
			return new WP_REST_Response(array('success' => false, 'message' => __('API response missing QR data URL.', 'vietqr-generator')), 500);
		}

		VietQR_DB::log($ip_for_log, $user_id, 'generate_qr', 'success', "Generated QR for $account_no ($account_name)");

		return new WP_REST_Response(
			array(
				'success' => true,
				'data' => array(
					'qrDataURL' => $qr_data_url,
					'qrCode' => $qr_code,
				),
				'qrDataURL' => $qr_data_url,
				'qrCode' => $qr_code,
			),
			200
		);
	}
}
