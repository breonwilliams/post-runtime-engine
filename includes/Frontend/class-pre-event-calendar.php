<?php
/**
 * iCalendar feed for event-shaped record types.
 *
 * Two things a resident expects from a meeting or program listing, both
 * asked for in the RFPs this stack is built for (calendars in four of
 * seven; "add to my calendar" and "subscribe to updates" explicitly):
 *
 *   1. An "Add to calendar" link on a single record that downloads a
 *      standard .ics file (Apple Calendar, Outlook, Thunderbird) plus a
 *      Google Calendar link, which is only a URL.
 *   2. A feed per event type — every upcoming record of that type — that
 *      a calendar app subscribes to once and keeps up to date.
 *
 * Built on WordPress core's own feed mechanism (`add_feed()`): the feed
 * name `ics` joins rss2/atom, so core generates the URLs and the rewrite
 * rules —
 *
 *   /{type}/{slug}/?feed=ics   one record   (the permalink plus the feed var)
 *   /{type}/feed/ics/          the type     (get_post_type_archive_feed_link)
 *
 * — and the plain-permalink forms (?{type}=slug&feed=ics, ?feed=ics&post_type=x)
 * work with nothing extra. No table, no settings, no rewrite rules of our
 * own. The record URL is permalink + `?feed=ics` rather than core's
 * `/feed/ics/` comment-feed form because PRE registers its types with
 * `rewrite.feeds = false` (they carry no comments), so that rule does not
 * exist; the query-var form resolves through the single's own rule. The event data is the same the Schema.org Event emitter reads: the
 * `event_start` / `event_end` / `event_location` / `event_status` semantic
 * roles (docs/EVENTS_VERTICAL_DESIGN.md §5.2), so a type that emits Event
 * JSON-LD is, by the same configuration, a type with a calendar.
 *
 * The iCalendar builders are pure static methods so they are unit-tested
 * without WordPress (tests/Unit/EventCalendarTest.php). RFC 5545 details
 * that matter and are pinned there: CRLF line endings, 75-octet line
 * folding that never splits a multibyte character, text escaping of
 * backslash/semicolon/comma/newline, all-day events as VALUE=DATE with an
 * EXCLUSIVE end (the day after), timed events in UTC.
 *
 * @package PostRuntimeEngine
 * @since 0.8.2
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `ics` feed, renders it, and prints the calendar links.
 */
class PCPTPages_Event_Calendar {

	/** Feed name registered with core (`?feed=ics`, `/feed/ics/`). */
	const FEED = 'ics';

	/**
	 * Option that records the rewrite rules have been flushed once with the
	 * feed registered. Core only writes a feed into the rewrite rules when
	 * they are regenerated, and PRE flushes when the CPT set changes — not
	 * when a plugin update adds a feed. One flush, once, on `wp_loaded`
	 * (after every post type is registered), then never again.
	 */
	const RULES_OPTION = 'pcptpages_ics_feed_rules';

	/**
	 * The type feed carries records whose start is within this many days
	 * in the past, plus everything upcoming — so a subscriber's calendar
	 * keeps last month's meeting after it happened, without carrying a
	 * decade of history on every refresh.
	 */
	const ARCHIVE_LOOKBACK_DAYS = 30;

