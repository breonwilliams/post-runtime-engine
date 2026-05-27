<?php
/**
 * Cowork connector admin page.
 *
 * Lives under Post Runtime → Connector. Provides the four-step setup flow:
 *
 *   1. Enable the connector (toggle)
 *   2. Generate Application Password (one-click; password shown once)
 *   3. Copy the one-line bash command and paste into Terminal — installs
 *      the MCP server JS file into ~/post-runtime-mcp/ and registers it
 *      with Claude Desktop's config
 *   4. Restart Claude Desktop — connector becomes available in Cowork
 *
 * State delegates to PRE_Connector_Settings and WordPress core's
 * WP_Application_Passwords. This class holds no state itself.
 *
 * Mirrors FRE_Connector_Admin's pattern intentionally — the user-facing
 * setup is identical across Promptless CPT Pages, Form Runtime Engine,
 * and Promptless WP, so site operators wire each connector the same way.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 *
 * Justification: All AJAX handlers in this class call verify_ajax() at
 * the top, which runs check_ajax_referer() + capability checks before
 * any $_POST data is read. Plugin Check's static analyzer cannot trace
 * the verification across that helper-method boundary.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector admin page coordinator.
 */
class PRE_Connector_Admin {

	const MENU_SLUG    = 'post-runtime-connector';
	const NONCE_ACTION = 'pre_connector_nonce';

	/**
	 * Hook suffix for the connector submenu page. Captured at registration
	 * so admin_enqueue_scripts can gate asset loading to just this page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Wire admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers — gated behind nonce + capability check.
		add_action( 'wp_ajax_pre_connector_toggle_enabled', array( $this, 'ajax_toggle_enabled' ) );
		add_action( 'wp_ajax_pre_connector_generate_password', array( $this, 'ajax_generate_password' ) );
		add_action( 'wp_ajax_pre_connector_revoke_password', array( $this, 'ajax_revoke_password' ) );

		// MCP script download. Intentionally public (no auth) — the served
		// file is a static JavaScript MCP server with no embedded
		// secrets; it reads credentials from environment variables at
		// runtime. Keeping the endpoint unauthenticated lets the bash
		// setup command curl it without juggling cookies or tokens.
		add_action( 'wp_ajax_pre_download_connector', array( $this, 'ajax_download_connector' ) );
		add_action( 'wp_ajax_nopriv_pre_download_connector', array( $this, 'ajax_download_connector' ) );
	}

	/**
	 * Register submenu under Post Runtime.
	 */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			PRE_Admin::MENU_SLUG,
			__( 'Connector', 'promptless-cpt-pages' ),
			__( 'Connector', 'promptless-cpt-pages' ),
			PRE_Capabilities::MANAGE_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue connector admin CSS + JS — only on the connector page.
	 *
	 * @param string $hook_suffix Current admin page hook (passed by WP).
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$plugin_url = plugins_url( '', dirname( __DIR__, 2 ) . '/post-runtime-engine.php' );

		wp_enqueue_style(
			'pre-connector-admin',
			$plugin_url . '/assets/css/connector-admin.css',
			array(),
			PRE_VERSION
		);

		wp_enqueue_script(
			'pre-connector-admin',
			$plugin_url . '/assets/js/connector-admin.js',
			array(),
			PRE_VERSION,
			true
		);

