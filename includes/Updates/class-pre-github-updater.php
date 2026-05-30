<?php
/**
 * GitHub Updater for Promptless CPT Pages.
 *
 * Checks GitHub releases for plugin updates and integrates with
 * WordPress's built-in update system.
 *
 * Distribution model: this plugin is self-hosted via GitHub Releases, not
 * the WordPress.org plugin directory. The Plugin Check (PCP) tool flags
 * plugin-provided updaters as a violation because WordPress.org provides
 * its own update mechanism for hosted plugins — but that rule does not
 * apply to plugins distributed outside WP.org. The phpcs:ignoreFile
 * directive below silences PCP for this file only; the rest of the plugin
 * still runs through every other rule.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:ignoreFile PluginCheck.CodeAnalysis.PluginUpdater.plugin_updater_detected
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater class.
 *
 * Handles checking for updates from GitHub releases and providing
 * update information to WordPress.
 */
class PCPTPages_GitHub_Updater {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $slug = 'promptless-cpt-pages';

	/**
	 * Plugin file path relative to plugins directory.
	 *
	 * @var string
	 */
	private $plugin_file = 'post-runtime-engine/post-runtime-engine.php';

	/**
	 * GitHub repository (username/repo). Edit here if your fork is hosted
	 * under a different account or repo name.
	 *
	 * @var string
	 */
	private $github_repo = 'breonwilliams/post-runtime-engine';

	/**
	 * Current installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Transient cache key.
	 *
	 * @var string
	 */
	private $cache_key = 'pcptpages_github_update_check';

	/**
	 * Cache expiry time in seconds (12 hours).
	 *
	 * @var int
	 */
	private $cache_expiry = 43200;

	/**
	 * GitHub API response data.
	 *
	 * @var object|null
	 */
	private $github_response = null;

