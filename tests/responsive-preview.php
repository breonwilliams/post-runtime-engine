<?php
/**
 * Responsive preview harness for the kitchen-sink demo post.
 *
 * Renders the demo post inside three iframes at canonical mobile / tablet /
 * desktop widths so visual review can compare all three breakpoints in a
 * single screenshot. Same-origin iframes inherit cookies, so an admin's
 * preview-of-draft permissions and Promptless's editor-assets-when-active
 * detection both work transparently.
 *
 * Each iframe gets the post's frontend URL plus `?pre_responsive_preview=1`
 * so we could (in a later pass) hide WordPress's admin bar inside the
 * iframe — currently the admin bar still shows, which is fine for design
 * review.
 *
 * @package PostRuntimeEngine
 */

require_once dirname( __FILE__ ) . '/../../../../wp-load.php';

if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? '' ) );
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	http_response_code( 403 );
	wp_die( 'Forbidden' );
}

// Find the kitchen-sink demo post.
$demo_posts = get_posts(
	array(
		'post_type'      => 'pre_demo',
		'title'          => 'Modern Family Home in Lake View',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);

if ( empty( $demo_posts ) ) {
	wp_die( 'Kitchen sink demo post not found. Run tests/run-kitchen-sink-via-browser.php first.' );
}

$demo_url = get_permalink( $demo_posts[0] ) . '?pre_responsive_preview=1';

$breakpoints = array(
	array( 'label' => 'Mobile (375px)', 'width' => 375 ),
	array( 'label' => 'Tablet (768px)', 'width' => 768 ),
	array( 'label' => 'Desktop (1280px)', 'width' => 1280 ),
);

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Post Runtime Engine — Responsive Preview</title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			padding: 24px;
			background: #1f2937;
			color: #f9fafb;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}
		h1 {
			margin: 0 0 8px 0;
			font-size: 18px;
			font-weight: 600;
		}
		.url {
			font-family: ui-monospace, "SF Mono", Menlo, monospace;
			font-size: 12px;
			color: #9ca3af;
			margin-bottom: 24px;
			word-break: break-all;
		}
		.frames {
			display: flex;
			gap: 24px;
			align-items: flex-start;
			overflow-x: auto;
			padding-bottom: 24px;
		}
		.frame {
			flex: 0 0 auto;
			background: #ffffff;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
		}
		.frame__label {
			background: #374151;
			color: #f9fafb;
			padding: 10px 16px;
			font-size: 13px;
			font-weight: 600;
		}
		.frame iframe {
			display: block;
			border: none;
			background: #ffffff;
		}
	</style>
</head>
<body>
	<h1>Post Runtime Engine — Responsive Preview</h1>
	<div class="url">Source: <?php echo esc_html( $demo_url ); ?></div>

	<div class="frames">
		<?php foreach ( $breakpoints as $bp ) : ?>
			<div class="frame" style="width: <?php echo (int) $bp['width']; ?>px;">
				<div class="frame__label"><?php echo esc_html( $bp['label'] ); ?></div>
				<iframe
					src="<?php echo esc_url( $demo_url ); ?>"
					width="<?php echo (int) $bp['width']; ?>"
					height="900"
					title="<?php echo esc_attr( $bp['label'] ); ?>"
					loading="lazy"
				></iframe>
			</div>
		<?php endforeach; ?>
	</div>
</body>
</html>
