<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VietQR_API {

	const REST_NAMESPACE = 'vietqr-generator/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/bank-list',
			array(
				'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
				'callback'            => array( $this, 'handle_bank_list' ),
				'permission_callback' => '__return_true', // Nonce checked manually inside callback
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/generate-qr',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_generate_qr' ),
				'permission_callback' => '__return_true', // Nonce checked manually inside callback
				'args'                => array(
					'accountNo'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'accountName' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'acqId'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'amount'      => array(
						'required'          => false,
						'type'              => array( 'string', 'number', 'null' ),
						'sanitize_callback' => array( $this, 'sanitize_nullable_amount' ),
					),
					'addInfo'     => array(
						'required'          => false,
						'type'              => array( 'string', 'null' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function sanitize_nullable_amount( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return is_numeric( $value ) ? (string) ( (float) $value ) : null;
	}

	public function handle_bank_list( WP_REST_Request $request ) {
		$ip         = $this->get_client_ip();
		$ip_for_log = $ip ?: 'unknown';
		$user_id    = $this->get_user_identifier();

		// 1. Nonce verification
		if ( ! $this->verify_nonce( $request ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'blocked', 'Invalid or missing REST Nonce.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Security check failed. Please refresh the page.', 'vietqr-generator' ),
				),
				403
			);
		}

		// 2. IP Blocklist check
		if ( $ip && $this->is_ip_blocked( $ip ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'blocked', 'Blocked IP address attempted bank list fetch.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Access denied. Your IP has been blocked.', 'vietqr-generator' ),
				),
				403
			);
		}

		// 3. Rate limiting check
		if ( $ip && $this->is_rate_limited( $ip ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'rate_limited', 'Rate limit exceeded.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Too many requests. Please slow down.', 'vietqr-generator' ),
				),
				429
			);
		}

		// Check transient cache for bank list to avoid hitting upstream repeatedly
		$cache_key = 'vietqr_bank_list_cache';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'success', 'Served bank list from local cache.' );
			return new WP_REST_Response( $cached, 200 );
		}

		// Fetch from configured upstream webhook
		$webhook_url = get_option( 'vietqr_bank_list_webhook', 'https://auto.dpsmedia.vn/webhook/banklistdpsmedia' );
		$response    = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => json_encode( new stdClass() ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'failed', $response->get_error_message() );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to fetch bank list from upstream service.', 'vietqr-generator' ),
				),
				500
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'failed', 'Empty or invalid JSON from upstream.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid response format from bank list service.', 'vietqr-generator' ),
				),
				500
			);
		}

		// Cache for 1 hour
		set_transient( $cache_key, $data, 3600 );
		VietQR_DB::log( $ip_for_log, $user_id, 'bank_list', 'success', 'Bank list fetched successfully.' );

		return new WP_REST_Response( $data, 200 );
	}

	public function handle_generate_qr( WP_REST_Request $request ) {
		$ip         = $this->get_client_ip();
		$ip_for_log = $ip ?: 'unknown';
		$user_id    = $this->get_user_identifier();

		// 1. Nonce verification
		if ( ! $this->verify_nonce( $request ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'blocked', 'Invalid or missing REST Nonce.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Security check failed. Please refresh the page.', 'vietqr-generator' ),
				),
				403
			);
		}

		// 2. IP Blocklist check
		if ( $ip && $this->is_ip_blocked( $ip ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'blocked', 'Blocked IP address attempted QR generation.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Access denied. Your IP has been blocked.', 'vietqr-generator' ),
				),
				403
			);
		}

		// 3. Rate limiting check
		if ( $ip && $this->is_rate_limited( $ip ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'rate_limited', 'Rate limit exceeded.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Too many requests. Please slow down.', 'vietqr-generator' ),
				),
				429
			);
		}

		$account_no   = trim( (string) $request->get_param( 'accountNo' ) );
		$account_name = trim( (string) $request->get_param( 'accountName' ) );
		$acq_id       = trim( (string) $request->get_param( 'acqId' ) );
		$amount       = $request->get_param( 'amount' );
		$add_info     = trim( (string) ( $request->get_param( 'addInfo' ) ?? '' ) );

		if ( empty( $account_no ) || empty( $account_name ) || empty( $acq_id ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'failed', 'Missing required fields.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Bank, account number, and account name are required.', 'vietqr-generator' ),
				),
				400
			);
		}

		$payload = array(
			'accountNo'   => $account_no,
			'accountName' => $account_name,
			'acqId'       => $acq_id,
			'amount'      => $amount,
			'addInfo'     => $add_info ?: null,
			'format'      => 'text',
			'template'    => 'compact',
		);

		$webhook_url = get_option( 'vietqr_generate_webhook', 'https://auto.dpsmedia.vn/webhook/qrdpsmedia' );
		$response    = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => json_encode( $payload ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'failed', $response->get_error_message() );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to communicate with QR code service.', 'vietqr-generator' ),
				),
				500
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$img = ( $data && ( $data['qrCodeBase64'] ?? ( $data['data']['qrCodeBase64'] ?? '' ) ) ) ?: '';

		if ( empty( $img ) ) {
			VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'failed', 'Upstream returned invalid QR image.' );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Could not generate valid QR code from provider response.', 'vietqr-generator' ),
				),
				500
			);
		}

		VietQR_DB::log( $ip_for_log, $user_id, 'generate_qr', 'success', "QR generated for BIN: {$acq_id}, Acc: {$account_no}" );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'qrCodeBase64'   => $img,
				'nonce'          => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	private function verify_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private function get_user_identifier(): string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return $user->user_email ?: $user->user_login;
		}
		return 'Guest';
	}

	private function get_client_ip(): ?string {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

		if ( ! get_option( 'vietqr_trust_proxies', false ) ) {
			return $remote_addr ?: null;
		}

		$trusted_proxies_str = get_option( 'vietqr_trusted_proxies', '' );
		$trusted_proxies     = array_filter( array_map( 'trim', explode( "\n", $trusted_proxies_str ) ) );

		if ( ! empty( $trusted_proxies ) && ! in_array( $remote_addr, $trusted_proxies, true ) ) {
			return $remote_addr ?: null;
		}

		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'HTTP_X_REAL_IP',
		);

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$ip = $_SERVER[ $key ];
			if ( strpos( $ip, ',' ) !== false ) {
				$parts = explode( ',', $ip );
				$ip    = trim( $parts[0] );
			}

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return $remote_addr ?: null;
	}

	private function is_ip_blocked( string $ip ): bool {
		$blocklist = get_option( 'vietqr_blocked_ips', array() );
		if ( ! is_array( $blocklist ) || empty( $blocklist[ $ip ] ) ) {
			return false;
		}

		$entry      = $blocklist[ $ip ];
		$expires_at = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;

		if ( $expires_at > 0 && $expires_at <= time() ) {
			unset( $blocklist[ $ip ] );
			update_option( 'vietqr_blocked_ips', $blocklist, false );
			return false;
		}

		return true;
	}

	private function is_rate_limited( string $ip ): bool {
		$rate_limit_count = intval( get_option( 'vietqr_rate_limit_count', 15 ) );
		$rate_limit_time  = intval( get_option( 'vietqr_rate_limit_time', 60 ) );

		if ( $rate_limit_count <= 0 || $rate_limit_time <= 0 ) {
			return false;
		}

		$transient_key = 'vietqr_rate_' . md5( $ip );
		$requests      = (int) get_transient( $transient_key );

		if ( $requests >= $rate_limit_count ) {
			return true;
		}

		set_transient( $transient_key, $requests + 1, $rate_limit_time );
		return false;
	}
}