	/**
	 * GitHub Personal Access Token for private repos. Define
	 * `PCPTPages_GITHUB_TOKEN` in wp-config.php to enable updates from a private
	 * repository. Leave undefined for public repos.
	 *
	 * @var string
	 */
	private $github_token = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->version      = defined( 'PCPTPages_VERSION' ) ? PCPTPages_VERSION : '0.0.0';
		$this->github_token = defined( 'PCPTPages_GITHUB_TOKEN' ) ? PCPTPages_GITHUB_TOKEN : '';
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Check for updates when WordPress checks for plugin updates.
		add_filter( 'pcptpages_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Provide plugin info for the "View Details" popup.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		// Clear cache after plugin update.
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

		// Add custom message on plugins page.
		add_action( 'in_plugin_update_message-' . $this->plugin_file, array( $this, 'update_message' ), 10, 2 );

		// Add auth headers for private repo downloads.
		if ( ! empty( $this->github_token ) ) {
			add_filter( 'http_request_args', array( $this, 'http_request_args' ), 10, 2 );
		}

		// Ensure correct directory name after extraction.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * Check GitHub for the latest release.
	 *
	 * @param object $transient WordPress update transient.
	 * @return object Modified transient with update info.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get release info from GitHub.
		$release = $this->get_github_release();

		if ( ! $release ) {
			return $transient;
		}

		// Compare versions.
		$latest_version = ltrim( $release->tag_name, 'v' );

		if ( version_compare( $this->version, $latest_version, '<' ) ) {
			$update = (object) array(
				'slug'         => $this->slug,
				'plugin'       => $this->plugin_file,
				'new_version'  => $latest_version,
				'url'          => $release->html_url,
				'package'      => $this->get_download_url( $release ),
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => '',
				'requires'     => '5.0',
				'requires_php' => '7.4',
			);

			$transient->response[ $this->plugin_file ] = $update;
		} else {
			// No update available - add to no_update array.
			$transient->no_update[ $this->plugin_file ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->plugin_file,
				'new_version' => $this->version,
				'url'         => 'https://github.com/' . $this->github_repo,
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View Details" popup.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object Plugin info or false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $this->slug !== $args->slug ) {
			return $result;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $result;
		}

		$latest_version = ltrim( $release->tag_name, 'v' );

		// Build plugin info object.
		$plugin_info = (object) array(
			'name'           => 'Promptless CPT Pages',
			'slug'           => $this->slug,
			'version'        => $latest_version,
			'author'         => '<a href="https://github.com/breonwilliams">Breon Williams</a>',
			'author_profile' => 'https://github.com/breonwilliams',
			'homepage'       => 'https://github.com/' . $this->github_repo,
			'requires'       => '5.0',
			'tested'         => get_bloginfo( 'version' ),
			'requires_php'   => '7.4',
			'downloaded'     => 0,
			'last_updated'   => $release->published_at,
			'sections'       => array(
				'description'  => $this->get_plugin_description(),
				'changelog'    => $this->format_changelog( $release->body ),
				'installation' => $this->get_installation_instructions(),
			),
			'download_link'  => $this->get_download_url( $release ),
			'banners'        => array(),
			'icons'          => array(),
		);

		return $plugin_info;
	}

	/**
	 * Get the latest release from GitHub API.
	 *
	 * @return object|null Release data or null on failure.
	 */
	private function get_github_release() {
		// Check cache first.
		$cached = get_transient( $this->cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch from GitHub API.
		$url = sprintf(
			'https://api.github.com/repos/%s/releases/latest',
			$this->github_repo
		);

		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
		);

		// Add auth token for private repositories.
		if ( ! empty( $this->github_token ) ) {
			$headers['Authorization'] = 'token ' . $this->github_token;
		}

		$response = wp_remote_get( $url, array(
			'headers' => $headers,
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			// Log the error but don't cache it - allow retry on next check.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PRE GitHub Updater: API request failed - ' . $response->get_error_message() );
			}
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			// Handle 404 (no releases) or other errors.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PRE GitHub Updater: API returned status ' . $code );
			}
			// Cache a failure for a shorter time (1 hour) to avoid hammering API.
			set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( ! $data || ! isset( $data->tag_name ) ) {
			return null;
		}

		// Cache the successful response.
		set_transient( $this->cache_key, $data, $this->cache_expiry );

		return $data;
	}

	/**
	 * Get the download URL from a release.
	 *
	 * Prefers a ZIP asset attached to the release (which has the correct
	 * directory structure for WordPress), falls back to the auto-generated
	 * zipball. For private repos, appends the auth token to the URL so
	 * WordPress can download it.
	 *
	 * @param object $release GitHub release data.
	 * @return string Download URL.
	 */
	private function get_download_url( $release ) {
		// Look for a specifically named ZIP file in release assets.
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( preg_match( '/\.zip$/i', $asset->name ) ) {
					$url = $asset->browser_download_url;

					// For private repos, use the API URL with auth token.
					if ( ! empty( $this->github_token ) ) {
						$url = $asset->url;
					}

					return $url;
				}
			}
		}