		wp_localize_script(
			'pre-connector-admin',
			'preConnectorAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( self::NONCE_ACTION ),
				'connectorScriptUrl' => admin_url( 'admin-ajax.php?action=pre_download_connector' ),
				'siteUrl'            => home_url(),
				'i18n'               => array(
					'enabled'       => __( 'Enabled.', 'promptless-cpt-pages' ),
					'disabled'      => __( 'Disabled.', 'promptless-cpt-pages' ),
					'generating'    => __( 'Generating...', 'promptless-cpt-pages' ),
					'configured'    => __( 'Configured', 'promptless-cpt-pages' ),
					'regenerate'    => __( 'Regenerate Connection', 'promptless-cpt-pages' ),
					'copied'        => __( 'Copied', 'promptless-cpt-pages' ),
					'revokeConfirm' => __( 'Revoke the connector App Password? Claude Cowork will lose access immediately.', 'promptless-cpt-pages' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-cpt-pages' ) );
		}

		$is_enabled    = PRE_Connector_Settings::is_enabled();
		$configured_at = PRE_Connector_Settings::get_user_configured_at();

		// Use WordPress's canonical app-password availability check (returns
		// true on HTTPS sites OR when WP_ENVIRONMENT_TYPE is 'local'). Local
		// by Flywheel / wp-env / LocalWP set 'local' by default, so dev
		// environments work out of the box. A bare is_ssl() check would
		// incorrectly block dev workflows.
		$app_passwords_available = wp_is_application_passwords_available();

		$ajax_nonce           = wp_create_nonce( self::NONCE_ACTION );
		$rest_base_url        = esc_url( rest_url( PRE_REST_NAMESPACE . '/' . PRE_REST_BASE ) );
		$site_url             = home_url();
		$connector_script_url = admin_url( 'admin-ajax.php?action=pre_download_connector' );
		$mcp_setup_url        = PRE_PLUGIN_URL . 'docs/MCP_CONNECTOR_SETUP.md';
		$spec_url             = PRE_PLUGIN_URL . 'docs/CONNECTOR_SPEC.md';
		$user                 = wp_get_current_user();

		?>
		<div class="wrap pre-connector-settings">
			<h1><?php esc_html_e( 'The Post Runtime Connector', 'promptless-cpt-pages' ); ?></h1>
			<p class="pre-connector-subtitle">
				<?php esc_html_e( 'Connect Claude Desktop to your WordPress site so it can manage custom post types and structured content.', 'promptless-cpt-pages' ); ?>
			</p>

			<?php if ( ! $app_passwords_available ) : ?>
				<!-- App password availability banner. Renders the UI below
				     even when prerequisites are missing — user can read
				     through the steps and understand what they'd be
				     configuring. Banner explains the fix for both
				     production (HTTPS) and dev (WP_ENVIRONMENT_TYPE)
				     contexts. -->
				<div class="notice notice-warning" style="margin: 12px 0 20px;">
					<p><strong><?php esc_html_e( 'Application passwords not available on this site.', 'promptless-cpt-pages' ); ?></strong>
					<?php esc_html_e( "WordPress requires either HTTPS or a local environment to issue application passwords. Until that's set up, the \"Generate Connection\" button will return an error.", 'promptless-cpt-pages' ); ?></p>
					<ul style="margin: 6px 0 6px 24px; list-style: disc;">
						<li><?php echo wp_kses( __( '<strong>On a production site:</strong> enable HTTPS / install an SSL certificate.', 'promptless-cpt-pages' ), array( 'strong' => array() ) ); ?></li>
						<li><?php echo wp_kses( __( "<strong>For local development:</strong> add <code>define('WP_ENVIRONMENT_TYPE', 'local');</code> to your <code>wp-config.php</code>. Most local environments (Local by Flywheel, wp-env, LocalWP) set this automatically.", 'promptless-cpt-pages' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Connection Status card. Status pill + inline kill-switch toggle.
			     Tucks the security toggle next to the visual status so the setup
			     flow below stays at 3 clean steps. -->
			<div class="pre-connector-card" id="pre-connector-status-card">
				<h2><?php esc_html_e( 'Connection Status', 'promptless-cpt-pages' ); ?></h2>
				<div class="pre-connector-status-row">
					<span class="pre-connector-status-badge <?php echo $configured_at > 0 ? 'pre-connector-status-active' : 'pre-connector-status-inactive'; ?>" id="pre-connector-status-pill">
						<?php echo $configured_at > 0 ? esc_html__( 'Configured', 'promptless-cpt-pages' ) : esc_html__( 'Not Connected', 'promptless-cpt-pages' ); ?>
					</span>
					<label class="pre-connector-killswitch">
						<input
							type="checkbox"
							id="pre-connector-enabled"
							<?php checked( $is_enabled ); ?>
							data-ajax-action="pre_connector_toggle_enabled"
						>
						<span><?php esc_html_e( 'Allow Claude Cowork to call this site', 'promptless-cpt-pages' ); ?></span>
						<span class="pre-connector-toggle-status" id="pre-enabled-status" aria-live="polite"></span>
					</label>
				</div>
				<p class="pre-connector-status-help">
					<?php if ( $configured_at > 0 ) : ?>
						<?php
						printf(
							/* translators: %s: human-readable last-configured time */
							esc_html__( 'Last configured: %s. Generate a new connection below if you need to reconfigure.', 'promptless-cpt-pages' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Follow the steps below to connect Claude Desktop to your site.', 'promptless-cpt-pages' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<!-- Step 1: Generate Connection -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 1: Generate Connection', 'promptless-cpt-pages' ); ?></h2>
				<p><?php esc_html_e( 'This creates a secure application password that allows Claude to communicate with your site. Any existing connection will be replaced.', 'promptless-cpt-pages' ); ?></p>
				<p>
					<button type="button" id="pre-generate-password-btn" class="button button-primary">
						<?php
						echo $configured_at > 0
							? esc_html__( 'Regenerate Connection', 'promptless-cpt-pages' )
							: esc_html__( 'Generate Connection', 'promptless-cpt-pages' );
						?>
					</button>
					<?php if ( $configured_at > 0 ) : ?>
						<button type="button" id="pre-revoke-password-btn" class="button">
							<?php esc_html_e( 'Revoke Connection', 'promptless-cpt-pages' ); ?>
						</button>
					<?php endif; ?>
				</p>

				<!-- Hidden until generated -->
				<div id="pre-credential-display" class="pre-connector-success-notice" style="display:none;">
					<p><strong><?php esc_html_e( 'Connection generated successfully!', 'promptless-cpt-pages' ); ?></strong> <?php esc_html_e( 'Now proceed to Step 2.', 'promptless-cpt-pages' ); ?></p>
				</div>
			</div>

			<!-- Step 2: Run Setup Command -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 2: Run Setup Command', 'promptless-cpt-pages' ); ?></h2>
				<p><?php esc_html_e( 'Copy the command below and paste it into', 'promptless-cpt-pages' ); ?> <strong><?php esc_html_e( 'Terminal', 'promptless-cpt-pages' ); ?></strong> <?php esc_html_e( 'on your Mac. This automatically installs and configures the Post Runtime Connector.', 'promptless-cpt-pages' ); ?></p>

				<div class="pre-connector-requirements">
					<strong><?php esc_html_e( 'Requirements:', 'promptless-cpt-pages' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'macOS with Terminal', 'promptless-cpt-pages' ); ?></li>
						<li><?php esc_html_e( 'Node.js installed (v14 or higher)', 'promptless-cpt-pages' ); ?></li>
						<li><?php esc_html_e( 'Claude Desktop app installed', 'promptless-cpt-pages' ); ?></li>
					</ul>
				</div>

				<!-- Setup command is only shown after the in-session Generate
				     click — the plaintext password is one-shot and can never
				     be re-displayed on a page refresh. Visitors who land
				     here with a stored configured_at but no in-memory
				     password see the placeholder telling them to
				     Regenerate. Same UX as Promptless. -->
				<div id="pre-setup-command-container" style="display:none;">
					<div class="pre-connector-code-block">
						<pre id="pre-setup-command"></pre>
						<button type="button" class="button pre-connector-copy-btn" id="pre-copy-setup-command"><?php esc_html_e( 'Copy Command', 'promptless-cpt-pages' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'After running the command, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next session.', 'promptless-cpt-pages' ); ?></p>
				</div>

				<div id="pre-setup-command-placeholder">
					<p class="description" style="color:#999;">
						<?php if ( $configured_at > 0 ) : ?>
							<?php esc_html_e( 'Your connection is configured. To see the setup command again, click "Regenerate Connection" in Step 1.', 'promptless-cpt-pages' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Generate a connection in Step 1 first, then your setup command will appear here.', 'promptless-cpt-pages' ); ?>
						<?php endif; ?>
					</p>
				</div>
			</div>

			<!-- Step 3: Verify Connection -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 3: Verify Connection', 'promptless-cpt-pages' ); ?></h2>
				<p><?php esc_html_e( 'After running the setup command and restarting Claude Desktop, start a new conversation and type:', 'promptless-cpt-pages' ); ?></p>
				<div class="pre-connector-code-block">
					<pre><?php esc_html_e( 'List the post types managed by Promptless CPT Pages on my site.', 'promptless-cpt-pages' ); ?></pre>
				</div>
				<p><?php esc_html_e( 'Claude should respond with your registered post types, confirming the connection is active.', 'promptless-cpt-pages' ); ?></p>
			</div>

			<!-- Developer info — collapsed by default. Hides technical refs
			     (REST endpoint URL, version, spec link) that end users do not
			     need but devs may want for debugging or scripting. -->
			<details class="pre-connector-dev-info">
				<summary><?php esc_html_e( 'Developer info', 'promptless-cpt-pages' ); ?></summary>
				<dl>
					<dt><?php esc_html_e( 'REST base URL', 'promptless-cpt-pages' ); ?></dt>
					<dd><code><?php echo esc_html( $rest_base_url ); ?></code></dd>
					<dt><?php esc_html_e( 'Authenticated user', 'promptless-cpt-pages' ); ?></dt>
					<dd><code><?php echo esc_html( $user->user_login ); ?></code></dd>
					<dt><?php esc_html_e( 'Plugin version', 'promptless-cpt-pages' ); ?></dt>
					<dd><code><?php echo esc_html( PRE_VERSION ); ?></code> &middot; <?php esc_html_e( 'Data schema', 'promptless-cpt-pages' ); ?> <code><?php echo esc_html( PRE_DATA_VERSION ); ?></code></dd>
					<dt><?php esc_html_e( 'Documentation', 'promptless-cpt-pages' ); ?></dt>
					<dd>
						<a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connector specification', 'promptless-cpt-pages' ); ?></a>
						&middot;
						<a href="<?php echo esc_url( $mcp_setup_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MCP setup notes', 'promptless-cpt-pages' ); ?></a>
					</dd>
				</dl>
			</details>
		</div>

		<?php
	}

	// ---------------------------------------------------------------------
	// AJAX handlers
	// ---------------------------------------------------------------------

	/**
	 * Verify AJAX request's nonce + capability. Sends a JSON error and
	 * halts on failure. Returns normally on pass.
	 */
	private function verify_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'promptless-cpt-pages' ) ), 403 );
		}
	}

