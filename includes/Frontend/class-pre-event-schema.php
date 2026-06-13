<?php
/**
 * Schema.org Event JSON-LD emitter (events vertical, v1.2).
 *
 * PRE owns the schema for the CPT single pages it renders, so it emits the
 * Event JSON-LD itself via `wp_head` rather than routing through Promptless
 * WP's SchemaRegistry. This keeps the one-way decoupling intact: PRE works
 * standalone, has no PHP dependency on Promptless, and its Event node
 * coexists with Promptless's WebPage node (distinct @type).
 *
 * Emits only when:
 *   - the request is a singular view of a PRE-registered CPT,
 *   - `_aisb_enabled` is NOT set on the post (Promptless precedence — a
 *     flagship page hand-built in Promptless owns its own head), and
 *   - the CPT maps an `event_start` semantic role.
 *
 * Field → schema mapping (design contract § 8; Google Event requirements):
 *   required    name (post title), startDate, location
 *   recommended endDate, eventStatus, eventAttendanceMode, image, description, offers
 *
 * All-day dates emit date-only (YYYY-MM-DD); timed dates emit ISO-8601 with
 * the resolved UTC offset, per schema.org guidance.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and prints the Event JSON-LD block.
 */
class PCPTPages_Event_Schema {

	/**
	 * Register the wp_head emitter.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'emit' ), 20 );
	}

	/**
	 * wp_head callback. Gate, build, filter, print.
	 *
	 * @return void
	 */
	public function emit() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Promptless precedence: a page hand-built in Promptless owns its head.
		if ( get_post_meta( $post->ID, '_aisb_enabled', true ) ) {
			return;
		}

		// Event-shaped only.
		if ( PCPTPages_Event_Query::resolve_role_field_key( $post->post_type, 'event_start' ) === '' ) {
			return;
		}

		$schema = $this->build_schema( $post->ID, $post->post_type );
		if ( empty( $schema ) ) {
			return;
		}

