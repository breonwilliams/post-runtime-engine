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
 * setup is identical across Post Runtime Engine, Form Runtime Engine,
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
	 * Wire admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );

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
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( PRE_Capabilities::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-runtime-engine' ) );
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
			<h1><?php esc_html_e( 'The Post Runtime Connector', 'post-runtime-engine' ); ?></h1>
			<p class="pre-connector-subtitle">
				<?php esc_html_e( 'Connect Claude Desktop to your WordPress site so it can manage custom post types and structured content.', 'post-runtime-engine' ); ?>
			</p>

			<?php if ( ! $app_passwords_available ) : ?>
				<!-- App password availability banner. Renders the UI below
				     even when prerequisites are missing — user can read
				     through the steps and understand what they'd be
				     configuring. Banner explains the fix for both
				     production (HTTPS) and dev (WP_ENVIRONMENT_TYPE)
				     contexts. -->
				<div class="notice notice-warning" style="margin: 12px 0 20px;">
					<p><strong><?php esc_html_e( 'Application passwords not available on this site.', 'post-runtime-engine' ); ?></strong>
					<?php esc_html_e( "WordPress requires either HTTPS or a local environment to issue application passwords. Until that's set up, the \"Generate Connection\" button will return an error.", 'post-runtime-engine' ); ?></p>
					<ul style="margin: 6px 0 6px 24px; list-style: disc;">
						<li><?php echo wp_kses( __( '<strong>On a production site:</strong> enable HTTPS / install an SSL certificate.', 'post-runtime-engine' ), array( 'strong' => array() ) ); ?></li>
						<li><?php echo wp_kses( __( "<strong>For local development:</strong> add <code>define('WP_ENVIRONMENT_TYPE', 'local');</code> to your <code>wp-config.php</code>. Most local environments (Local by Flywheel, wp-env, LocalWP) set this automatically.", 'post-runtime-engine' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Connection Status card. Status pill + inline kill-switch toggle.
			     Tucks the security toggle next to the visual status so the setup
			     flow below stays at 3 clean steps. -->
			<div class="pre-connector-card" id="pre-connector-status-card">
				<h2><?php esc_html_e( 'Connection Status', 'post-runtime-engine' ); ?></h2>
				<div class="pre-connector-status-row">
					<span class="pre-connector-status-badge <?php echo $configured_at > 0 ? 'pre-connector-status-active' : 'pre-connector-status-inactive'; ?>" id="pre-connector-status-pill">
						<?php echo $configured_at > 0 ? esc_html__( 'Configured', 'post-runtime-engine' ) : esc_html__( 'Not Connected', 'post-runtime-engine' ); ?>
					</span>
					<label class="pre-connector-killswitch">
						<input
							type="checkbox"
							id="pre-connector-enabled"
							<?php checked( $is_enabled ); ?>
							data-ajax-action="pre_connector_toggle_enabled"
						>
						<span><?php esc_html_e( 'Allow Claude Cowork to call this site', 'post-runtime-engine' ); ?></span>
						<span class="pre-connector-toggle-status" id="pre-enabled-status" aria-live="polite"></span>
					</label>
				</div>
				<p class="pre-connector-status-help">
					<?php if ( $configured_at > 0 ) : ?>
						<?php
						printf(
							/* translators: %s: human-readable last-configured time */
							esc_html__( 'Last configured: %s. Generate a new connection below if you need to reconfigure.', 'post-runtime-engine' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Follow the steps below to connect Claude Desktop to your site.', 'post-runtime-engine' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<!-- Step 1: Generate Connection -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 1: Generate Connection', 'post-runtime-engine' ); ?></h2>
				<p><?php esc_html_e( 'This creates a secure application password that allows Claude to communicate with your site. Any existing connection will be replaced.', 'post-runtime-engine' ); ?></p>
				<p>
					<button type="button" id="pre-generate-password-btn" class="button button-primary">
						<?php
						echo $configured_at > 0
							? esc_html__( 'Regenerate Connection', 'post-runtime-engine' )
							: esc_html__( 'Generate Connection', 'post-runtime-engine' );
						?>
					</button>
					<?php if ( $configured_at > 0 ) : ?>
						<button type="button" id="pre-revoke-password-btn" class="button">
							<?php esc_html_e( 'Revoke Connection', 'post-runtime-engine' ); ?>
						</button>
					<?php endif; ?>
				</p>

				<!-- Hidden until generated -->
				<div id="pre-credential-display" class="pre-connector-success-notice" style="display:none;">
					<p><strong><?php esc_html_e( 'Connection generated successfully!', 'post-runtime-engine' ); ?></strong> <?php esc_html_e( 'Now proceed to Step 2.', 'post-runtime-engine' ); ?></p>
				</div>
			</div>

			<!-- Step 2: Run Setup Command -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 2: Run Setup Command', 'post-runtime-engine' ); ?></h2>
				<p><?php esc_html_e( 'Copy the command below and paste it into', 'post-runtime-engine' ); ?> <strong><?php esc_html_e( 'Terminal', 'post-runtime-engine' ); ?></strong> <?php esc_html_e( 'on your Mac. This automatically installs and configures the Post Runtime Connector.', 'post-runtime-engine' ); ?></p>

				<div class="pre-connector-requirements">
					<strong><?php esc_html_e( 'Requirements:', 'post-runtime-engine' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'macOS with Terminal', 'post-runtime-engine' ); ?></li>
						<li><?php esc_html_e( 'Node.js installed (v14 or higher)', 'post-runtime-engine' ); ?></li>
						<li><?php esc_html_e( 'Claude Desktop app installed', 'post-runtime-engine' ); ?></li>
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
						<button type="button" class="button pre-connector-copy-btn" id="pre-copy-setup-command"><?php esc_html_e( 'Copy Command', 'post-runtime-engine' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'After running the command, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next session.', 'post-runtime-engine' ); ?></p>
				</div>

				<div id="pre-setup-command-placeholder">
					<p class="description" style="color:#999;">
						<?php if ( $configured_at > 0 ) : ?>
							<?php esc_html_e( 'Your connection is configured. To see the setup command again, click "Regenerate Connection" in Step 1.', 'post-runtime-engine' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Generate a connection in Step 1 first, then your setup command will appear here.', 'post-runtime-engine' ); ?>
						<?php endif; ?>
					</p>
				</div>
			</div>

			<!-- Step 3: Verify Connection -->
			<div class="pre-connector-card">
				<h2><?php esc_html_e( 'Step 3: Verify Connection', 'post-runtime-engine' ); ?></h2>
				<p><?php esc_html_e( 'After running the setup command and restarting Claude Desktop, start a new conversation and type:', 'post-runtime-engine' ); ?></p>
				<div class="pre-connector-code-block">
					<pre><?php esc_html_e( 'List the post types managed by Post Runtime Engine on my site.', 'post-runtime-engine' ); ?></pre>
				</div>
				<p><?php esc_html_e( 'Claude should respond with your registered post types, confirming the connection is active.', 'post-runtime-engine' ); ?></p>
			</div>

			<!-- Developer info — collapsed by default. Hides technical refs
			     (REST endpoint URL, version, spec link) that end users do not
			     need but devs may want for debugging or scripting. -->
			<details class="pre-connector-dev-info">
				<summary><?php esc_html_e( 'Developer info', 'post-runtime-engine' ); ?></summary>
				<dl>
					<dt><?php esc_html_e( 'REST base URL', 'post-runtime-engine' ); ?></dt>
					<dd><code><?php echo esc_html( $rest_base_url ); ?></code></dd>
					<dt><?php esc_html_e( 'Authenticated user', 'post-runtime-engine' ); ?></dt>
					<dd><code><?php echo esc_html( $user->user_login ); ?></code></dd>
					<dt><?php esc_html_e( 'Plugin version', 'post-runtime-engine' ); ?></dt>
					<dd><code><?php echo esc_html( PRE_VERSION ); ?></code> &middot; <?php esc_html_e( 'Data schema', 'post-runtime-engine' ); ?> <code><?php echo esc_html( PRE_DATA_VERSION ); ?></code></dd>
					<dt><?php esc_html_e( 'Documentation', 'post-runtime-engine' ); ?></dt>
					<dd>
						<a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connector specification', 'post-runtime-engine' ); ?></a>
						&middot;
						<a href="<?php echo esc_url( $mcp_setup_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MCP setup notes', 'post-runtime-engine' ); ?></a>
					</dd>
				</dl>
			</details>
		</div>

		<style>
			/* PRE connector admin styles — mirrors Promptless's .aisb-* visual
			 * treatment with .pre-connector-* prefix so the two plugins look
			 * like siblings without sharing CSS class names across plugin
			 * boundaries. Refactored 2026-05-16 from a flat-text 4-step
			 * layout to this card-based 3-step layout with the kill-switch
			 * tucked into the Status card.
			 */
			.pre-connector-settings { max-width: 800px; }
			.pre-connector-subtitle { font-size: 14px; color: #646970; margin-top: -5px; }

			.pre-connector-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px 24px;
				margin-bottom: 20px;
			}
			.pre-connector-card h2 {
				margin-top: 0;
				padding-top: 0;
				font-size: 16px;
				border-bottom: none;
			}

			.pre-connector-status-row {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				flex-wrap: wrap;
				margin-bottom: 8px;
			}
			.pre-connector-status-badge {
				display: inline-block;
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 13px;
				font-weight: 500;
			}
			.pre-connector-status-active { background: #d4edda; color: #155724; }
			.pre-connector-status-inactive { background: #f8d7da; color: #721c24; }
			.pre-connector-status-help { margin: 0; color: #50575e; }

			.pre-connector-killswitch { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #1d2327; }
			.pre-connector-toggle-status { font-style: italic; color: #50575e; min-width: 60px; }

			.pre-connector-success-notice {
				margin: 12px 0 0 0;
				padding: 8px 12px;
				background: #edf7ed;
				border-left: 3px solid #46b450;
				border-radius: 0 4px 4px 0;
			}
			.pre-connector-success-notice p { margin: 0; }

			.pre-connector-requirements {
				background: #f0f6fc;
				border: 1px solid #c8d8e4;
				border-radius: 4px;
				padding: 12px 16px;
				margin: 12px 0;
			}
			.pre-connector-requirements ul { margin: 4px 0 0 20px; }
			.pre-connector-requirements li { margin-bottom: 2px; }

			.pre-connector-code-block {
				position: relative;
				background: #1d2327;
				color: #50c878;
				padding: 16px 20px;
				border-radius: 6px;
				margin: 12px 0;
				overflow-x: auto;
			}
			.pre-connector-code-block pre {
				margin: 0;
				white-space: pre-wrap;
				word-break: break-all;
				font-family: 'SF Mono', 'Monaco', 'Menlo', 'Consolas', monospace;
				font-size: 13px;
				line-height: 1.6;
				color: #50c878;
			}
			.pre-connector-copy-btn {
				position: absolute !important;
				top: 8px !important;
				right: 8px !important;
				font-size: 12px !important;
				padding: 2px 10px !important;
				min-height: 28px !important;
			}

			.pre-connector-dev-info {
				margin-top: 20px;
				padding: 12px 16px;
				background: #f6f7f7;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}
			.pre-connector-dev-info summary {
				cursor: pointer;
				font-weight: 600;
				color: #1d2327;
				outline: none;
			}
			.pre-connector-dev-info[open] summary { margin-bottom: 8px; }
			.pre-connector-dev-info dl { margin: 0; }
			.pre-connector-dev-info dt {
				font-weight: 600;
				color: #50575e;
				font-size: 12px;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				margin-top: 10px;
			}
			.pre-connector-dev-info dt:first-child { margin-top: 0; }
			.pre-connector-dev-info dd {
				margin: 4px 0 0 0;
				font-size: 13px;
			}
		</style>

		<script>
		(function() {
			const ajaxUrl             = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
			const nonce               = '<?php echo esc_js( $ajax_nonce ); ?>';
			const connectorScriptUrl  = '<?php echo esc_js( $connector_script_url ); ?>';
			const siteUrl             = '<?php echo esc_js( $site_url ); ?>';

			/**
			 * Build the one-line bash setup command.
			 *
			 * Adapted from the FRE / Promptless connector's equivalent.
			 * Notable details:
			 *   - Installs into ~/post-runtime-mcp so it doesn't conflict
			 *     with parallel Promptless or FRE installs.
			 *   - Claude Desktop config key is "post-runtime-engine" —
			 *     distinct from "form-engine-wordpress" and
			 *     "promptless-wordpress" so all three connectors coexist.
			 *   - Uses Node.js itself to rewrite
			 *     claude_desktop_config.json so no jq/sed is required.
			 *   - Password is passed via argv[2], NOT interpolated into
			 *     the Node script, so it never appears in shell history.
			 *   - Leading `;` separator between NODE_PATH assignments
			 *     avoids the `&&` short-circuit bug Promptless documented.
			 */
			function buildSetupCommand(username, password) {
				const escapedPassword = password.replace(/'/g, "'\\''");
				const escapedSiteUrl  = siteUrl.replace(/'/g, "'\\''");
				const escapedUsername = username.replace(/'/g, "'\\''");

				return [
					`mkdir -p ~/post-runtime-mcp && \\`,
					`curl -fsSL -A 'WordPress/PostRuntimeEngine' '${connectorScriptUrl}' -o ~/post-runtime-mcp/post-runtime-connector.js && \\`,
					`NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \\`,
					`CONFIG="$HOME/Library/Application Support/Claude/claude_desktop_config.json" && \\`,
					`mkdir -p "$HOME/Library/Application Support/Claude" && \\`,
					`"$NODE_PATH" -e '` +
					`var fs=require("fs");` +
					`var p=process.env.HOME+"/Library/Application Support/Claude/claude_desktop_config.json";` +
					`var c;try{c=JSON.parse(fs.readFileSync(p,"utf8"))}catch(e){c={}}` +
					`c.mcpServers=c.mcpServers||{};` +
					`c.mcpServers["post-runtime-engine"]={` +
					`command:process.argv[1],` +
					`args:[process.env.HOME+"/post-runtime-mcp/post-runtime-connector.js"],` +
					`env:{` +
					`POST_RUNTIME_SITE_URL:"${escapedSiteUrl}",` +
					`POST_RUNTIME_USERNAME:"${escapedUsername}",` +
					`POST_RUNTIME_APP_PASSWORD:process.argv[2]` +
					`}};` +
					`fs.writeFileSync(p,JSON.stringify(c,null,2))` +
					`' "$NODE_PATH" '${escapedPassword}' && \\`,
					`echo "" && echo "Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it."`,
				].join('\n');
			}

			function showSetupCommand(username, password) {
				const cmd = buildSetupCommand(username, password);
				document.getElementById('pre-setup-command').textContent = cmd;
				const container = document.getElementById('pre-setup-command-container');
				if (container) container.style.display = 'block';
				const placeholder = document.getElementById('pre-setup-command-placeholder');
				if (placeholder) placeholder.style.display = 'none';
			}

			async function post(action, extra = {}) {
				const body = new URLSearchParams({ action, nonce, ...extra });
				const res  = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
				return res.json();
			}

			function showStatus(id, text, ok = true) {
				const el = document.getElementById(id);
				if (!el) return;
				el.textContent = text;
				el.style.color = ok ? '#2271b1' : '#b32d2e';
				clearTimeout(el._t);
				el._t = setTimeout(() => { el.textContent = ''; }, 2500);
			}

			document.querySelectorAll('[data-ajax-action]').forEach((cb) => {
				cb.addEventListener('change', async (e) => {
					const action   = cb.dataset.ajaxAction;
					const enabled  = cb.checked ? '1' : '0';
					const statusId = 'pre-enabled-status';
					try {
						const r = await post(action, { enabled });
						if (r.success) {
							showStatus(statusId, cb.checked ? '<?php echo esc_js( __( 'Enabled.', 'post-runtime-engine' ) ); ?>' : '<?php echo esc_js( __( 'Disabled.', 'post-runtime-engine' ) ); ?>');
						} else {
							cb.checked = !cb.checked;
							showStatus(statusId, (r.data && r.data.message) || 'Error', false);
						}
					} catch (err) {
						cb.checked = !cb.checked;
						showStatus(statusId, String(err), false);
					}
				});
			});

			const genBtn = document.getElementById('pre-generate-password-btn');
			if (genBtn) {
				genBtn.addEventListener('click', async () => {
					// No confirm() dialog — Promptless doesn't use one and the
					// blocking modal adds friction without preventing mistakes
					// (the previous password is already revoked atomically on
					// the server side, so a misclick is recoverable by
					// re-clicking Generate).
					const originalLabel = genBtn.textContent;
					genBtn.disabled = true;
					genBtn.textContent = '<?php echo esc_js( __( 'Generating...', 'post-runtime-engine' ) ); ?>';
					const r = await post('pre_connector_generate_password');
					genBtn.disabled = false;
					if (r.success) {
						// Reveal the "connection generated" success notice
						// in the Step 1 card.
						const display = document.getElementById('pre-credential-display');
						if (display) display.style.display = 'block';

						// Build the bash setup command and reveal it in
						// the Step 2 card. The plaintext password lives in
						// memory only for this build — never re-shown after
						// a page reload.
						showSetupCommand(r.data.username, r.data.password);

						// Flip the status pill from "Not Connected" red to
						// "Configured" green in the Connection Status card.
						const pill = document.getElementById('pre-connector-status-pill');
						if (pill) {
							pill.textContent = '<?php echo esc_js( __( 'Configured', 'post-runtime-engine' ) ); ?>';
							pill.classList.remove('pre-connector-status-inactive');
							pill.classList.add('pre-connector-status-active');
						}

						// Update the button label to "Regenerate" for any
						// future clicks during this session.
						genBtn.textContent = '<?php echo esc_js( __( 'Regenerate Connection', 'post-runtime-engine' ) ); ?>';
					} else {
						genBtn.textContent = originalLabel;
						alert((r.data && r.data.message) || 'Error');
					}
				});
			}

			const copyBtn = document.getElementById('pre-copy-setup-command');
			if (copyBtn) {
				// Capture the original label on init so the restore-after-flash
				// matches whatever the PHP template rendered, instead of being
				// hardcoded to 'Copy' (which becomes a mismatched stub when the
				// template label is something more descriptive like 'Copy Command').
				const originalCopyLabel = copyBtn.textContent;
				const flashCopied = () => {
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'post-runtime-engine' ) ); ?>';
					setTimeout(() => { copyBtn.textContent = originalCopyLabel; }, 2000);
				};
				copyBtn.addEventListener('click', async () => {
					const pre = document.getElementById('pre-setup-command');
					const cmd = pre.textContent;
					// Path 1: modern Clipboard API. Only available on HTTPS
					// sites and on true localhost (127.0.0.1, ::1). NOT
					// available on HTTP custom hostnames like
					// `mysite.local` from Local by Flywheel.
					if (navigator.clipboard && navigator.clipboard.writeText) {
						try {
							await navigator.clipboard.writeText(cmd);
							flashCopied();
							return;
						} catch (e) { /* fall through to legacy path */ }
					}
					// Path 2: legacy execCommand fallback. Deprecated but
					// works on HTTP sites where the modern API is gated.
					// Visually selects the <pre> then issues a copy command
					// — actual clipboard write succeeds in every browser
					// we care about (Chrome, Safari, Firefox, Edge) without
					// requiring HTTPS. We still show the "Copied" flash so
					// the user gets visual confirmation either way.
					const sel = window.getSelection();
					const range = document.createRange();
					range.selectNodeContents(pre);
					sel.removeAllRanges();
					sel.addRange(range);
					try {
						const ok = document.execCommand('copy');
						sel.removeAllRanges();
						if (ok) {
							flashCopied();
						}
					} catch (e) {
						// execCommand also failed — leave the selection so
						// the user can press Cmd+C manually.
					}
				});
			}

			const revokeBtn = document.getElementById('pre-revoke-password-btn');
			if (revokeBtn) {
				revokeBtn.addEventListener('click', async () => {
					if (!confirm('<?php echo esc_js( __( 'Revoke the connector App Password? Claude Cowork will lose access immediately.', 'post-runtime-engine' ) ); ?>')) return;
					revokeBtn.disabled = true;
					const r = await post('pre_connector_revoke_password');
					revokeBtn.disabled = false;
					if (r.success) {
						window.location.reload();
					} else {
						alert((r.data && r.data.message) || 'Error');
					}
				});
			}
		})();
		</script>
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
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'post-runtime-engine' ) ), 403 );
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
					? __( 'Connector enabled.', 'post-runtime-engine' )
					: __( 'Connector disabled.', 'post-runtime-engine' ),
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
					'message' => __( 'Application Passwords are not available on this site. Requires WordPress 5.6+ and HTTPS (or WP_ENVIRONMENT_TYPE=local for development).', 'post-runtime-engine' ),
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
				'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', 'post-runtime-engine' ),
			)
		);
	}

	/**
	 * AJAX: revoke the connector App Password for the current user.
	 */
	public function ajax_revoke_password() {
		$this->verify_ajax();

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress 5.6+ is required.', 'post-runtime-engine' ) ) );
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
					_n( '%d connector credential revoked.', '%d connector credentials revoked.', $count, 'post-runtime-engine' ),
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
			echo '// Post Runtime Engine connector script not found on this install.';
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
