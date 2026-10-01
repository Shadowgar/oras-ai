<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Least-privilege adapter for current published The Events Calendar records.
 */
final class ORAS_AI_Events_Calendar_Connector implements ORAS_AI_Observable_Live_Connector_Interface {

	const CONNECTOR = 'events_calendar';

	private $event_loader;
	private $now_provider;
	private $offering_loader;

	public function __construct( $event_loader = null, $now_provider = null, $offering_loader = null ) {
		$this->event_loader = is_callable( $event_loader ) ? $event_loader : null;
		$this->now_provider = is_callable( $now_provider ) ? $now_provider : null;
		$this->offering_loader = is_callable( $offering_loader ) ? $offering_loader : null;
	}

	public function supports( ORAS_AI_Live_Request $request ) {
		return null !== $this->route( $request );
	}

	public function connector_id() {
		return self::CONNECTOR;
	}

	public function is_available() {
		return null !== $this->event_loader || (
			function_exists( 'tribe_get_events' )
			&& function_exists( 'tribe_get_event' )
			&& function_exists( 'tribe_get_event_link' )
		);
	}

	public function required_fact_keys( ORAS_AI_Live_Request $request ) {
		$route = $this->route( $request );
		return is_array( $route ) && empty( $route['reason'] ) ? $route['fact_keys'] : array();
	}

	public function fetch( ORAS_AI_Live_Request $request ) {
		$route = $this->route( $request );
		if ( null === $route ) {
			return ORAS_AI_Live_Result::unknown( 'event_query_not_supported' );
		}
		if ( ! in_array( 'public', $request->allowed_visibilities(), true ) ) {
			return ORAS_AI_Live_Result::denied( 'event_visibility_denied' );
		}
		if ( ! empty( $route['reason'] ) ) {
			return ORAS_AI_Live_Result::unknown( $route['reason'] );
		}

		$routed_request = $request->with_route( self::CONNECTOR, $route['subject'], $route['fact_keys'] );
		try {
			$events = null === $this->event_loader
				? $this->load_from_tec( $routed_request->subject() )
				: call_user_func( $this->event_loader, $routed_request->subject() );
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Live_Result::unknown( 'event_lookup_failed' );
		}

		if ( is_wp_error( $events ) ) {
			return 'oras_ai_events_unavailable' === $events->get_error_code()
				? ORAS_AI_Live_Result::unavailable( 'events_connector_unavailable' )
				: ORAS_AI_Live_Result::unknown( 'event_lookup_failed' );
		}
		if ( ! is_array( $events ) ) {
			return ORAS_AI_Live_Result::unknown( 'event_data_malformed' );
		}

		$normalized    = array();
		$saw_malformed = false;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ( 'upcoming' !== $routed_request->subject()
				&& ! $this->title_matches_subject( $event['title'] ?? '', $routed_request->subject() ) ) ) {
				continue;
			}

