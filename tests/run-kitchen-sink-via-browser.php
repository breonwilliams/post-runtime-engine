<?php
/**
 * Browser runner for the kitchen-sink fixture script.
 *
 * Visit this URL while logged in as administrator to set up the visual
 * fixture. After completion, follow the printed link to view the demo
 * post on the frontend.
 *
 * @package PostRuntimeEngine
 */

// Bootstrap WordPress.
$wp_load = dirname( __FILE__ ) . '/../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	http_response_code( 500 );
	echo 'wp-load.php not found at expected relative path.';
	exit;
}
require_once $wp_load;

// Authorization gate.
if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? '' ) );
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	http_response_code( 403 );
	wp_die(
		'<p>You need administrator privileges to run the kitchen sink.</p>',
		'Forbidden',
		array( 'response' => 403 )
	);
}

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Post Runtime Engine — Kitchen Sink Setup</title>
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
	</style>
</head>
<body>
	<h1>Post Runtime Engine — Kitchen Sink Setup</h1>
	<div class="meta">
		Site: <?php echo esc_html( home_url() ); ?> |
		User: <?php echo esc_html( wp_get_current_user()->user_login ); ?> |
		Plugin loaded: <?php echo function_exists( 'pre' ) ? 'yes' : '<strong>no — activate the plugin first</strong>'; ?>
	</div>
	<pre><?php

ob_start();
include dirname( __FILE__ ) . '/kitchen-sink.php';
$output = ob_get_clean();

$output = htmlspecialchars( $output );
$output = preg_replace( '/^(OK)(:\s)/m', '<span style="color:#10b981;font-weight:600">$1</span>$2', $output );
$output = preg_replace( '/^(FAIL)(:\s)/m', '<span style="color:#dc2626;font-weight:700">$1</span>$2', $output );

// Linkify any URL.
$output = preg_replace_callback(
	'/(https?:\/\/[^\s]+)/',
	function ( $m ) {
		return '<a href="' . htmlspecialchars( $m[1], ENT_QUOTES ) . '" target="_blank" style="color:#6366f1;">' . $m[1] . '</a>';
	},
	$output
);

echo $output;
?></pre>
</body>
</html>
