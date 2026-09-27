<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provider-independent weather planner and normalized evidence mapper. */
final class ORAS_AI_Current_Weather_Service {
	private $provider;
	private $night_resolver;
	private $clock;
	private $observability;

	public function __construct(
		ORAS_AI_Weather_Provider_Interface $provider,
		ORAS_AI_Astronomical_Night_Resolver $night_resolver,
		ORAS_AI_Clock_Interface $clock,
		?ORAS_AI_Astronomy_Observability $observability = null
	) {
		$this->provider       = $provider;
		$this->night_resolver = $night_resolver;
		$this->clock          = $clock;
		$this->observability  = $observability;
	}

	public function query(
		ORAS_AI_Authorized_Request $authorized_request,
		?DateTimeImmutable $requested_at = null,
		?DateTimeImmutable $window_end = null,
		$force_forecast = false,
		?DateTimeImmutable $night_date = null
	) {
		$question = strtolower( trim( wp_strip_all_tags( $authorized_request->question(), true ) ) );
		$matched  = $force_forecast || (bool) preg_match( '/\b(weather|forecast|clouds?|cloudy|rain|snow|precipitation|temperature|wind|humidity|conditions?|seeing|transparency)\b/', $question );
		if ( ! $matched ) {
			return new ORAS_AI_Current_Weather_Query_Result( false, 0, new ORAS_AI_Evidence_Packet() );
		}

		$explicit = $requested_at instanceof DateTimeImmutable || $window_end instanceof DateTimeImmutable;
		if ( $explicit && ( ! $requested_at instanceof DateTimeImmutable || ! $window_end instanceof DateTimeImmutable ) ) {
			return $this->failure( 'invalid_weather_interval', array( 'weather:forecast:interval' ) );
		}

		$current = ! $explicit && null === $night_date && (bool) preg_match( '/\b(now|currently|at the moment|current conditions?)\b/', $question );
		if ( $current ) {
			$requested_at = $this->clock->now();
			$window_end   = $requested_at;
		} elseif ( ! $explicit ) {
			$member_window = null === $night_date ? $this->explicit_window_from_question( $question ) : null;
			if ( null !== $member_window ) {
				list( $requested_at, $window_end ) = $member_window;
			} else {
				$night = $this->night_resolver->resolve( $authorized_request, $night_date ?: $this->night_date( $question ) );
				if ( is_wp_error( $night ) ) {
					return $this->failure( 'night_window_unavailable', array( 'weather:forecast:interval' ) );
				}
				list( $requested_at, $window_end ) = $night;
			}
		}

		try {
			$request = ORAS_AI_Current_Data_Request::from_authorized_request(
				$authorized_request,
				$this->clock,
				array( ORAS_AI_Current_Data_Request::WEATHER_CONDITIONS ),
				$requested_at,
				$window_end
			);
			$result = $this->provider->fetch( $request );
		} catch ( Throwable $throwable ) {
			$result = ORAS_AI_Current_Data_Result::unavailable( $this->provider->provider_id(), 'provider_request_failed' );
		}
		if ( ! $result instanceof ORAS_AI_Current_Data_Result ) {
			$result = ORAS_AI_Current_Data_Result::unknown( $this->provider->provider_id(), 'malformed_provider_response' );
		}
		if ( null !== $this->observability ) {
			$this->observability->record_outcome( $result->provider_id(), $result->status(), $result->reason() );
		}
		if ( ORAS_AI_Current_Data_Result::SUCCESS !== $result->status() ) {
			return $this->failure( $result->reason(), array( $current ? 'weather:observation:current' : 'weather:forecast:interval' ), $requested_at, $window_end );
		}

		$items = array();
		$values = array();
		foreach ( $result->values() as $snapshot ) {
			if ( $snapshot instanceof ORAS_AI_Weather_Snapshot ) {
				$items[] = $this->evidence( $snapshot );
				$values[] = $snapshot;
			}
		}
		return new ORAS_AI_Current_Weather_Query_Result( true, count( $items ), new ORAS_AI_Evidence_Packet( $items ), $values, $requested_at, $window_end );
	}