	/**
	 * AJAX: toggle the connector enable flag.
	 */
	public function ajax_toggle_enabled() {
		$this->verify_ajax();

		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
		PRE_Connector_Settings::set_enabled( $enabled );

		wp_send_json_success(
			array(
				'enabled' => $enabled,
				'message' => $enabled
					? __( 'Connector enabled.', 'promptless-cpt-pages' )
					: __( 'Connector disabled.', 'promptless-cpt-pages' ),
			)
		);
	}

	/**
	 * AJAX: generate a new App Password for the current user.
	 *
	 * Revokes any prior PRE-named credential first so each user has at
	 * most one active connector App Password at a time. The plaintext
	 * password is returned in the response for one-time display; this
	 * plugin never stores it.
	 */
	public function ajax_generate_password() {
		$this->verify_ajax();

		if ( ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application Passwords are not available on this site. Requires WordPress 5.6+ and HTTPS (or WP_ENVIRONMENT_TYPE=local for development).', 'promptless-cpt-pages' ),
				)
			);
		}

		$user_id = get_current_user_id();

		// Revoke any prior PRE-named credential for this user.
		$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $pw ) {
				if ( isset( $pw['name'] ) && PRE_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
					WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
				}
			}
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => PRE_Connector_Settings::APP_PASSWORD_NAME )
		);

		if ( is_wp_error( $created ) ) {
			wp_send_json_error( array( 'message' => $created->get_error_message() ) );
		}

		// WP returns [ $password_string, $item_metadata ].
		list( $password_string, $item ) = $created;

		PRE_Connector_Settings::mark_user_configured( $user_id );

		$current_user = wp_get_current_user();

		wp_send_json_success(
			array(
				'username' => $current_user->user_login,
				'password' => $password_string,
				'uuid'     => $item['uuid'] ?? '',
				'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', 'promptless-cpt-pages' ),
			)
		);
	}

	/**
	 * AJAX: revoke the connector App Password for the current user.
	 */
	public function ajax_revoke_password() {
		$this->verify_ajax();

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress 5.6+ is required.', 'promptless-cpt-pages' ) ) );
		}

		$user_id  = get_current_user_id();
		$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
		$count    = 0;

		if ( is_array( $existing ) ) {
			foreach ( $existing as $pw ) {
				if ( isset( $pw['name'] ) && PRE_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
					WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
					++$count;
				}
			}
		}

		PRE_Connector_Settings::clear_user_configured( $user_id );

		wp_send_json_success(
			array(
				'revoked_count' => $count,
				'message'       => sprintf(
					/* translators: %d: count of revoked app passwords */
					_n( '%d connector credential revoked.', '%d connector credentials revoked.', $count, 'promptless-cpt-pages' ),
					$count
				),
			)
		);
	}

	/**
	 * AJAX: serve the MCP connector JavaScript file.
	 *
	 * Intentionally public — no nonce, no capability check. The served
	 * file is a static JavaScript MCP server with no embedded secrets;
	 * it reads credentials from environment variables at runtime.
	 * Keeping this endpoint unauthenticated lets the bash setup command
	 * curl it without juggling cookies or tokens.
	 *
	 * Route: /wp-admin/admin-ajax.php?action=pre_download_connector
	 */
	public function ajax_download_connector() {
		$path = PRE_PLUGIN_DIR . 'includes/Connector/assets/post-runtime-connector.js';

		if ( ! file_exists( $path ) ) {
			status_header( 404 );
			echo '// Promptless CPT Pages connector script not found on this install.';
			exit;
		}

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="post-runtime-connector.js"' );
		header( 'Cache-Control: no-cache, must-revalidate' );

		// File is a static plugin-shipped JavaScript asset (the MCP connector),
		// not user-controlled input. Output must be raw JS, so it is intentionally
		// not run through esc_*; the content is plugin-controlled and has no XSS surface.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped
		echo file_get_contents( $path );
		exit;
	}
}
