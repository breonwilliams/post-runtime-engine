<?php
/**
 * Cowork connector admin page.
 *
 * Adds a "Connector" submenu under the Post Runtime Engine admin menu
 * with three controls:
 *
 *   1. Enable / disable toggle (writes pre_connector_enabled option)
 *   2. Generate Application Password button (creates a WP App Password
 *      via core's WP_Application_Passwords API, displays it once for
 *      the user to copy into their MCP client config, marks the user
 *      as "configured")
 *   3. Site-info readout (REST URL, namespace, plugin/data versions) —
 *      the values an MCP client config needs
 *
 * Mirrors FRE's connector admin UX. Differences:
 *   - No "entry-read" secondary toggle (PRE has no equivalent surface).
 *   - No call-log viewer (deferred).
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector admin page coordinator.
 */
class PRE_Connector_Admin {

	const MENU_SLUG = 'post-runtime-connector';

	const NONCE_ACTION = 'pre_connector_admin';
	const NONCE_NAME   = 'pre_connector_nonce';

	/**
	 * Wire admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_pre_connector_save', array( $this, 'handle_save_toggle' ) );
		add_action( 'admin_post_pre_connector_generate_password', array( $this, 'handle_generate_password' ) );
		add_action( 'admin_post_pre_connector_revoke_password', array( $this, 'handle_revoke_password' ) );
	}

	/**
	 * Register submenu under the Post Runtime Engine top-level menu.
	 */
	public function register_menu() {
		add_submenu_page(
			PRE_Admin::MENU_SLUG,
			__( 'Connector', 'post-runtime-engine' ),
			__( 'Connector', 'post-runtime-engine' ),
			PRE_Capabilities::MANAGE_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the connector admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-runtime-engine' ) );
		}

		$enabled         = PRE_Connector_Settings::is_enabled();
		$configured_at   = PRE_Connector_Settings::get_user_configured_at();
		$rest_base_url   = esc_url( rest_url( PRE_REST_NAMESPACE . '/' . PRE_REST_BASE ) );
		$user            = wp_get_current_user();
		$generated_creds = $this->consume_flash_credentials();
		$notice          = $this->consume_flash_notice();

		?>
		<div class="wrap pre-admin pre-connector-admin">
			<h1><?php esc_html_e( 'Post Runtime Engine — Connector', 'post-runtime-engine' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'The connector lets external tools — most notably Claude Cowork — register custom post types, define groupings, populate per-post values, and preview rendered output through a secure REST API. The connector is opt-in: enable it below, then generate an Application Password to authenticate your MCP client.', 'post-runtime-engine' ); ?>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Connector status', 'post-runtime-engine' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="pre_connector_save">

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable connector', 'post-runtime-engine' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pre_connector_enabled" value="1" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Allow external clients to call the connector REST API.', 'post-runtime-engine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When disabled, all connector endpoints return 403. Authenticated callers still need the Application Password below.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'post-runtime-engine' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Application Password', 'post-runtime-engine' ); ?></h2>

			<p class="description">
				<?php
				/* translators: 1: link to WordPress core docs on App Passwords */
				printf(
					wp_kses(
						__( 'The connector authenticates external clients using <a href="%s" target="_blank" rel="noopener">WordPress Application Passwords</a> — per-user, per-app credentials that your administrator can revoke at any time. Generate one below for use in your MCP client config; copy it immediately, since WordPress only displays it once.', 'post-runtime-engine' ),
						array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
					),
					'https://developer.wordpress.org/rest-api/frequently-asked-questions/#how-can-i-authenticate-with-the-rest-api'
				);
				?>
			</p>

			<?php if ( $generated_creds ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'New Application Password generated.', 'post-runtime-engine' ); ?></strong></p>
					<p><?php esc_html_e( 'Copy this credential now — it will not be shown again. Store it in your MCP client config or a password manager.', 'post-runtime-engine' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Username', 'post-runtime-engine' ); ?></th>
							<td><code><?php echo esc_html( $generated_creds['login'] ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Application Password', 'post-runtime-engine' ); ?></th>
							<td><code style="user-select:all;font-size:14px;letter-spacing:0.05em;"><?php echo esc_html( $generated_creds['password'] ); ?></code></td>
						</tr>
					</table>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="pre_connector_generate_password">
				<button type="submit" class="button button-primary">
					<?php
					echo $configured_at > 0
						? esc_html__( 'Regenerate Application Password', 'post-runtime-engine' )
						: esc_html__( 'Generate Application Password', 'post-runtime-engine' );
					?>
				</button>
			</form>

			<?php if ( $configured_at > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="action" value="pre_connector_revoke_password">
					<button type="submit" class="button">
						<?php esc_html_e( 'Revoke', 'post-runtime-engine' ); ?>
					</button>
				</form>
				<p class="description" style="margin-top:8px;">
					<?php
					/* translators: %s: human-readable last-configured time */
					printf(
						esc_html__( 'Last configured: %s', 'post-runtime-engine' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
					);
					?>
				</p>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Connection details', 'post-runtime-engine' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Configure your MCP client (Claude Cowork or similar) with these values.', 'post-runtime-engine' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'REST base URL', 'post-runtime-engine' ); ?></th>
					<td><code style="user-select:all;"><?php echo esc_html( $rest_base_url ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress username', 'post-runtime-engine' ); ?></th>
					<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin version', 'post-runtime-engine' ); ?></th>
					<td><?php echo esc_html( PRE_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Data version', 'post-runtime-engine' ); ?></th>
					<td><?php echo esc_html( PRE_DATA_VERSION ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle "Save settings" form submission.
	 */
	public function handle_save_toggle() {
		$this->verify_nonce_or_die();

		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'post-runtime-engine' ) );
		}

		$enabled = isset( $_POST['pre_connector_enabled'] ) && $_POST['pre_connector_enabled'] === '1';
		PRE_Connector_Settings::set_enabled( $enabled );

		$this->set_flash_notice(
			'success',
			$enabled
				? __( 'Connector enabled. External clients with a valid Application Password can now call the connector API.', 'post-runtime-engine' )
				: __( 'Connector disabled. All external requests will be rejected with 403.', 'post-runtime-engine' )
		);

		$this->redirect_to_settings();
	}

	/**
	 * Handle "Generate App Password" submission.
	 *
	 * Revokes any existing PRE-named app password for the current user
	 * before issuing a new one — keeps the per-user state to a single
	 * connector credential at a time.
	 */
	public function handle_generate_password() {
		$this->verify_nonce_or_die();

		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'post-runtime-engine' ) );
		}

		// Application Passwords requires the feature to be available.
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available() ) {
			$this->set_flash_notice(
				'error',
				__( 'Application Passwords are not available on this site. Ensure WordPress 5.6+ and HTTPS (or define WP_ENVIRONMENT_TYPE=local for development).', 'post-runtime-engine' )
			);
			$this->redirect_to_settings();
		}