		/**
		 * Filter the assembled Event schema array before it is encoded.
		 *
		 * @param array  $schema  The schema.org Event array.
		 * @param int    $post_id The event post ID.
		 * @param string $cpt     The CPT slug.
		 */
		$schema = apply_filters( 'pcptpages_event_schema', $schema, $post->ID, $post->post_type );
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}

		echo "\n" . '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>' . "\n";
	}

	/**
	 * Assemble the Event schema array for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cpt     CPT slug.
	 * @return array Empty if the minimum (name + startDate) can't be built.
	 */
	private function build_schema( $post_id, $cpt ) {
		$plugin = pcptpages();
		$fields = $plugin->post_fields->get_all( $cpt );

		// Index the first field per semantic role.
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
			return array();
		}

		$site_tz = wp_timezone()->getName();

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => wp_strip_all_tags( get_the_title( $post_id ) ),
		);

		// startDate (required) + endDate (recommended).
		$start = $this->read_date_for_schema( $post_id, $by_role['event_start'], $site_tz );
		if ( $start === '' ) {
			return array(); // No usable start date — not a valid Event.
		}
		$schema['startDate'] = $start;

		if ( ! empty( $by_role['event_end'] ) ) {
			$end = $this->read_date_for_schema( $post_id, $by_role['event_end'], $site_tz );
			if ( $end !== '' ) {
				$schema['endDate'] = $end;
			}
		}

		// description.
		$excerpt = has_excerpt( $post_id )
			? get_the_excerpt( $post_id )
			: wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 55 );
		$excerpt = trim( wp_strip_all_tags( (string) $excerpt ) );
		if ( $excerpt !== '' ) {
			$schema['description'] = $excerpt;
		}

		// image.
		$image = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $image ) {
			$schema['image'] = array( $image );
		}

		// location (recommended/required) — Place from the text field value.
		if ( ! empty( $by_role['event_location'] ) ) {
			$loc = $plugin->post_data->get_field_value( $post_id, $by_role['event_location']['key'] );
			$loc = is_scalar( $loc ) ? trim( (string) $loc ) : '';
			if ( $loc !== '' ) {
				$schema['location'] = array(
					'@type'   => 'Place',
					'name'    => $loc,
					'address' => $loc,
				);
			}
		}

		// eventStatus.
		if ( ! empty( $by_role['event_status'] ) ) {
			$val = $plugin->post_data->get_field_value( $post_id, $by_role['event_status']['key'] );
			$uri = self::map_event_status( is_scalar( $val ) ? (string) $val : '' );
			if ( $uri !== '' ) {
				$schema['eventStatus'] = $uri;
			}
		}

		// eventAttendanceMode.
		if ( ! empty( $by_role['event_attendance_mode'] ) ) {
			$val = $plugin->post_data->get_field_value( $post_id, $by_role['event_attendance_mode']['key'] );
			$uri = self::map_attendance_mode( is_scalar( $val ) ? (string) $val : '' );
			if ( $uri !== '' ) {
				$schema['eventAttendanceMode'] = $uri;
			}
		}

		// offers.
		if ( ! empty( $by_role['event_offers'] ) ) {
			$val = $plugin->post_data->get_field_value( $post_id, $by_role['event_offers']['key'] );
			if ( is_scalar( $val ) && (string) $val !== '' && is_numeric( $val ) ) {
				$currency = $by_role['event_offers']['def']['currency_code'] ?? '';
				if ( $currency === '' ) {
					$currency = (string) apply_filters( 'pcptpages_event_default_currency', 'USD' );
				}
				$schema['offers'] = array(
					'@type'         => 'Offer',
					'price'         => (string) $val,
					'priceCurrency' => strtoupper( $currency ),
					'url'           => get_permalink( $post_id ),
				);
			}
		}

		return $schema;
	}

	/**
	 * Resolve a role field's stored value into a schema-ready date string.
	 *
	 * @param int    $post_id    Post ID.
	 * @param array  $role_entry { key, def } for the role.
	 * @param string $site_tz    Site IANA timezone id.
	 * @return string Schema date string, or '' if unavailable.
	 */
	private function read_date_for_schema( $post_id, $role_entry, $site_tz ) {
		$plugin = pcptpages();
		$val    = $plugin->post_data->get_field_value( $post_id, $role_entry['key'] );
		if ( ! is_scalar( $val ) || (string) $val === '' ) {
			return '';
		}
		$def = is_array( $role_entry['def'] ) ? $role_entry['def'] : array();
		return self::format_schema_date(
			(string) $val,
			! empty( $def['all_day'] ),
			isset( $def['event_timezone'] ) ? (string) $def['event_timezone'] : '',
			$site_tz
		);
	}

	/**
	 * Pure formatter: stored date value → schema.org date string.
	 *
	 * All-day → date-only (YYYY-MM-DD). Timed → ISO-8601 with the resolved
	 * UTC offset (local time preserved, per schema.org). A non-all-day value
	 * that carries no time component degrades to date-only.
	 *
	 * @param string $value    Stored value ('Y-m-d' or 'Y-m-d H:i:s').
	 * @param bool   $all_day  All-day flag.
	 * @param string $event_tz IANA timezone id, or '' for the site timezone.
	 * @param string $site_tz  Site IANA timezone id.
	 * @return string
	 */
	public static function format_schema_date( $value, $all_day, $event_tz, $site_tz ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		$date_part = substr( $value, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_part ) ) {
			return '';
		}
		if ( $all_day ) {
			return $date_part;
		}

		if ( strlen( $value ) > 10 && preg_match( '/\d{1,2}:\d{2}/', $value ) ) {
			$time = trim( substr( $value, 10 ) );
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
				$time .= ':00';
			}
			$wall = $date_part . ' ' . $time;
		} else {
			// No time component on a non-all-day value — emit date-only.
			return $date_part;
		}

		$tz_name = ( is_string( $event_tz ) && $event_tz !== '' ) ? $event_tz : (string) $site_tz;
		try {
			$tz = new DateTimeZone( $tz_name );
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $wall, $tz );
			if ( $dt instanceof DateTimeImmutable ) {
				return $dt->format( 'c' );
			}
		} catch ( \Exception $e ) {
			return '';
		}
		return '';
	}

	/**
	 * Map an event_status field value to a schema.org eventStatus URI.
	 *
	 * @param string $val Raw field value.
	 * @return string URI or '' when unrecognized.
	 */
	public static function map_event_status( $val ) {
		$v   = strtolower( trim( (string) $val ) );
		$map = array(
			'scheduled'    => 'https://schema.org/EventScheduled',
			'sold_out'     => 'https://schema.org/EventScheduled',
			'sold-out'     => 'https://schema.org/EventScheduled',
			'cancelled'    => 'https://schema.org/EventCancelled',
			'canceled'     => 'https://schema.org/EventCancelled',
			'postponed'    => 'https://schema.org/EventPostponed',
			'rescheduled'  => 'https://schema.org/EventRescheduled',
			'moved_online' => 'https://schema.org/EventMovedOnline',
			'moved-online' => 'https://schema.org/EventMovedOnline',
		);
		return $map[ $v ] ?? '';
	}

	/**
	 * Map an event_attendance_mode field value to a schema.org URI.
	 *
	 * @param string $val Raw field value.
	 * @return string URI or '' when unrecognized.
	 */
	public static function map_attendance_mode( $val ) {
		$v   = strtolower( trim( (string) $val ) );
		$map = array(
			'offline'   => 'https://schema.org/OfflineEventAttendanceMode',
			'in_person' => 'https://schema.org/OfflineEventAttendanceMode',
			'in-person' => 'https://schema.org/OfflineEventAttendanceMode',
			'online'    => 'https://schema.org/OnlineEventAttendanceMode',
			'virtual'   => 'https://schema.org/OnlineEventAttendanceMode',
			'mixed'     => 'https://schema.org/MixedEventAttendanceMode',
			'hybrid'    => 'https://schema.org/MixedEventAttendanceMode',
		);
		return $map[ $v ] ?? '';
	}
}
