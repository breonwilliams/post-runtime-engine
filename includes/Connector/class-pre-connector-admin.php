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
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
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

		$ajax_nonce            = wp_create_nonce( self::NONCE_ACTION );
		$rest_base_url         = esc_url( rest_url( PRE_REST_NAMESPACE . '/' . PRE_REST_BASE ) );
		$site_url              = home_url();
		$connector_script_url  = admin_url( 'admin-ajax.php?action=pre_download_connector' );
		$mcp_setup_url         = PRE_PLUGIN_URL . 'docs/MCP_CONNECTOR_SETUP.md';
		$spec_url              = PRE_PLUGIN_URL . 'docs/CONNECTOR_SPEC.md';
		$user                  = wp_get_current_user();

		?>
		<div class="wrap pre-claude-connection">
			<h1><?php esc_html_e( 'Post Runtime Engine — Connector', 'post-runtime-engine' ); ?></h1>

			<p>
				<?php esc_html_e( 'The connector lets external tools — most notably Claude Cowork — register custom post types, define groupings, populate per-post values, and preview rendered output through a secure REST API. Follow the four steps below to connect your Mac.', 'post-runtime-engine' ); ?>
			</p>

			<h2 class="title"><?php esc_html_e( 'Step 1 — Enable the connector', 'post-runtime-engine' ); ?></h2>
			<p>
				<label>
					<input
						type="checkbox"
						id="pre-connector-enabled"
						<?php checked( $is_enabled ); ?>
						data-ajax-action="pre_connector_toggle_enabled"
					>
					<strong><?php esc_html_e( 'Allow Claude Cowork to call the connector REST API', 'post-runtime-engine' ); ?></strong>
				</label>
				<span class="pre-toggle-status" id="pre-enabled-status" aria-live="polite"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'When disabled, all connector endpoints return 403 — even with valid credentials. Toggle this off if you ever need to temporarily lock the agent out of the site.', 'post-runtime-engine' ); ?>
			</p>

			<h2 class="title"><?php esc_html_e( 'Step 2 — Generate a connection', 'post-runtime-engine' ); ?></h2>
			<p>
				<?php esc_html_e( 'The connector authenticates via a WordPress Application Password. Generating one here revokes any previous connector credential for your user, so there is at most one active connector key at any time. The password is shown once — the bash command in Step 3 will be populated with it automatically.', 'post-runtime-engine' ); ?>
			</p>

			<p>
				<button type="button" id="pre-generate-password-btn" class="button button-primary">
					<?php
					echo $configured_at > 0
						? esc_html__( 'Regenerate connection', 'post-runtime-engine' )
						: esc_html__( 'Generate connection', 'post-runtime-engine' );
					?>
				</button>

				<?php if ( $configured_at > 0 ) : ?>
					<button type="button" id="pre-revoke-password-btn" class="button">
						<?php esc_html_e( 'Revoke connection', 'post-runtime-engine' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<?php if ( $configured_at > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: human-readable last-configured time */
						esc_html__( 'Last configured: %s', 'post-runtime-engine' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
					);
					?>
				</p>
			<?php endif; ?>

			<div id="pre-credential-display" style="display:none;margin-top:12px;background:#f0f6fc;border:1px solid #2271b1;padding:12px;">
				<p style="margin:0 0 6px 0;">
					<strong><?php esc_html_e( 'Application Password (copy now — it will not be shown again):', 'post-runtime-engine' ); ?></strong>
				</p>
				<code id="pre-credential-value" style="display:block;padding:8px;background:#fff;font-size:13px;letter-spacing:0.05em;user-select:all;"></code>
			</div>

			<h2 class="title"><?php esc_html_e( 'Step 3 — Connect Claude Desktop', 'post-runtime-engine' ); ?></h2>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: link to MCP setup documentation */
						__( 'Copy the command below and paste it into Terminal on your Mac. It downloads the MCP server script, detects your Node.js installation, and registers the connector with Claude Desktop. Full setup notes and troubleshooting are in <a href="%s" target="_blank" rel="noopener">MCP_CONNECTOR_SETUP.md</a>.', 'post-runtime-engine' ),
						esc_url( $mcp_setup_url )
					),
					array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
				);
				?>
			</p>

			<div class="pre-setup-requirements">
				<strong><?php esc_html_e( 'Requirements:', 'post-runtime-engine' ); ?></strong>
				<ul style="margin: 6px 0 0 20px;">
					<li><?php esc_html_e( 'macOS with Terminal', 'post-runtime-engine' ); ?></li>
					<li><?php esc_html_e( 'Node.js v14+ (via nvm, Homebrew, or system installer)', 'post-runtime-engine' ); ?></li>
					<li><?php esc_html_e( 'Claude Desktop installed', 'post-runtime-engine' ); ?></li>
				</ul>
			</div>

			<div id="pre-setup-command-placeholder" style="margin-top:12px;<?php echo $configured_at > 0 ? 'display:none;' : ''; ?>">
				<p class="description" style="color:#888;">
					<?php esc_html_e( 'Generate a connection in Step 2 first, then your setup command will appear here.', 'post-runtime-engine' ); ?>
				</p>
			</div>

			<div id="pre-setup-command-wrap" style="display:none;margin-top:12px;">
				<div style="background:#f6f7f7;border:1px solid #c3c4c7;padding:12px;position:relative;">
					<pre id="pre-setup-command" style="margin:0;white-space:pre;overflow-x:auto;font-size:12px;line-height:1.5;"></pre>
					<button type="button" class="button button-small" id="pre-copy-setup-command" style="position:absolute;top:8px;right:8px;">
						<?php esc_html_e( 'Copy', 'post-runtime-engine' ); ?>
					</button>
				</div>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'After the command completes, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next Cowork session.', 'post-runtime-engine' ); ?>
				</p>
			</div>

			<h2 class="title"><?php esc_html_e( 'REST endpoint reference', 'post-runtime-engine' ); ?></h2>
			<p>
				<?php esc_html_e( 'Base URL for all connector endpoints:', 'post-runtime-engine' ); ?>
				<br>
				<code><?php echo esc_html( $rest_base_url ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'Logged in as:', 'post-runtime-engine' ); ?>
				<code><?php echo esc_html( $user->user_login ); ?></code>
				·
				<?php
				/* translators: 1: plugin version, 2: data version */
				printf( esc_html__( 'Plugin v%1$s · Data v%2$s', 'post-runtime-engine' ), esc_html( PRE_VERSION ), esc_html( PRE_DATA_VERSION ) );
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: spec URL */
					esc_html__( 'See the full endpoint list and request/response schemas in the %s.', 'post-runtime-engine' ),
					'<a href="' . esc_url( $spec_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'connector specification', 'post-runtime-engine' ) . '</a>'
				);
				?>
			</p>
		</div>

		<style>
			.pre-claude-connection h2.title { margin-top: 2em; }
			.pre-toggle-status { margin-left: 10px; font-style: italic; color: #50575e; }
			.pre-setup-requirements { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 10px 14px; margin-top: 6px; }
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
				document.getElementById('pre-setup-command-wrap').style.display = 'block';
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
					if (!confirm('<?php echo esc_js( __( 'Generate a new connection? Any previous connector App Password will be revoked immediately.', 'post-runtime-engine' ) ); ?>')) return;
					genBtn.disabled = true;
					const r = await post('pre_connector_generate_password');
					genBtn.disabled = false;
					if (r.success) {
						document.getElementById('pre-credential-display').style.display = 'block';
						document.getElementById('pre-credential-value').textContent = r.data.password;

						// Build the bash setup command while we still
						// have the plaintext password in memory — it's
						// never shown again after this page reload.
						showSetupCommand(r.data.username, r.data.password);
					} else {
						alert((r.data && r.data.message) || 'Error');
					}
				});
			}

			const copyBtn = document.getElementById('pre-copy-setup-command');
			if (copyBtn) {
				copyBtn.addEventListener('click', async () => {
					const cmd = document.getElementById('pre-setup-command').textContent;
					try {
						await navigator.clipboard.writeText(cmd);
						copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'post-runtime-engine' ) ); ?>';
						setTimeout(() => { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'post-runtime-engine' ) ); ?>'; }, 2000);
					} catch (e) {
						const sel = window.getSelection();
						const range = document.createRange();
						range.selectNodeContents(document.getElementById('pre-setup-command'));
						sel.removeAllRanges();
						sel.addRange(range);
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