		$user_id = get_current_user_id();

		// Revoke any prior PRE-named credential.
		$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $row ) {
				if ( ! empty( $row['name'] ) && $row['name'] === PRE_Connector_Settings::APP_PASSWORD_NAME ) {
					WP_Application_Passwords::delete_application_password( $user_id, $row['uuid'] );
				}
			}
		}

		$result = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => PRE_Connector_Settings::APP_PASSWORD_NAME )
		);

		if ( is_wp_error( $result ) ) {
			$this->set_flash_notice(
				'error',
				sprintf(
					/* translators: %s: error message from WordPress core */
					__( 'Could not create Application Password: %s', 'post-runtime-engine' ),
					$result->get_error_message()
				)
			);
			$this->redirect_to_settings();
		}

		// $result is array( $unhashed_password, $stored_meta ).
		list( $password ) = $result;

		$user = wp_get_current_user();

		$this->set_flash_credentials(
			array(
				'login'    => $user->user_login,
				'password' => $password,
			)
		);

		PRE_Connector_Settings::mark_user_configured( $user_id );

		$this->redirect_to_settings();
	}

	/**
	 * Handle "Revoke App Password" submission.
	 */
	public function handle_revoke_password() {
		$this->verify_nonce_or_die();

		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'post-runtime-engine' ) );
		}

		$user_id  = get_current_user_id();
		$revoked  = 0;
		$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );

		if ( is_array( $existing ) ) {
			foreach ( $existing as $row ) {
				if ( ! empty( $row['name'] ) && $row['name'] === PRE_Connector_Settings::APP_PASSWORD_NAME ) {
					WP_Application_Passwords::delete_application_password( $user_id, $row['uuid'] );
					++$revoked;
				}
			}
		}

		PRE_Connector_Settings::clear_user_configured( $user_id );

		$this->set_flash_notice(
			'success',
			$revoked > 0
				? __( 'Application Password revoked. Any MCP client using the previous credential will receive 401 on its next request.', 'post-runtime-engine' )
				: __( 'No connector Application Password was found for your account.', 'post-runtime-engine' )
		);

		$this->redirect_to_settings();
	}

	/**
	 * Verify the form nonce or wp_die() — used by all admin-post handlers.
	 */
	private function verify_nonce_or_die() {
		if (
			! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed. Refresh the page and try again.', 'post-runtime-engine' ) );
		}
	}

	/**
	 * Stash credentials in a one-shot transient so the redirect target can
	 * display them. Transient is consumed (deleted) on first read.
	 *
	 * @param array $creds {login, password}.
	 */
	private function set_flash_credentials( array $creds ) {
		set_transient( 'pre_connector_flash_creds_' . get_current_user_id(), $creds, 60 );
	}

	/**
	 * Read and clear the flash credentials transient.
	 *
	 * @return array|null {login, password} or null.
	 */
	private function consume_flash_credentials() {
		$key   = 'pre_connector_flash_creds_' . get_current_user_id();
		$creds = get_transient( $key );
		if ( ! is_array( $creds ) ) {
			return null;
		}
		delete_transient( $key );
		return $creds;
	}

	/**
	 * Stash a flash notice for the redirect target.
	 *
	 * @param string $type    'success' | 'error' | 'warning' | 'info'.
	 * @param string $message Human-readable message (HTML allowed via wp_kses_post).
	 */
	private function set_flash_notice( $type, $message ) {
		set_transient(
			'pre_connector_flash_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Read and clear the flash notice transient.
	 *
	 * @return array|null
	 */
	private function consume_flash_notice() {
		$key    = 'pre_connector_flash_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );
		return $notice;
	}

	/**
	 * Redirect back to the connector settings page and exit.
	 */
	private function redirect_to_settings() {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::MENU_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
