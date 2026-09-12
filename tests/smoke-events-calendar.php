<?php
/**
 * Smoke test: the calendar feed on a site (LOCAL only).
 *
 * Fetches the feed over HTTP the way a calendar app does — the record
 * feed, the type feed, the plain-permalink forms, and a type that is not
 * event-shaped — and checks the single page carries the "Add to calendar"
 * links and the discovery <link>. Needs at least one event-shaped type
 * with a published record.
 *
 * Run from the plugin root in the Local site shell, or with Homebrew PHP:
 *   php -d mysqli.default_socket="…/mysqld.sock" tests/smoke-events-calendar.php
 *
 * @package PostRuntimeEngine
 * @since 0.8.2
 */

$wp_load = '';
$dir     = __DIR__;
for ( $i = 0; $i < 8; $i++ ) {
	$dir = dirname( $dir );
	if ( file_exists( $dir . '/wp-load.php' ) ) {
		$wp_load = $dir . '/wp-load.php';
		break;
	}
}
if ( '' === $wp_load ) {
	fwrite( STDERR, "WordPress not found.\n" );
	exit( 1 );
}
require_once $wp_load;

$pass = 0;
$fail = 0;
function check( $cond, $label, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ {$label}\n";
	} else {
		$fail++;
		echo "  ✗ {$label}" . ( $detail !== '' ? "\n      {$detail}" : '' ) . "\n";
	}
}
function fetch( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false ) );
	if ( is_wp_error( $r ) ) {
		return array( 0, '', array() );
	}
	return array(
		(int) wp_remote_retrieve_response_code( $r ),
		(string) wp_remote_retrieve_body( $r ),
		array(
			'content-type'        => (string) wp_remote_retrieve_header( $r, 'content-type' ),
			'content-disposition' => (string) wp_remote_retrieve_header( $r, 'content-disposition' ),
		),
	);
}

// Pick an event-shaped type with a published record.
$event_type = '';
$record     = null;
foreach ( array_keys( (array) get_option( 'pcptpages_cpts', array() ) ) as $slug ) {
	if ( ! PCPTPages_Event_Query::is_event_cpt( $slug ) ) {
		continue;
	}
	$posts = get_posts( array( 'post_type' => $slug, 'post_status' => 'publish', 'posts_per_page' => 1 ) );
	if ( $posts ) {
		$event_type = $slug;
		$record     = $posts[0];
		break;
	}
}
$plain_type = '';
foreach ( array_keys( (array) get_option( 'pcptpages_cpts', array() ) ) as $slug ) {
	if ( ! PCPTPages_Event_Query::is_event_cpt( $slug ) ) {
		$plain_type = $slug;
		break;
	}
}

echo "\n0. Fixtures\n";
check( $event_type !== '' && $record, 'an event-shaped type with a published record exists' . ( $event_type ? " ({$event_type} #{$record->ID})" : '' ) );
if ( $event_type === '' ) {
	exit( 1 );
}
// The feed is registered on init; make sure the rewrite rules carry it.
flush_rewrite_rules( false );
// The renderer caches a record's HTML; drop this one's so the page fetched
// below is rendered by the current code.
delete_transient( 'pcptpages_render_' . $record->ID );

echo "\n1. One record's feed\n";
$url = PCPTPages_Event_Calendar::record_feed_url( $record );
check( $url !== '' && strpos( $url, 'feed=ics' ) !== false, 'record_feed_url is the permalink plus ?feed=ics', $url );
list( $code, $body, $headers ) = fetch( $url );
check( $code === 200, 'HTTP 200', "{$code} {$url}" );
check( stripos( (string) ( $headers['content-type'] ?? '' ), 'text/calendar' ) === 0, 'Content-Type text/calendar', (string) ( $headers['content-type'] ?? '' ) );
check( stripos( (string) ( $headers['content-disposition'] ?? '' ), 'attachment' ) === 0, 'downloads as an attachment', (string) ( $headers['content-disposition'] ?? '' ) );
check( strpos( $body, "BEGIN:VCALENDAR\r\n" ) === 0, 'starts with BEGIN:VCALENDAR and CRLF' );
check( substr_count( $body, 'BEGIN:VEVENT' ) === 1, 'exactly one VEVENT' );
check( strpos( $body, 'SUMMARY:' . PCPTPages_Event_Calendar::escape_text( wp_strip_all_tags( get_the_title( $record ) ) ) ) !== false, 'SUMMARY is the record title' );
check( preg_match( '/^DTSTART(;VALUE=DATE)?:\d{8}/m', $body ) === 1, 'DTSTART present' );
check( strpos( $body, 'URL:' . get_permalink( $record ) ) !== false, 'URL is the permalink' );
check( preg_match_all( '/(?<!\r)\n/', $body ) === 0, 'no bare LF anywhere' );
foreach ( explode( "\r\n", rtrim( $body ) ) as $line ) {
	if ( strlen( $line ) > 75 ) {
		check( false, 'every line within 75 octets', substr( $line, 0, 60 ) . '…' );
		break;
	}
}