		// Fall back to GitHub's auto-generated zipball.
		return $release->zipball_url;
	}

	/**
	 * Filter the HTTP request args for downloading the plugin package.
	 *
	 * Adds the GitHub auth token to the download request for private repos.
	 * This is necessary because WordPress downloads the package URL via
	 * wp_remote_get, and private repo assets require authentication.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  The request URL.
	 * @return array Modified arguments.
	 */
	public function http_request_args( $args, $url ) {
		// Only modify requests to our GitHub repo.
		if ( empty( $this->github_token ) ) {
			return $args;
		}

		if ( false === strpos( $url, $this->github_repo ) && false === strpos( $url, 'github.com' ) ) {
			return $args;
		}

		// Add auth token and accept header for asset downloads.
		$args['headers']['Authorization'] = 'token ' . $this->github_token;
		$args['headers']['Accept']        = 'application/octet-stream';

		return $args;
	}

	/**
	 * Format the changelog from release body (markdown).
	 *
	 * @param string $body Release body in markdown.
	 * @return string HTML formatted changelog.
	 */
	private function format_changelog( $body ) {
		if ( empty( $body ) ) {
			return '<p>No changelog available for this release.</p>';
		}

		// Convert markdown to HTML (basic conversion).
		$html = esc_html( $body );

		// Convert headers.
		$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $html );

		// Convert bold and italic.
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

		// Convert bullet points.
		$html = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html );

		// Convert line breaks.
		$html = nl2br( $html );

		return $html;
	}

	/**
	 * Get plugin description.
	 *
	 * @return string HTML description.
	 */
	private function get_plugin_description() {
		return '<p>Promptless CPT Pages renders WordPress custom-post-type single pages with structured data display through Promptless WP\'s design system. Companion plugin to Form Runtime Engine and Promptless WP.</p>'
			. '<h4>Features</h4>'
			. '<ul>'
			. '<li>Register CPTs through a constrained-primitive system (groupings + post fields) without writing PHP</li>'
			. '<li>Nine post-field display types: currency, badge, meta_pair, date, rating, progress, multi_badge, number_with_label, text</li>'
			. '<li>Six render positions (image_overlay, headline, subtitle, meta_strip, footer_meta, hidden) symmetric across single-post hero AND archive / PostGrid cards</li>'
			. '<li>Four layout variants for groupings (compact-grid, card-grid, featured-card, horizontal-row) with three source modes (manual, child_posts, taxonomy_match, meta_match)</li>'
			. '<li>Design-token integration with Promptless WP — colors, typography, spacing all inherit automatically</li>'
			. '<li>Per-CPT archive-card meta toggles (suppress theme-rendered post date / author when a custom date field is more meaningful)</li>'
			. '<li>Connector REST API + MCP bridge so Claude Cowork can register CPTs, define fields, populate values, and choose variants without an admin UI</li>'
			. '<li>Iconify icon support alongside the built-in 53-icon library — 200,000+ icons available via collection:name codes</li>'
			. '<li>Free, no premium tier, no license gates</li>'
			. '</ul>';
	}

	/**
	 * Get installation instructions.
	 *
	 * @return string HTML installation instructions.
	 */
	private function get_installation_instructions() {
		return '<ol>'
			. '<li>Download the plugin ZIP file from the latest GitHub release</li>'
			. '<li>Go to WordPress Admin → Plugins → Add New</li>'
			. '<li>Click "Upload Plugin" and select the ZIP file</li>'
			. '<li>Activate the plugin</li>'
			. '<li>Register a CPT via Promptless CPT Pages → Post Types → Add New, then define groupings and post fields for it</li>'
			. '<li>Or wire Claude Cowork into your site via the Connector page to author CPTs + fields through MCP tools</li>'
			. '</ol>';
	}

	/**
	 * Display a message on the plugins page for updates.
	 *
	 * @param array  $plugin_data Plugin data.
	 * @param object $response    Update response data.
	 */
	public function update_message( $plugin_data, $response ) {
		echo ' <em>' . esc_html__( 'Update available from GitHub.', 'promptless-cpt-pages' ) . '</em>';
	}

	/**
	 * Clear the update cache.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $options  Array of update options.
	 */
	public function clear_cache( $upgrader, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fix the extracted source directory name after download.
	 *
	 * GitHub's auto-generated zipballs extract to a folder like
	 * "breonwilliams-post-runtime-engine-abc1234" instead of
	 * "post-runtime-engine". This method renames it so WordPress
	 * recognizes it as the same plugin and replaces instead of
	 * creating a duplicate.
	 *
	 * @param string      $source        Path to the extracted source.
	 * @param string      $remote_source Path to the remote source.
	 * @param WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra arguments passed to hooked filters.
	 * @return string|WP_Error Corrected source path or WP_Error on failure.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		// Only act on our plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $source;
		}

		global $wp_filesystem;

		$expected_dir = trailingslashit( $remote_source ) . $this->slug . '/';

		// If the source already has the correct name, no action needed.
		if ( trailingslashit( $source ) === $expected_dir ) {
			return $source;
		}

		// Rename the extracted directory to match the plugin slug.
		if ( $wp_filesystem->move( $source, $expected_dir ) ) {
			return $expected_dir;
		}

		return new WP_Error(
			'rename_failed',
			__( 'Unable to rename the plugin directory for Promptless CPT Pages.', 'promptless-cpt-pages' )
		);
	}

	/**
	 * Manually clear the update cache.
	 *
	 * Useful for debugging or forcing an update check.
	 */
	public static function force_check() {
		delete_transient( 'pcptpages_github_update_check' );
		delete_site_transient( 'update_plugins' );
	}
}
