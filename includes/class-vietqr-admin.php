<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VietQR_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_vietqr_purge_logs', array( $this, 'handle_purge_logs' ) );
	}

	public function add_admin_menu(): void {
		add_options_page(
			__( 'VietQR Generator Settings', 'vietqr-generator' ),
			__( 'VietQR Generator', 'vietqr-generator' ),
			'manage_options',
			'vietqr-generator-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		// General Settings
		register_setting( 'vietqr_general_group', 'vietqr_generator_client_id', 'sanitize_text_field' );
		register_setting( 'vietqr_general_group', 'vietqr_generator_api_key', 'sanitize_text_field' );
		register_setting( 'vietqr_general_group', 'vietqr_google_client_id', 'sanitize_text_field' );
		register_setting( 'vietqr_general_group', 'vietqr_require_login', 'rest_sanitize_boolean' );
		register_setting( 'vietqr_general_group', 'vietqr_generate_webhook', 'esc_url_raw' );
		register_setting( 'vietqr_general_group', 'vietqr_bank_list_webhook', 'esc_url_raw' );

		// Security Settings
		register_setting( 'vietqr_security_group', 'vietqr_rate_limit_max', 'absint' );
		register_setting( 'vietqr_security_group', 'vietqr_rate_limit_window', 'absint' );
		register_setting( 'vietqr_security_group', 'vietqr_ip_blocklist', array( $this, 'sanitize_ip_blocklist' ) );
	}

	public function sanitize_ip_blocklist( $input ): string {
		if ( empty( $input ) ) {
			return '';
		}

		$lines = explode( "\n", str_replace( "\r", '', (string) $input ) );
		$valid_ips = array();

		foreach ( $lines as $line ) {
			$ip = trim( $line );
			if ( ! empty( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$valid_ips[] = $ip;
			}
		}

		return implode( "\n", array_unique( $valid_ips ) );
	}

	public function handle_purge_logs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'vietqr-generator' ) );
		}

		check_admin_referer( 'vietqr_purge_logs_action', 'vietqr_purge_logs_nonce' );

		$type = sanitize_text_field( $_POST['purge_type'] ?? '' );
		if ( 'all' === $type ) {
			VietQR_DB::purge_all_logs();
			add_settings_error( 'vietqr_messages', 'vietqr_message', __( 'All request logs have been purged.', 'vietqr-generator' ), 'updated' );
		} else {
			$purged = VietQR_DB::purge_old_logs( 30 );
			/* translators: %d: number of logs purged */
			add_settings_error( 'vietqr_messages', 'vietqr_message', sprintf( __( '%d old logs (older than 30 days) purged.', 'vietqr-generator' ), $purged ), 'updated' );
		}

		wp_redirect( admin_url( 'options-general.php?page=vietqr-generator-settings&tab=logs' ) );
		exit;
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
		settings_errors( 'vietqr_messages' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VietQR Generator Settings & Administration', 'vietqr-generator' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=vietqr-generator-settings&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General Settings', 'vietqr-generator' ); ?>
				</a>
				<a href="?page=vietqr-generator-settings&tab=security" class="nav-tab <?php echo 'security' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Security & IP Blocklist', 'vietqr-generator' ); ?>
				</a>
				<a href="?page=vietqr-generator-settings&tab=logs" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Request Logs', 'vietqr-generator' ); ?>
				</a>
			</h2>

			<?php
			if ( 'general' === $active_tab ) {
				$this->render_general_tab();
			} elseif ( 'security' === $active_tab ) {
				$this->render_security_tab();
			} elseif ( 'logs' === $active_tab ) {
				$this->render_logs_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_general_tab(): void {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'vietqr_general_group' );
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vietqr_generator_client_id"><?php esc_html_e( 'VietQR Client ID', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="text" id="vietqr_generator_client_id" name="vietqr_generator_client_id" value="<?php echo esc_attr( get_option( 'vietqr_generator_client_id', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your Client ID from My VietQR portal (or set in vietqr-config.php).', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_generator_api_key"><?php esc_html_e( 'VietQR API Key', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="password" id="vietqr_generator_api_key" name="vietqr_generator_api_key" value="<?php echo esc_attr( get_option( 'vietqr_generator_api_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Your API Key from My VietQR portal.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_google_client_id"><?php esc_html_e( 'Google Client ID', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="text" id="vietqr_google_client_id" name="vietqr_google_client_id" value="<?php echo esc_attr( get_option( 'vietqr_google_client_id', '' ) ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Google OAuth 2.0 Web Client ID for Google Sign-In authentication.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require Google Authentication', 'vietqr-generator' ); ?></th>
					<td>
						<label for="vietqr_require_login">
							<input type="checkbox" id="vietqr_require_login" name="vietqr_require_login" value="1" <?php checked( 1, get_option( 'vietqr_require_login', 0 ) ); ?> />
							<?php esc_html_e( 'Require users to sign in with Google before generating QR codes.', 'vietqr-generator' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_generate_webhook"><?php esc_html_e( 'QR Generate Webhook URL', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="url" id="vietqr_generate_webhook" name="vietqr_generate_webhook" value="<?php echo esc_attr( get_option( 'vietqr_generate_webhook', 'https://auto.dpsmedia.vn/webhook/qrdpsmedia' ) ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Upstream server-side QR generation endpoint URL.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_bank_list_webhook"><?php esc_html_e( 'Bank List Webhook URL', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="url" id="vietqr_bank_list_webhook" name="vietqr_bank_list_webhook" value="<?php echo esc_attr( get_option( 'vietqr_bank_list_webhook', 'https://auto.dpsmedia.vn/webhook/banklistdpsmedia' ) ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Upstream bank list service endpoint URL.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_security_tab(): void {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'vietqr_security_group' );
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vietqr_rate_limit_max"><?php esc_html_e( 'Rate Limit Max Requests', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="number" id="vietqr_rate_limit_max" name="vietqr_rate_limit_max" value="<?php echo esc_attr( get_option( 'vietqr_rate_limit_max', 10 ) ); ?>" class="small-text" min="1" max="100" />
						<p class="description"><?php esc_html_e( 'Maximum allowed QR generation requests per IP within the rate window.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_rate_limit_window"><?php esc_html_e( 'Rate Limit Window (seconds)', 'vietqr-generator' ); ?></label></th>
					<td>
						<input type="number" id="vietqr_rate_limit_window" name="vietqr_rate_limit_window" value="<?php echo esc_attr( get_option( 'vietqr_rate_limit_window', 60 ) ); ?>" class="small-text" min="10" max="3600" />
						<p class="description"><?php esc_html_e( 'Time window in seconds for rate limiting calculation.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vietqr_ip_blocklist"><?php esc_html_e( 'IP Blocklist', 'vietqr-generator' ); ?></label></th>
					<td>
						<textarea id="vietqr_ip_blocklist" name="vietqr_ip_blocklist" rows="8" class="large-text code"><?php echo esc_textarea( get_option( 'vietqr_ip_blocklist', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Enter IP addresses to block (one per line). Blocked IPs will be denied access to API endpoints.', 'vietqr-generator' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_logs_tab(): void {
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit  = 20;
		$offset = ( $paged - 1 ) * $limit;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		$status = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';

		$logs       = VietQR_DB::get_logs( $limit, $offset, $search, $status );
		$total_logs = VietQR_DB::get_logs_count( $search, $status );
		$total_pages = ceil( $total_logs / $limit );
		?>
		<div class="vietqr-logs-toolbar" style="margin: 15px 0; display: flex; justify-content: space-between; align-items: center;">
			<form method="get" style="display: flex; gap: 10px;">
				<input type="hidden" name="page" value="vietqr-generator-settings" />
				<input type="hidden" name="tab" value="logs" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search IP, User, Action...', 'vietqr-generator' ); ?>" />
				<select name="status_filter">
					<option value=""><?php esc_html_e( 'All Statuses', 'vietqr-generator' ); ?></option>
					<option value="success" <?php selected( $status, 'success' ); ?>><?php esc_html_e( 'Success', 'vietqr-generator' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'vietqr-generator' ); ?></option>
					<option value="blocked" <?php selected( $status, 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'vietqr-generator' ); ?></option>
					<option value="rate_limited" <?php selected( $status, 'rate_limited' ); ?>><?php esc_html_e( 'Rate Limited', 'vietqr-generator' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'vietqr-generator' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to purge logs?', 'vietqr-generator' ); ?>');">
				<input type="hidden" name="action" value="vietqr_purge_logs" />
				<?php wp_nonce_field( 'vietqr_purge_logs_action', 'vietqr_purge_logs_nonce' ); ?>
				<button type="submit" name="purge_type" value="old" class="button"><?php esc_html_e( 'Purge >30 Days', 'vietqr-generator' ); ?></button>
				<button type="submit" name="purge_type" value="all" class="button button-link-delete"><?php esc_html_e( 'Purge All Logs', 'vietqr-generator' ); ?></button>
			</form>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 140px;"><?php esc_html_e( 'Timestamp', 'vietqr-generator' ); ?></th>
					<th style="width: 130px;"><?php esc_html_e( 'IP Address', 'vietqr-generator' ); ?></th>
					<th style="width: 150px;"><?php esc_html_e( 'User', 'vietqr-generator' ); ?></th>
					<th style="width: 110px;"><?php esc_html_e( 'Action', 'vietqr-generator' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'Status', 'vietqr-generator' ); ?></th>
					<th><?php esc_html_e( 'Details', 'vietqr-generator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No logs found.', 'vietqr-generator' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log['created_at'] ); ?></td>
							<td><code><?php echo esc_html( $log['ip_address'] ); ?></code></td>
							<td><?php echo esc_html( $log['user_identifier'] ); ?></td>
							<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
							<td>
								<?php
								$status_colors = array(
									'success'      => 'color: green; font-weight: bold;',
									'failed'       => 'color: red;',
									'blocked'      => 'color: darkred; font-weight: bold;',
									'rate_limited' => 'color: orange;',
								);
								$style = $status_colors[ $log['status'] ] ?? '';
								?>
								<span style="<?php echo esc_attr( $style ); ?>"><?php echo esc_html( $log['status'] ); ?></span>
							</td>
							<td><?php echo esc_html( $log['details'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $total_pages,
						'current'   => $paged,
					) );
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}
}