echo "\n2. The type's subscribable feed\n";
$turl = PCPTPages_Event_Calendar::type_feed_url( $event_type );
check( $turl !== '', 'type_feed_url built by core', $turl );
list( $code, $body, $headers ) = fetch( $turl );
check( $code === 200, 'HTTP 200', "{$code} {$turl}" );
check( stripos( (string) ( $headers['content-type'] ?? '' ), 'text/calendar' ) === 0, 'Content-Type text/calendar' );
check( substr_count( $body, 'BEGIN:VEVENT' ) >= 1, 'carries at least one VEVENT (' . substr_count( $body, 'BEGIN:VEVENT' ) . ')' );
check( strpos( $body, 'X-WR-CALNAME:' ) !== false, 'has a calendar name' );

echo "\n3. Plain-permalink forms work too\n";
list( $code, $body ) = fetch( add_query_arg( array( 'feed' => 'ics', $event_type => $record->post_name ), home_url( '/' ) ) );
check( $code === 200 && substr_count( $body, 'BEGIN:VEVENT' ) === 1, '?' . $event_type . '=slug&feed=ics', (string) $code );
list( $code, $body ) = fetch( add_query_arg( array( 'feed' => 'ics', 'post_type' => $event_type ), home_url( '/' ) ) );
check( $code === 200 && strpos( $body, 'BEGIN:VCALENDAR' ) === 0, '?feed=ics&post_type=' . $event_type, (string) $code );

echo "\n4. A type that is not event-shaped has no calendar\n";
if ( $plain_type !== '' ) {
	check( PCPTPages_Event_Calendar::type_feed_url( $plain_type ) === '', "type_feed_url('{$plain_type}') is empty" );
	list( $code ) = fetch( add_query_arg( array( 'feed' => 'ics', 'post_type' => $plain_type ), home_url( '/' ) ) );
	check( $code === 404, 'its feed answers 404', (string) $code );
} else {
	echo "  (no non-event type on this site — skipped)\n";
}
list( $code ) = fetch( add_query_arg( 'feed', 'ics', home_url( '/' ) ) );
check( $code === 404, 'the site-wide ?feed=ics answers 404', (string) $code );

echo "\n5. The record page carries the links and the discovery <link>\n";
list( $code, $html ) = fetch( get_permalink( $record ) );
check( $code === 200, 'record page 200' );
check( strpos( $html, 'class="pre-hero__calendar"' ) !== false, 'calendar links block present' );
check( strpos( $html, '>Add to calendar<' ) !== false, '"Add to calendar" link' );
check( strpos( $html, 'calendar.google.com/calendar/render' ) !== false, 'Google Calendar link' );
check( strpos( $html, 'type="text/calendar"' ) !== false, 'discovery <link rel="alternate" type="text/calendar">' );

echo "\n6. Connector exposes the type feed\n";
$api = new PCPTPages_Connector_API();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$req = new WP_REST_Request( 'GET', '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE . '/cpts/' . $event_type );
$req->set_url_params( array( 'slug' => $event_type ) );
$resp = $api->handle_get_cpt( $req );
$data = is_wp_error( $resp ) ? array() : $resp->get_data();
check( isset( $data['calendar_feed_url'] ) && $data['calendar_feed_url'] === $turl, 'get_cpt carries calendar_feed_url', json_encode( $data['calendar_feed_url'] ?? null ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