	/** Hard cap on records in one type feed. */
	const ARCHIVE_LIMIT = 500;

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_feed' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rules' ) );
		add_filter( 'feed_content_type', array( $this, 'content_type' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'discovery_link' ), 5 );
		add_action( 'pcptpages_single_hero_after_meta', array( $this, 'render_links' ) );
	}

	/**
	 * Register `ics` as a feed name with core.
	 *
	 * @return void
	 */
	public function register_feed() {
		add_feed( self::FEED, array( $this, 'render_feed' ) );
	}

	/**
	 * One-time rewrite flush so the feed appears in core's feed regex.
	 *
	 * @return void
	 */
	public function maybe_flush_rules() {
		if ( get_option( self::RULES_OPTION ) === '1' ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::RULES_OPTION, '1', false );
	}

	/**
	 * `feed_content_type` filter: our feed is text/calendar.
	 *
	 * @param string $type Content type.
	 * @param string $feed Feed name.
	 * @return string
	 */
	public function content_type( $type, $feed ) {
		return ( $feed === self::FEED ) ? 'text/calendar' : $type;
	}

	/**
	 * Whether a post type is event-shaped (maps an event_start role).
	 *
	 * @param string $type Post type slug.
	 * @return bool
	 */
	public static function is_event_type( $type ) {
		return is_string( $type ) && $type !== '' && class_exists( 'PCPTPages_Event_Query' )
			&& PCPTPages_Event_Query::is_event_cpt( $type );
	}

	/**
	 * Feed URL for one record, or '' when its type has no calendar.
	 *
	 * @param int|WP_Post $post Post.
	 * @return string
	 */
	public static function record_feed_url( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || ! self::is_event_type( $post->post_type ) ) {
			return '';
		}
		$permalink = get_permalink( $post );
		return is_string( $permalink ) && $permalink !== '' ? add_query_arg( 'feed', self::FEED, $permalink ) : '';
	}

	/**
	 * Subscribable feed URL for a type, or '' when it has no calendar or no
	 * archive.
	 *
	 * @param string $type Post type slug.
	 * @return string
	 */
	public static function type_feed_url( $type ) {
		if ( ! self::is_event_type( $type ) ) {
			return '';
		}
		$url = get_post_type_archive_feed_link( $type, self::FEED );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * `<link rel="alternate" type="text/calendar">` on event singles and
	 * archives, so calendar apps and agents can find the feed.
	 *
	 * @return void
	 */
	public function discovery_link() {
		$url   = '';
		$title = '';
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$url   = self::record_feed_url( $post );
				$title = get_the_title( $post );
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
			$url  = self::type_feed_url( $type );
			$obj  = get_post_type_object( $type );
			$title = $obj ? $obj->labels->name : $type;
		}
		if ( $url === '' ) {
			return;
		}
		printf(
			'<link rel="alternate" type="text/calendar" title="%s" href="%s" />' . "\n",
			esc_attr( sprintf( /* translators: %s: record or type name */ __( '%s (calendar)', 'promptless-cpt-pages' ), $title ) ),
			esc_url( $url )
		);
	}

	/**
	 * "Add to calendar" + "Google Calendar" links under the single hero's
	 * meta. Fired by the renderer through `pcptpages_single_hero_after_meta`.
	 *
	 * @param WP_Post $post The record.
	 * @return void
	 */
	public function render_links( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$ics = self::record_feed_url( $post );
		if ( $ics === '' ) {
			return;
		}
		$event = $this->event_for_post( $post );
		if ( ! $event ) {
			return;
		}
		$google = self::google_calendar_url( $event );
		?>
		<p class="pre-hero__calendar">
			<a class="pre-hero__calendar-link" href="<?php echo esc_url( $ics ); ?>" download><?php esc_html_e( 'Add to calendar', 'promptless-cpt-pages' ); ?></a>
			<?php if ( $google !== '' ) : ?>
				<a class="pre-hero__calendar-link" href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google Calendar', 'promptless-cpt-pages' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'promptless-cpt-pages' ); ?></span></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Feed callback (core's `do_feed_ics`). One record on a singular
	 * request, the type's upcoming records on an archive request, 404
	 * for anything else.
	 *
	 * @return void
	 */
	public function render_feed() {
		$posts       = array();
		$name        = '';
		$filename    = 'calendar.ics';
		$disposition = 'inline';

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post || ! self::is_event_type( $post->post_type ) ) {
				$this->not_found();
				return;
			}
			$posts       = array( $post );
			$name        = get_the_title( $post );
			$filename    = ( $post->post_name !== '' ? $post->post_name : 'event' ) . '.ics';
			$disposition = 'attachment';
		} else {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
			$type = sanitize_key( $type );
			if ( ! self::is_event_type( $type ) ) {
				$this->not_found();
				return;
			}
			$posts    = $this->archive_posts( $type );
			$obj      = get_post_type_object( $type );
			$name     = $obj ? $obj->labels->name : $type;
			$filename = $type . '.ics';
		}

		$events = array();
		foreach ( $posts as $p ) {
			$e = $this->event_for_post( $p );
			if ( $e ) {
				$events[] = $e;
			}
		}

		$ics = self::build_calendar( $events, $name . ' — ' . get_bloginfo( 'name' ) );

		header( 'Content-Type: text/calendar; charset=' . get_option( 'blog_charset' ), true );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( $filename ) . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545 by the builders.
		echo $ics;
	}

	/**
	 * A 404 for a feed request that has no calendar behind it.
	 *
	 * @return void
	 */
	private function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8', true );
		echo 'No calendar feed for this address.';
	}

	/**
	 * The records a type's feed carries: published, start within the
	 * look-back window or in the future, soonest first, capped.
	 *
	 * @param string $type Post type slug.
	 * @return WP_Post[]
	 */
	private function archive_posts( $type ) {
		$start_key = PCPTPages_Event_Query::resolve_role_field_key( $type, 'event_start' );
		if ( $start_key === '' ) {
			return array();
		}
		$sort_key = PCPTPages_Event_Query::sort_meta_key( $start_key );
		$since    = (int) wp_date( 'YmdHis', time() - self::ARCHIVE_LOOKBACK_DAYS * DAY_IN_SECONDS );

		$args = array(
			'post_type'           => $type,
			'post_status'         => 'publish',
			'posts_per_page'      => self::ARCHIVE_LIMIT,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_key'            => $sort_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'             => 'meta_value_num',
			'order'               => 'ASC',
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => $sort_key,
					'value'   => $since,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		);

		/**
		 * Filter the query behind a type's calendar feed.
		 *
		 * @param array  $args WP_Query args.
		 * @param string $type Post type slug.
		 */
		$args = apply_filters( 'pcptpages_calendar_feed_query_args', $args, $type );

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Resolve one record into the plain array the builders take.
	 *
	 * @param WP_Post $post The record.
	 * @return array|null Null when the record has no usable start.
	 */
	public function event_for_post( WP_Post $post ) {
		$plugin = pcptpages();
		$fields = $plugin->post_fields->get_all( $post->post_type );

		$by_role = array();
		foreach ( (array) $fields as $key => $def ) {
			$role = is_array( $def ) ? ( $def['semantic_role'] ?? '' ) : '';
			if ( $role !== '' && ! isset( $by_role[ $role ] ) ) {
				$by_role[ $role ] = array(
					'key' => $key,
					'def' => $def,
				);
			}
		}
		if ( empty( $by_role['event_start'] ) ) {
			return null;
		}

		$site_tz = wp_timezone()->getName();
		$start   = $this->read_date( $post->ID, $by_role['event_start'], $site_tz );
		if ( $start === '' ) {
			return null;
		}
		$end = ! empty( $by_role['event_end'] ) ? $this->read_date( $post->ID, $by_role['event_end'], $site_tz ) : '';

		$location = '';
		if ( ! empty( $by_role['event_location'] ) ) {
			$loc      = $plugin->post_data->get_field_value( $post->ID, $by_role['event_location']['key'] );
			$location = is_scalar( $loc ) ? trim( (string) $loc ) : '';
		}

		$status = 'CONFIRMED';
		if ( ! empty( $by_role['event_status'] ) ) {
			$val    = $plugin->post_data->get_field_value( $post->ID, $by_role['event_status']['key'] );
			$status = self::map_status( is_scalar( $val ) ? (string) $val : '' );
		}

		$description = has_excerpt( $post->ID )
			? get_the_excerpt( $post->ID )
			: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 55 );
		$description = trim( wp_strip_all_tags( (string) $description ) );

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'uid'         => 'pre-' . $post->ID . '@' . ( is_string( $host ) && $host !== '' ? $host : 'localhost' ),
			'summary'     => wp_strip_all_tags( get_the_title( $post ) ),
			'description' => $description,
			'location'    => $location,
			'url'         => get_permalink( $post ),
			'start'       => $start,
			'end'         => $end,
			'status'      => $status,
			'stamp'       => gmdate( 'Ymd\THis\Z' ),
			'modified'    => gmdate( 'Ymd\THis\Z', (int) strtotime( $post->post_modified_gmt . ' UTC' ) ),
		);
	}

	/**
	 * A role's date as the Schema emitter formats it: 'YYYY-MM-DD' for an
	 * all-day (or time-less) value, ISO-8601 with offset for a timed one.
	 *
	 * @param int    $post_id    Post ID.
	 * @param array  $role_entry {key, def}.
	 * @param string $site_tz    Site timezone name.
	 * @return string
	 */
	private function read_date( $post_id, $role_entry, $site_tz ) {
		$val = pcptpages()->post_data->get_field_value( $post_id, $role_entry['key'] );
		if ( ! is_scalar( $val ) || (string) $val === '' ) {
			return '';
		}
		$def = is_array( $role_entry['def'] ) ? $role_entry['def'] : array();
		return PCPTPages_Event_Schema::format_schema_date(
			(string) $val,
			! empty( $def['all_day'] ),
			isset( $def['event_timezone'] ) ? (string) $def['event_timezone'] : '',
			$site_tz
		);
	}

	/* ---------------------------------------------------------------------
	 * Pure builders (no WordPress) — pinned by tests/Unit/EventCalendarTest.php
	 * ------------------------------------------------------------------- */

	/**
	 * Map a stored event_status value onto an iCalendar STATUS.
	 *
	 * @param string $val Stored value.
	 * @return string CONFIRMED | TENTATIVE | CANCELLED
	 */
	public static function map_status( $val ) {
		$v = strtolower( trim( (string) $val ) );
		if ( $v === 'cancelled' || $v === 'canceled' ) {
			return 'CANCELLED';
		}
		if ( $v === 'postponed' || $v === 'rescheduled' ) {
			return 'TENTATIVE';
		}
		return 'CONFIRMED';
	}

	/**
	 * A complete VCALENDAR document.
	 *
	 * @param array[] $events Events (see event_for_post for the shape).
	 * @param string  $name   Calendar display name (X-WR-CALNAME).
	 * @return string
	 */
	public static function build_calendar( array $events, $name ) {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Post Runtime Engine//Calendar//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape_text( (string) $name ),
			'X-PUBLISHED-TTL:PT1H',
		);
		foreach ( $events as $event ) {
			$vevent = self::vevent_lines( (array) $event );
			if ( $vevent ) {
				$lines = array_merge( $lines, $vevent );
			}
		}
		$lines[] = 'END:VCALENDAR';

		$folded = array();
		foreach ( $lines as $line ) {
			$folded[] = self::fold_line( $line );
		}
		return implode( "\r\n", $folded ) . "\r\n";
	}

	/**
	 * The lines of one VEVENT, unfolded. Empty when the event has no usable
	 * start.
	 *
	 * @param array $e Event.
	 * @return string[]
	 */
	public static function vevent_lines( array $e ) {
		$dates = self::date_properties( (string) ( $e['start'] ?? '' ), (string) ( $e['end'] ?? '' ) );
		if ( ! $dates ) {
			return array();
		}
		$lines   = array( 'BEGIN:VEVENT' );
		$lines[] = 'UID:' . self::escape_text( (string) ( $e['uid'] ?? '' ) );
		$lines[] = 'DTSTAMP:' . ( isset( $e['stamp'] ) && $e['stamp'] !== '' ? $e['stamp'] : gmdate( 'Ymd\THis\Z' ) );
		$lines   = array_merge( $lines, $dates );
		$lines[] = 'SUMMARY:' . self::escape_text( (string) ( $e['summary'] ?? '' ) );
		if ( ! empty( $e['description'] ) ) {
			$lines[] = 'DESCRIPTION:' . self::escape_text( (string) $e['description'] );
		}
		if ( ! empty( $e['location'] ) ) {
			$lines[] = 'LOCATION:' . self::escape_text( (string) $e['location'] );
		}
		if ( ! empty( $e['url'] ) ) {
			$lines[] = 'URL:' . (string) $e['url'];
		}
		$lines[] = 'STATUS:' . ( in_array( $e['status'] ?? '', array( 'CONFIRMED', 'TENTATIVE', 'CANCELLED' ), true ) ? $e['status'] : 'CONFIRMED' );
		if ( ! empty( $e['modified'] ) ) {
			$lines[] = 'LAST-MODIFIED:' . (string) $e['modified'];
		}
		$lines[] = 'SEQUENCE:0';
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * DTSTART / DTEND properties for a start and optional end.
	 *
	 * A date-only start ('YYYY-MM-DD') is an all-day event: VALUE=DATE, and
	 * DTEND is the day AFTER the last day, because RFC 5545 ends are
	 * exclusive — a one-day event on the 12th is DTSTART 12, DTEND 13. A
	 * timed start (ISO-8601 with offset) is converted to UTC.
	 *
	 * @param string $start Start as format_schema_date() returns it.
	 * @param string $end   End, same format, or ''.
	 * @return string[] Property lines, or empty when the start is unusable.
	 */
	public static function date_properties( $start, $end = '' ) {
		$start = trim( $start );
		if ( $start === '' ) {
			return array();
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			$last = preg_match( '/^\d{4}-\d{2}-\d{2}/', $end ) ? substr( $end, 0, 10 ) : $start;
			try {
				$end_exclusive = ( new DateTimeImmutable( $last . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Ymd' );
			} catch ( \Exception $ex ) {
				return array();
			}
			return array(
				'DTSTART;VALUE=DATE:' . str_replace( '-', '', $start ),
				'DTEND;VALUE=DATE:' . $end_exclusive,
			);
		}
		$utc_start = self::to_utc( $start );
		if ( $utc_start === '' ) {
			return array();
		}
		$lines   = array( 'DTSTART:' . $utc_start );
		$utc_end = $end !== '' ? self::to_utc( $end ) : '';
		if ( $utc_end !== '' && $utc_end > $utc_start ) {
			$lines[] = 'DTEND:' . $utc_end;
		}
		return $lines;
	}

	/**
	 * An ISO-8601 value (with offset) as an iCalendar UTC timestamp.
	 *
	 * @param string $iso ISO-8601 string.
	 * @return string 'YYYYMMDDTHHMMSSZ' or '' when unparseable.
	 */
	public static function to_utc( $iso ) {
		$iso = trim( (string) $iso );
		if ( $iso === '' ) {
			return ''; // DateTimeImmutable('') would mean "now".
		}
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( \Exception $ex ) {
			return '';
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/**
	 * RFC 5545 §3.3.11 TEXT escaping.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function escape_text( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $text );
		return str_replace( "\n", '\\n', $text );
	}

	/**
	 * RFC 5545 §3.1 line folding: at most 75 octets per line, continuation
	 * lines begin with one space, and a multibyte character is never split
	 * (mb_strcut cuts on a character boundary).
	 *
	 * @param string $line One unfolded content line.
	 * @return string The line, folded with CRLF + space where needed.
	 */
	public static function fold_line( $line ) {
		$line  = (string) $line;
		$out   = array();
		$first = true;
		while ( true ) {
			$limit = $first ? 75 : 74;
			if ( strlen( $line ) <= $limit ) {
				$out[] = ( $first ? '' : ' ' ) . $line;
				break;
			}
			$chunk = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, $limit, 'UTF-8' ) : substr( $line, 0, $limit );
			if ( $chunk === '' ) {
				$chunk = substr( $line, 0, $limit );
			}
			$out[] = ( $first ? '' : ' ' ) . $chunk;
			$line  = substr( $line, strlen( $chunk ) );
			$first = false;
		}
		return implode( "\r\n", $out );
	}

	/**
	 * A Google Calendar "add event" URL for an event, or '' when it cannot be
	 * dated.
	 *
	 * @param array $e Event.
	 * @return string
	 */
	public static function google_calendar_url( array $e ) {
		$start = (string) ( $e['start'] ?? '' );
		$end   = (string) ( $e['end'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			$props = self::date_properties( $start, $end );
			if ( ! $props ) {
				return '';
			}
			$dates = substr( $props[0], strlen( 'DTSTART;VALUE=DATE:' ) ) . '/' . substr( $props[1], strlen( 'DTEND;VALUE=DATE:' ) );
		} else {
			$s = self::to_utc( $start );
			if ( $s === '' ) {
				return '';
			}
			$en    = $end !== '' ? self::to_utc( $end ) : '';
			$dates = $s . '/' . ( $en !== '' && $en > $s ? $en : $s );
		}
		$params = array(
			'action' => 'TEMPLATE',
			'text'   => (string) ( $e['summary'] ?? '' ),
			'dates'  => $dates,
		);
		if ( ! empty( $e['description'] ) ) {
			$params['details'] = (string) $e['description'];
		}
		if ( ! empty( $e['location'] ) ) {
			$params['location'] = (string) $e['location'];
		}
		return 'https://calendar.google.com/calendar/render?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}
}