			$record = $this->normalize_event( $event );
			if ( is_wp_error( $record ) ) {
				$saw_malformed = true;
				continue;
			}
			$normalized[] = $record;
		}

		if ( empty( $normalized ) ) {
			return ORAS_AI_Live_Result::unknown( $saw_malformed ? 'event_data_malformed' : 'event_not_found' );
		}

		usort(
			$normalized,
			static function ( array $left, array $right ) {
				$start = strcmp( $left['start'], $right['start'] );
				if ( 0 !== $start ) {
					return $start;
				}

				return (int) $left['id'] <=> (int) $right['id'];
			}
		);

		$event = $normalized[0];
		$selected_registration = null;
		if ( 'upcoming' === $routed_request->subject() ) {
			foreach ( $normalized as $candidate ) {
				$offering = $this->registration_fact( $candidate, 'event:upcoming:registration' );
				if ( $offering instanceof ORAS_AI_Live_Fact && 'open' === $offering->field( 'comparison_value' ) ) {
					$event = $candidate;
					$selected_registration = $offering;
					break;
				}
			}
		}
		$facts = array();
		$failures = array();
		foreach ( $routed_request->fact_keys() as $fact_key ) {
			$field = substr( $fact_key, strrpos( $fact_key, ':' ) + 1 );
			if ( 'registration' === $field ) {
				$offering = $selected_registration ?: $this->registration_fact( $event, $fact_key );
				if ( is_wp_error( $offering ) ) {
					$failures[] = array( 'connector' => self::CONNECTOR, 'reason' => 'event_offering_unavailable', 'fact_keys' => array( $fact_key ) );
				} else {
					$facts[] = $offering;
				}
				continue;
			}
			if ( 'venue' === $field && '' === $event['venue'] ) {
				return ORAS_AI_Live_Result::unknown( 'event_data_malformed' );
			}

			$text = $this->fact_text( $event, $field );
			if ( '' === $text ) {
				return ORAS_AI_Live_Result::unknown( 'event_data_malformed' );
			}

			$fact = ORAS_AI_Live_Fact::from_array(
				array(
					'fact_key'            => $fact_key,
					'source_title'        => $event['title'],
					'source_wp_object_id' => $event['id'],
					'source_type'         => 'tribe_events',
					'canonical_url'       => $event['canonical_url'],
					'relevant_text'       => $text,
					'comparison_value'    => $event[ $field ],
					'visibility'          => 'public',
					'source_modified_gmt' => $event['modified_gmt'],
					'retrieved_at'        => $event['retrieved_at'],
				)
			);
			if ( is_wp_error( $fact ) ) {
				return ORAS_AI_Live_Result::unknown( 'event_data_malformed' );
			}
			$facts[] = $fact;
		}

		return ORAS_AI_Live_Result::success( $facts, $failures );
	}

	private function route( ORAS_AI_Live_Request $request ) {
		if ( ORAS_AI_Retrieval_Request::INTENT_HISTORICAL === $request->intent() ) {
			return null;
		}

		$question       = strtolower( trim( wp_strip_all_tags( $request->question(), true ) ) );
		$is_astroblast  = (bool) preg_match( '/\bastro\s*blast\b/', $question );
		$is_public_night = (bool) preg_match( '/\bpublic\s+night\b/', $question );
		$current_signal = (bool) preg_match( '/\b(next|upcoming|when|where|start|starts|end|ends|time|schedule|venue|location|today|tomorrow|weekend|register|registration|tickets?|spots?|book|available|availability|sold out)\b/', $question );

		if ( ! $current_signal ) {
			return null;
		}
		if ( $is_astroblast && $is_public_night ) {
			return array( 'reason' => 'ambiguous_event_subject' );
		}
		if ( ! $is_astroblast && ! $is_public_night ) {
			if ( preg_match( '/\b(?:next|upcoming)\s+events?\b/', $question ) ) {
				return array(
					'subject' => 'upcoming',
					'fact_keys' => array( 'event:upcoming:start', 'event:upcoming:end', 'event:upcoming:registration' ),
					'reason' => '',
				);
			}
			return (bool) preg_match( '/\b(event|events|register|registration|tickets?|spots?|book)\b/', $question )
				? array( 'reason' => 'ambiguous_event_subject' )
				: null;
		}

		$subject   = $is_astroblast ? 'astroblast' : 'public-night';
		$fact_keys = array();
		$needs_schedule = (bool) preg_match( '/\b(next|upcoming|when|schedule|today|tomorrow|weekend)\b/', $question );
		if ( $needs_schedule ) {
			$fact_keys[] = 'event:' . $subject . ':start';
			$fact_keys[] = 'event:' . $subject . ':end';
		}
		if ( ! $needs_schedule && preg_match( '/\b(start|starts|time)\b/', $question ) ) {
			$fact_keys[] = 'event:' . $subject . ':start';
		}
		if ( ! $needs_schedule && preg_match( '/\b(end|ends)\b/', $question ) ) {
			$fact_keys[] = 'event:' . $subject . ':end';
		}
		if ( preg_match( '/\b(where|venue|location)\b/', $question ) ) {
			$fact_keys[] = 'event:' . $subject . ':venue';
		}
		if ( preg_match( '/\b(register|registration|tickets?|spots?|book|sold out)\b/', $question )
			|| ( ! preg_match( '/\bobserver\s+pass(?:es)?\b/', $question )
				&& preg_match( '/\b(available|availability)\b/', $question ) ) ) {
			$fact_keys[] = 'event:' . $subject . ':start';
			$fact_keys[] = 'event:' . $subject . ':end';
			$fact_keys[] = 'event:' . $subject . ':registration';
		}

		return array(
			'subject'   => $subject,
			'fact_keys' => ORAS_AI_Live_Request::normalize_fact_keys( $fact_keys ),
			'reason'    => '',
		);
	}

	private function title_matches_subject( $title, $subject ) {
		$title = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $title ) );

		return 'astroblast' === $subject
			? false !== strpos( $title, 'astroblast' )
			: false !== strpos( $title, 'publicnight' );
	}

	private function normalize_event( array $event ) {
		$title    = sanitize_text_field( (string) ( $event['title'] ?? '' ) );
		$status   = sanitize_key( $event['status'] ?? '' );
		$timezone = sanitize_text_field( (string) ( $event['timezone'] ?? '' ) );
		$url      = trim( (string) ( $event['canonical_url'] ?? '' ) );

		try {
			$zone = new DateTimeZone( $timezone );
			$start = $this->normalize_datetime( $event['start'] ?? '', $zone );
			$end   = $this->normalize_datetime( $event['end'] ?? '', $zone );
			$now   = $this->normalize_datetime( $this->now_value(), $zone );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'oras_ai_event_malformed', __( 'The event data was malformed.', 'oras-ai-assistant' ) );
		}

		if (
			'' === $title
			|| 0 === absint( $event['id'] ?? 0 )
			|| 'publish' !== $status
			|| '' === $timezone
			|| '' === $url
			|| null === $start
			|| null === $end
			|| null === $now
			|| $end < $start
			|| $end < $now
		) {
			return new WP_Error( 'oras_ai_event_malformed', __( 'The event data was malformed.', 'oras-ai-assistant' ) );
		}

		return array(
			'id'            => absint( $event['id'] ?? 0 ),
			'title'         => $title,
			'start'         => $start->format( DATE_ATOM ),
			'end'           => $end->format( DATE_ATOM ),
			'timezone'      => $timezone,
			'venue'         => sanitize_text_field( (string) ( $event['venue'] ?? '' ) ),
			'canonical_url' => $url,
			'modified_gmt'  => sanitize_text_field( (string) ( $event['modified_gmt'] ?? '' ) ),
			'retrieved_at'  => $now->format( DATE_ATOM ),
		);
	}

	private function normalize_datetime( $value, DateTimeZone $zone ) {
		if ( $value instanceof DateTimeInterface ) {
			return new DateTimeImmutable( $value->format( DATE_ATOM ) );
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return new DateTimeImmutable( trim( $value ), $zone );
	}

	private function now_value() {
		return null === $this->now_provider
			? current_time( 'mysql' )
			: call_user_func( $this->now_provider );
	}

	private function fact_text( array $event, $field ) {
		if ( 'start' === $field ) {
			return sprintf( '%1$s starts at %2$s (%3$s).', $event['title'], $event['start'], $event['timezone'] );
		}
		if ( 'end' === $field ) {
			return sprintf( '%1$s ends at %2$s (%3$s).', $event['title'], $event['end'], $event['timezone'] );
		}
		if ( 'venue' === $field ) {
			return sprintf( '%1$s is at %2$s.', $event['title'], $event['venue'] );
		}

		return '';
	}

	/** Project only public provider state; never expose ticket, product, or attendee records. */
	private function registration_fact( array $event, $fact_key ) {
		try {
			$offerings = null === $this->offering_loader
				? $this->load_oras_offerings( $event['id'] )
				: call_user_func( $this->offering_loader, $event['id'] );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'event_offering_lookup_failed' );
		}
		if ( is_wp_error( $offerings ) || ! is_array( $offerings ) || empty( $offerings ) ) {
			return new WP_Error( 'event_offering_unavailable' );
		}
		$states = array();
		$unsafe_link_seen = false;
		foreach ( $offerings as $offering ) {
			$state = is_array( $offering ) && in_array( $offering['state'] ?? '', array( 'open', 'full', 'closed', 'unknown' ), true )
				? $offering['state'] : 'unknown';
			if ( is_array( $offering ) && isset( $offering['url'] )
				&& (string) $offering['url'] !== $event['canonical_url'] ) {
				$unsafe_link_seen = true;
				if ( 'open' === $state ) {
					$state = 'unknown';
				}
			}
			$states[] = $state;
		}
		$state = in_array( 'open', $states, true ) ? 'open'
			: ( 1 === count( array_unique( $states ) ) ? $states[0] : 'unknown' );
		$link_qualified = 'open' === $state || ! $unsafe_link_seen;
		$descriptions = array(
			'open' => 'Registration is currently open for %s.',
			'full' => 'Registration is currently full for %s.',
			'closed' => 'Registration is currently closed for %s.',
			'unknown' => 'Registration availability could not be verified for %s.',
		);
		return ORAS_AI_Live_Fact::from_array( array(
			'fact_key' => $fact_key,
			'source_title' => $event['title'],
			'source_wp_object_id' => $event['id'],
			'source_type' => 'event_offering',
			'canonical_url' => $link_qualified ? $event['canonical_url'] : '',
			'relevant_text' => sprintf( $descriptions[ $state ], $event['title'] ),
			'comparison_value' => $state,
			'visibility' => 'public',
			'retrieved_at' => $event['retrieved_at'],
		) );
	}

	/** Read the event-owned ORAS Tickets collection and RSVP decision without mutations. */
	private function load_oras_offerings( $event_id ) {
		$resolver = 'ORAS\\Tickets\\Domain\\Event_Offering_Resolver';
		$capacity = 'ORAS\\Tickets\\Registration_Desk\\RSVP_Capacity';
		if ( ! class_exists( $resolver ) || ! class_exists( $capacity ) || ! function_exists( 'get_post_meta' ) ) {
			return new WP_Error( 'event_offering_provider_missing' );
		}
		$states = array();
		foreach ( $resolver::resolve_for_event( (int) $event_id ) as $ticket ) {
			if ( ! is_array( $ticket ) || ! in_array( $ticket['sale_state'] ?? '', array( 'on_sale', 'upcoming', 'ended' ), true ) ) {
				$states[] = array( 'state' => 'unknown' );
				continue;
			}
			if ( empty( $ticket['product_exists'] ) ) {
				$state = 'unknown';
			} elseif ( 'on_sale' !== $ticket['sale_state'] ) {
				$state = 'closed';
			} elseif ( 'sold_out' === ( $ticket['availability'] ?? '' ) ) {
				$state = 'full';
			} elseif ( 'available' === ( $ticket['availability'] ?? '' ) && ! empty( $ticket['selectable'] ) ) {
				$state = 'open';
			} else {
				$state = 'unknown';
			}
			$states[] = array( 'state' => $state );
		}
		$rsvp_meta = get_post_meta( (int) $event_id, '_oras_rsvp_v1', true );
		if ( is_array( $rsvp_meta ) && ! empty( $rsvp_meta['enabled'] ) ) {
			$rsvp = $capacity::state( (int) $event_id );
			$state = 'open' !== ( $rsvp['window_state'] ?? '' ) ? 'closed'
				: ( 'admit' === ( $rsvp['decision'] ?? '' ) ? 'open'
				: ( in_array( $rsvp['decision'] ?? '', array( 'waitlist', 'refuse' ), true ) ? 'full' : 'unknown' ) );
			$states[] = array( 'state' => $state );
		}
		return $states;
	}

	private function load_from_tec( $subject ) {
		if (
			! function_exists( 'tribe_get_events' )
			|| ! function_exists( 'tribe_get_event' )
			|| ! function_exists( 'tribe_get_event_link' )
		) {
			return new WP_Error( 'oras_ai_events_unavailable', __( 'The Events Calendar is unavailable.', 'oras-ai-assistant' ) );
		}

		$search = 'astroblast' === $subject ? 'AstroBlast' : ( 'public-night' === $subject ? 'Public Night' : '' );
		$posts  = tribe_get_events(
			array(
				'eventDisplay'   => 'custom',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'start_date'     => current_time( 'Y-m-d' ),
				'orderby'        => 'event_date',
				'order'          => 'ASC',
				's'              => $search,
			)
		);
		if ( ! is_array( $posts ) ) {
			return new WP_Error( 'oras_ai_events_invalid', __( 'The event lookup failed.', 'oras-ai-assistant' ) );
		}

		$events = array();
		foreach ( $posts as $post ) {
			$decorated = tribe_get_event( $post );
			if ( ! is_object( $decorated ) ) {
				$events[] = array();
				continue;
			}

			$id = absint( $decorated->ID ?? ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post ) );
			$events[] = array(
				'id'            => $id,
				'title'         => (string) ( $decorated->post_title ?? '' ),
				'status'        => (string) ( $decorated->post_status ?? '' ),
				'start'         => $decorated->dates->start ?? ( $decorated->start_date ?? '' ),
				'end'           => $decorated->dates->end ?? ( $decorated->end_date ?? '' ),
				'timezone'      => (string) ( $decorated->timezone ?? '' ),
				'venue'         => function_exists( 'tribe_get_venue' ) ? (string) tribe_get_venue( $id ) : '',
				'canonical_url' => (string) tribe_get_event_link( $id, false ),
				'modified_gmt'  => (string) ( $decorated->post_modified_gmt ?? '' ),
			);
		}

		return $events;
	}
}
