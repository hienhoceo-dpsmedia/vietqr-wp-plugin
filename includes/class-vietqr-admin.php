<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VietQR_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_vietqr_block_ip', array( $this, 'handle_block_ip' ) );
		add_action( 'admin_post_vietqr_unblock_ip', array( $this, 'handle_unblock_ip' ) );
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'VietQR Generator Settings', 'vietqr-generator' ),
			__( 'VietQR Generator', 'vietqr-generator' ),
			'manage_options',
			'vietqr-generator',
			array( $this, 'render_admin_page' ),
			'dashicons-qr',
			81
		);
	}

	public function register_settings(): void {
		register_setting( 'vietqr_options_group', 'vietqr_google_client_id', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'vietqr_options_group', 'vietqr_require_login', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		register_setting( 'vietqr_options_group', 'vietqr_bank_list_webhook', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://auto.dpsmedia.vn/webhook/banklistdpsmedia',
		) );

		register_setting( 'vietqr_options_group', 'vietqr_generate_webhook', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://auto.dpsmedia.vn/webhook/qrdpsmedia',
		) );

		register_setting( 'vietqr_options_group', 'vietqr_rate_limit_count', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 15,
		) );

		register_setting( 'vietqr_options_group', 'vietqr_rate_limit_time', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 60,
		) );

		register_setting( 'vietqr_options_group', 'vietqr_trust_proxies', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		register_setting( 'vietqr_options_group', 'vietqr_trusted_proxies', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VietQR Generator Security & Settings', 'vietqr-generator' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=vietqr-generator&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'vietqr-generator' ); ?>
				</a>
				<a href="?page=vietqr-generator&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Logs & Security', 'vietqr-generator' ); ?>
				</a>
			</h2>

			<?php
			if ( $active_tab === 'logs' ) {
				$this->render_logs_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_settings_tab(): void {
		?>
		<form method="post" action="options.php" style="margin-top: 20px;">
			<?php
			settings_fields( 'vietqr_options_group' );
			do_settings_sections( 'vietqr_options_group' );
			?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="vietqr_google_client_id"><?php esc_html_e( 'Google Client ID', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="text" id="vietqr_google_client_id" name="vietqr_google_client_id" value="<?php echo esc_attr( get_option( 'vietqr_google_client_id', '' ) ); ?>" class="large-text code" placeholder="xxxxxx.apps.googleusercontent.com">
						<p class="description"><?php esc_html_e( 'OAuth 2.0 Web Client ID from Google Cloud Console for Google Sign-In.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentication Requirement', 'vietqr-generator' ); ?></th>
					<td>
						<label for="vietqr_require_login">
							<input type="checkbox" id="vietqr_require_login" name="vietqr_require_login" value="1" <?php checked( 1, get_option( 'vietqr_require_login', false ) ); ?>>
							<?php esc_html_e( 'Require users to be logged in (or Sign In with Google) before generating VietQR codes.', 'vietqr-generator' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_bank_list_webhook"><?php esc_html_e( 'Bank List Webhook URL', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="url" id="vietqr_bank_list_webhook" name="vietqr_bank_list_webhook" value="<?php echo esc_attr( get_option( 'vietqr_bank_list_webhook', 'https://auto.dpsmedia.vn/webhook/banklistdpsmedia' ) ); ?>" class="regular-text" required>
						<p class="description"><?php esc_html_e( 'Upstream n8n webhook URL used to fetch bank metadata.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_generate_webhook"><?php esc_html_e( 'QR Generate Webhook URL', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="url" id="vietqr_generate_webhook" name="vietqr_generate_webhook" value="<?php echo esc_attr( get_option( 'vietqr_generate_webhook', 'https://auto.dpsmedia.vn/webhook/qrdpsmedia' ) ); ?>" class="regular-text" required>
						<p class="description"><?php esc_html_e( 'Upstream n8n webhook URL used to generate VietQR Base64 images.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_rate_limit_count"><?php esc_html_e( 'Rate Limit Count', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="number" id="vietqr_rate_limit_count" name="vietqr_rate_limit_count" value="<?php echo esc_attr( get_option( 'vietqr_rate_limit_count', 15 ) ); ?>" class="small-text" min="1">
						<?php esc_html_e( 'requests per window', 'vietqr-generator' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_rate_limit_time"><?php esc_html_e( 'Rate Limit Window (seconds)', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="number" id="vietqr_rate_limit_time" name="vietqr_rate_limit_time" value="<?php echo esc_attr( get_option( 'vietqr_rate_limit_time', 60 ) ); ?>" class="small-text" min="1">
						<?php esc_html_e( 'seconds', 'vietqr-generator' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trust Proxy Headers', 'vietqr-generator' ); ?></th>
					<td>
						<label for="vietqr_trust_proxies">
							<input type="checkbox" id="vietqr_trust_proxies" name="vietqr_trust_proxies" value="1" <?php checked( 1, get_option( 'vietqr_trust_proxies', false ) ); ?>>
							<?php esc_html_e( 'Enable detection of real client IP from Cloudflare or reverse proxy headers (HTTP_CF_CONNECTING_IP, HTTP_X_FORWARDED_FOR).', 'vietqr-generator' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_trusted_proxies"><?php esc_html_e( 'Trusted Proxy IP List', 'vietqr-generator' ); ?></label></th>
					<td>
						<textarea id="vietqr_trusted_proxies" name="vietqr_trusted_proxies" rows="4" class="large-text code"><?php echo esc_textarea( get_option( 'vietqr_trusted_proxies', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One IP per line. Leave empty to allow any upstream reverse proxy header if Trust Proxy Headers is checked.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_logs_tab(): void {
		$page    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$limit   = 20;
		$offset  = ( $page - 1 ) * $limit;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

		$logs       = VietQR_DB::get_logs( $limit, $offset, $search );
		$total_logs = VietQR_DB::count_logs( $search );
		$total_pages = ceil( $total_logs / $limit );

		$blocked_ips = get_option( 'vietqr_blocked_ips', array() );
		?>
		<div style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Blocked IP Addresses', 'vietqr-generator' ); ?></h2>
			<?php if ( empty( $blocked_ips ) ) : ?>
				<p><em><?php esc_html_e( 'No IP addresses are currently blocked.', 'vietqr-generator' ); ?></em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'IP Address', 'vietqr-generator' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'vietqr-generator' ); ?></th>
							<th><?php esc_html_e( 'Blocked At', 'vietqr-generator' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'vietqr-generator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blocked_ips as $ip => $data ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $ip ); ?></strong></td>
								<td><?php echo esc_html( $data['reason'] ?? 'Manual Block' ); ?></td>
								<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $data['blocked_at'] ?? time() ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'vietqr_unblock_ip_nonce' ); ?>
										<input type="hidden" name="action" value="vietqr_unblock_ip">
										<input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>">
										<button type="submit" class="button button-small button-secondary"><?php esc_html_e( 'Unblock IP', 'vietqr-generator' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top: 30px;"><?php esc_html_e( 'Request Logs', 'vietqr-generator' ); ?></h2>
			<form method="get" style="margin-bottom: 15px;">
				<input type="hidden" name="page" value="vietqr-generator">
				<input type="hidden" name="tab" value="logs">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by IP or status...', 'vietqr-generator' ); ?>">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter Logs', 'vietqr-generator' ); ?>">
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 60px;">ID</th>
						<th><?php esc_html_e( 'Time', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'User', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'Action', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'Message / Details', 'vietqr-generator' ); ?></th>
						<th><?php esc_html_e( 'Security Action', 'vietqr-generator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No request logs found.', 'vietqr-generator' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<?php
							$is_blocked = isset( $blocked_ips[ $log['ip_address'] ] );
							$status_color = '#0073aa';
							if ( 'success' === $log['status'] ) {
								$status_color = '#46b450';
							} elseif ( 'blocked' === $log['status'] || 'rate_limited' === $log['status'] ) {
								$status_color = '#dc3232';
							} elseif ( 'failed' === $log['status'] ) {
								$status_color = '#ffb900';
							}
							?>
							<tr>
								<td><?php echo esc_html( $log['id'] ); ?></td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
								<td><strong><?php echo esc_html( $log['ip_address'] ); ?></strong></td>
								<td><?php echo esc_html( $log['user_identifier'] ); ?></td>
								<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
								<td><span style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;"><?php echo esc_html( strtoupper( $log['status'] ) ); ?></span></td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
								<td>
									<?php if ( $is_blocked ) : ?>
										<span class="dashicons dashicons-lock" style="color: #dc3232;" title="IP Blocked"></span> <em>Blocked</em>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'vietqr_block_ip_nonce' ); ?>
											<input type="hidden" name="action" value="vietqr_block_ip">
											<input type="hidden" name="ip" value="<?php echo esc_attr( $log['ip_address'] ); ?>">
											<button type="submit" class="button button-small button-link-delete" onclick="return confirm('Block this IP address?');"><?php esc_html_e( 'Block IP', 'vietqr-generator' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 15px;">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $page,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_block_ip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'vietqr_block_ip_nonce' );

		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( $_POST['ip'] ) : '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$blocked = get_option( 'vietqr_blocked_ips', array() );
			$blocked[ $ip ] = array(
				'reason'     => 'Manual admin block',
				'blocked_at' => time(),
			);
			update_option( 'vietqr_blocked_ips', $blocked, false );
		}

		wp_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=vietqr-generator&tab=logs' ) );
		exit;
	}

	public function handle_unblock_ip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'vietqr_unblock_ip_nonce' );

		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( $_POST['ip'] ) : '';
		if ( $ip ) {
			$blocked = get_option( 'vietqr_blocked_ips', array() );
			unset( $blocked[ $ip ] );
			update_option( 'vietqr_blocked_ips', $blocked, false );
		}

		wp_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=vietqr-generator&tab=logs' ) );
		exit;
	}
}
