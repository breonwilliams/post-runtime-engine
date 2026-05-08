<?php
/**
 * Browser-based runner for the Phase 1 smoke test.
 *
 * Bypasses WP-CLI entirely. Use this when wp-cli is unavailable or having
 * trouble connecting to Local's MySQL socket. Loads WordPress directly,
 * gates on administrator capability, runs the smoke test, and renders
 * results as readable HTML.
 *
 * USAGE:
 *
 *   1. Log in to WordPress as an administrator (the account you use to
 *      manage the site at http://ai-section-builder.local/wp-admin/).
 *   2. In the same browser, visit:
 *        http://ai-section-builder.local/wp-content/plugins/post-runtime-engine/tests/run-via-browser.php
 *   3. The page will render PASS/FAIL lines for every test plus a summary.
 *
 * SECURITY:
 *
 *   This file is gated behind `manage_options` (administrator capability)
 *   so unauthenticated visitors get a 403. It does NOT expose any data the
 *   admin couldn't already see through other admin paths. It does NOT
 *   modify production state — it creates a temporary CPT, defines test
 *   groupings, creates a draft post, then cleans everything up before
 *   exiting.
 *
 *   Despite the safeguards, this file should be removed before deploying
 *   to a production server. It exists for development verification only.
 *
 * @package PostRuntimeEngine
 */

// ---------------------------------------------------------------------------
// Bootstrap WordPress.
// ---------------------------------------------------------------------------

// Walk up four directories: tests/ -> plugin/ -> plugins/ -> wp-content/ -> wp root.
$wp_load = dirname( __FILE__ ) . '/../../../../wp-load.php';

if ( ! file_exists( $wp_load ) ) {
	http_response_code( 500 );
	echo 'wp-load.php not found at expected relative path. ';
	echo 'Tried: ' . htmlspecialchars( $wp_load );
	exit;
}

require_once $wp_load;

// ---------------------------------------------------------------------------
// Authorization gate.
// ---------------------------------------------------------------------------

if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? '' ) );
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	http_response_code( 403 );
	wp_die(
		'<p>You need administrator privileges to run smoke tests.</p>',
		'Forbidden',
		array( 'response' => 403 )
	);
}

// ---------------------------------------------------------------------------
// Output the results page.
// ---------------------------------------------------------------------------

// Capture the smoke test's stdout so we can wrap it in HTML.
ob_start();

// Run the actual test. The smoke test itself uses echo / exit; capture the
// echo output and the exit code (via a register_shutdown_function trick).
$smoke_path = dirname( __FILE__ ) . '/smoke-phase1.php';

if ( ! file_exists( $smoke_path ) ) {
	echo 'smoke-phase1.php not found.';
	exit;
}

// Disable the script's own exit() from terminating the runner. The smoke
// test calls exit(0) on success and exit(1) on failure; we want to keep
// the page rendering after either. Override by buffering and letting the
// shutdown handler pick up where we left off.
//
// Trick: we capture output, then re-render in a styled wrapper.
//
// Note: smoke-phase1.php's `exit()` calls will end script execution
// regardless of buffering. To work around that, we use a sub-process pattern:
// require the file inside a function so exit() terminates the function but
// not the page. PHP's exit() actually halts the entire script though, so
// we have to swap exit for a thrown exception or use a different strategy.
//
// Simplest workaround: copy the smoke test logic inline rather than require
// it. Less DRY but works reliably across PHP versions. The smoke test file
// itself is the canonical source of truth — this file just loads it and
// renders its output.
//
// Actually — the cleanest solution is to replace `exit($code)` calls with
// `return $code` semantics. Since the smoke test is plain procedural code
// (no class wrapping), we can `include` it and let it run; the exits will
// halt page render, but our shutdown handler will print the closing HTML.

$exit_code = null;

// Capture exit code via shutdown function.
register_shutdown_function(
	function () {
		// Buffer is flushed at this point; just close the HTML wrapper.
		// The wrapper opening tags are emitted before include below, so
		// we close them here. This runs even if the smoke test calls exit().
		echo "</pre>\n";
		echo "<p style=\"font-family: -apple-system, sans-serif; padding: 16px 32px; color: #6b7280;\">";
		echo "Test complete. Refresh the page to run again.";
		echo "</p>\n";
		echo "</body></html>";
	}
);

// Open HTML wrapper.
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Post Runtime Engine — Phase 1 Smoke Test</title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: #f9fafb;
			color: #1f2937;
			margin: 0;
			padding: 32px;
		}
		h1 {
			margin: 0 0 8px 0;
			font-size: 20px;
			font-weight: 600;
		}
		.meta {
			color: #6b7280;
			font-size: 14px;
			margin-bottom: 24px;
		}
		pre {
			background: #ffffff;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 24px 32px;
			font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
			font-size: 13px;
			line-height: 1.6;
			color: #1f2937;
			white-space: pre-wrap;
			word-break: break-word;
			overflow-x: auto;
		}
		/* Highlight PASS / FAIL lines via inline styles in the script. */
	</style>
</head>
<body>
	<h1>Post Runtime Engine — Phase 1 Smoke Test</h1>
	<div class="meta">
		Site: <?php echo esc_html( home_url() ); ?> |
		User: <?php echo esc_html( wp_get_current_user()->user_login ); ?> |
		Plugin loaded: <?php echo function_exists( 'pre' ) ? 'yes' : '<strong>no — activate the plugin first</strong>'; ?>
	</div>
	<pre><?php

// Now include the smoke test. Its echo output appears here; its exit()
// triggers the shutdown handler which closes the HTML.
//
// We post-process the output so PASS/FAIL words render with color. We do
// this by buffering the smoke test's output, modifying it, then echoing.
ob_start();
include $smoke_path;
$smoke_output = ob_get_clean();

// Apply minimal coloring without breaking the monospace formatting.
$smoke_output = htmlspecialchars( $smoke_output );
$smoke_output = preg_replace(
	'/^(PASS)(\s)/m',
	'<span style="color:#10b981;font-weight:600">$1</span>$2',
	$smoke_output
);
$smoke_output = preg_replace(
	'/^(FAIL)(\s)/m',
	'<span style="color:#dc2626;font-weight:700">$1</span>$2',
	$smoke_output
);
$smoke_output = preg_replace(
	'/(Result: \d+\/\d+ passed)/',
	'<span style="color:#1f2937;font-weight:700;font-size:14px">$1</span>',
	$smoke_output
);

echo $smoke_output;

// The shutdown handler closes the HTML.