	private function evidence( ORAS_AI_Weather_Snapshot $snapshot ) {
		$data = $snapshot->to_array();
		$labels = array(
			'cloud_cover_percent'               => 'Cloud cover',
			'precipitation_probability_percent' => 'Precipitation probability',
			'precipitation_type'                => 'Precipitation type',
			'temperature_celsius'               => 'Temperature',
			'wind_speed_mps'                    => 'Wind speed',
			'wind_gust_mps'                     => 'Wind gust',
			'humidity_percent'                  => 'Relative humidity',
			'visibility_meters'                 => 'Visibility',
		);
		$units = array(
			'cloud_cover_percent' => ' percent', 'precipitation_probability_percent' => ' percent',
			'temperature_celsius' => ' C', 'wind_speed_mps' => ' m/s', 'wind_gust_mps' => ' m/s',
			'humidity_percent' => ' percent', 'visibility_meters' => ' meters',
		);
		$parts = array();
		$fact_keys = array();
		$interval_identity = '';
		if ( ORAS_AI_Weather_Snapshot::FORECAST === $data['designation'] ) {
			$from = new DateTimeImmutable( $data['valid_from'] );
			$until = new DateTimeImmutable( $data['valid_until'] );
			$interval_identity = ':from_' . $from->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis' )
				. ':until_' . $until->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis' );
		}
		foreach ( $labels as $field => $label ) {
			$key = 'weather:' . $data['designation'] . ':' . str_replace( array( '_percent', '_celsius', '_mps', '_meters' ), '', $field ) . $interval_identity;
			$fact_keys[] = $key;
			$value = $data[ $field ];
			$parts[] = null === $value || '' === $value
				? strtolower( $label ) . ' unavailable'
				: $label . ' ' . $value . ( $units[ $field ] ?? '' );
		}
		$parts[] = 'Seeing unavailable';
		$parts[] = 'Transparency unavailable';
		$parts[] = ucfirst( $data['designation'] ) . ' issued ' . $data['issued_at'] . ', valid ' . $data['valid_from'] . ' through ' . $data['valid_until'] . '.';

		return ORAS_AI_Evidence::from_array(
			array(
				'source_type'           => 'current_weather',
				'artifact_title'        => 'National Weather Service',
				'source_title'          => 'National Weather Service',
				'canonical_url'         => '',
				'relevant_text'         => implode( '; ', $parts ),
				'visibility'            => 'members',
				'lifecycle'             => 'approved',
				'source_classification' => 'current_data',
				'authority_class'       => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
				'source_modified_gmt'   => $data['issued_at'],
				'synced_at'             => $data['fresh_at'],
				'fact_keys'             => $fact_keys,
			)
		);
	}

	private function failure( $reason, array $fact_keys, ?DateTimeImmutable $requested_at = null, ?DateTimeImmutable $window_end = null ) {
		$reason = sanitize_key( $reason );
		$item = ORAS_AI_Evidence::from_array(
			array(
				'source_type'           => 'current_weather_status',
				'source_title'          => 'National Weather Service',
				'relevant_text'         => 'Required current weather information could not be established (' . $reason . ').',
				'visibility'            => 'members',
				'lifecycle'             => 'approved',
				'source_classification' => 'current_data',
				'authority_class'       => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
				'fact_keys'             => $fact_keys,
			)
		);
		return new ORAS_AI_Current_Weather_Query_Result( true, 0, new ORAS_AI_Evidence_Packet( array( $item ) ), array(), $requested_at, $window_end );
	}

	private function night_date( $question ) {
		$site  = ORAS_AI_Observing_Site::oras_observatory();
		$local = $this->clock->now()->setTimezone( $site->timezone() );
		if ( preg_match( '/\btomorrow\b/', $question ) ) {
			return $local->modify( '+1 day' );
		}
		if ( preg_match( '/\b(20\d{2}-\d{2}-\d{2})\b/', $question, $match ) ) {
			try {
				return new DateTimeImmutable( $match[1] . ' 12:00:00', $site->timezone() );
			} catch ( Throwable $throwable ) {
				return $local;
			}
		}
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $weekday ) {
			if ( preg_match( '/\b' . $weekday . '\b/', $question ) ) {
				return strtolower( $local->format( 'l' ) ) === $weekday ? $local : $local->modify( 'next ' . $weekday );
			}
		}
		return $local;
	}

	private function explicit_window_from_question( $question ) {
		$time = '(\d{1,2})(?::([0-5]\d))?\s*(am|pm)?';
		$date = $this->night_date( $question )->setTimezone( ORAS_AI_Observing_Site::oras_observatory()->timezone() )->format( 'Y-m-d' );
		if ( preg_match( '/\bfrom\s+' . $time . '\s+to\s+' . $time . '\b/', $question, $match ) ) {
			$start = $this->local_time( $date, $match[1], $match[2], $match[3] );
			$end   = $this->local_time( $date, $match[4], $match[5], $match[6] );
			if ( null === $start || null === $end ) {
				return null;
			}
			if ( $end <= $start ) {
				$end = $end->modify( '+1 day' );
			}
			return array( $start, $end );
		}
		if ( preg_match( '/\bat\s+' . $time . '\b/', $question, $match ) ) {
			$instant = $this->local_time( $date, $match[1], $match[2], $match[3] );
			return null === $instant ? null : array( $instant, $instant );
		}
		return null;
	}

	private function local_time( $date, $hour, $minute, $meridiem ) {
		$hour = (int) $hour;
		if ( '' === (string) $meridiem ) {
			if ( '' === (string) $minute || $hour > 23 ) {
				return null;
			}
		} else {
			if ( $hour < 1 || $hour > 12 ) {
				return null;
			}
			$hour = $hour % 12 + ( 'pm' === $meridiem ? 12 : 0 );
		}
		$minute = '' === (string) $minute ? 0 : (int) $minute;
		$timezone = ORAS_AI_Observing_Site::oras_observatory()->timezone();
		$local = new DateTimeImmutable( sprintf( '%s %02d:%02d:00', $date, $hour, $minute ), $timezone );
		return $local->format( 'Y-m-d H:i' ) === sprintf( '%s %02d:%02d', $date, $hour, $minute ) ? $local : null;
	}
}
