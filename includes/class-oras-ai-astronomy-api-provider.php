<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only AstronomyAPI adapter for allowlisted Solar System positions.
 */
final class ORAS_AI_Astronomy_API_Provider implements ORAS_AI_Astronomy_Provider_Interface {
	const PROVIDER_ID = 'astronomy_api';
	const BASE_URL    = 'https://api.astronomyapi.com/api/v2/bodies';

	private $application_id;
	private $application_secret;
	private $http_get;
	private $clock;
	private $configured;

	public function __construct(
		$application_id,
		$application_secret,
		?callable $http_get = null,
		?ORAS_AI_Clock_Interface $clock = null
	) {
		$application_id     = trim( (string) $application_id );
		$application_secret = trim( (string) $application_secret );
		if (
			( ( '' === $application_id ) xor ( '' === $application_secret ) )
			|| strlen( $application_id ) > 256
			|| strlen( $application_secret ) > 512
			|| preg_match( '/[\x00-\x1F\x7F]/', $application_id . $application_secret )
		) {
			throw new InvalidArgumentException( 'Invalid AstronomyAPI credentials.' );
		}

		$this->application_id     = $application_id;
		$this->application_secret = $application_secret;
		$this->configured         = '' !== $application_id && '' !== $application_secret;
		$this->http_get           = $http_get ?: static function ( $url, array $arguments ) {
			return wp_remote_get( $url, $arguments );
		};
		$this->clock              = $clock ?: new ORAS_AI_System_Clock();
	}

	public function provider_id() {
		return self::PROVIDER_ID;
	}

	public function fetch( ORAS_AI_Current_Data_Request $request ) {
		if ( ! $this->configured ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'provider_not_configured' );
		}
		if ( ! in_array( ORAS_AI_Current_Data_Request::PLANET_POSITION, $request->fact_types(), true ) ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'unsupported_fact_type' );
		}

		$target = $request->target_identity();
		$body   = $this->body_from_target( $target );
		if ( 'planet:all' !== $target && '' === $body ) {
			return ORAS_AI_Current_Data_Result::denied( self::PROVIDER_ID, 'unsupported_planet' );
		}

		$path = 'planet:all' === $target ? '/positions' : '/positions/' . rawurlencode( $body );
		$url  = self::BASE_URL . $path . '?' . http_build_query( $this->query_arguments( $request ), '', '&', PHP_QUERY_RFC3986 );

		try {
			$response = call_user_func(
				$this->http_get,
				$url,
				array(
					'headers'     => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Basic ' . base64_encode( $this->application_id . ':' . $this->application_secret ),
					),
					'timeout'     => 10,
					'redirection' => 0,
				)
			);
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'provider_request_failed' );
		}

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'provider_unavailable' );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$rows    = $this->extract_rows( $payload );
		$facts   = $this->normalize_rows( $rows, $target, $request );
		if ( empty( $facts ) ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'malformed_provider_response' );
		}

		if ( 'planet:all' === $target ) {
			$found = array();
			foreach ( $facts as $fact ) {
				$found[ $fact->to_array()['target_identity'] ] = true;
			}
			if ( count( $found ) !== count( self::allowed_bodies() ) ) {
				return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'incomplete_provider_response' );
			}
		}

		return ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, $facts );
	}

	public static function allowed_bodies() {
		return ORAS_AI_Planet_Targets::allowed();
	}

	private function body_from_target( $target ) {
		if ( 0 !== strpos( $target, 'planet:' ) ) {
			return '';
		}
		$body = substr( $target, strlen( 'planet:' ) );
		return in_array( $body, self::allowed_bodies(), true ) ? $body : '';
	}

	private function query_arguments( ORAS_AI_Current_Data_Request $request ) {
		$local = $request->local_requested_at();
		return array(
			'latitude'  => $request->site()->latitude(),
			'longitude' => $request->site()->longitude(),
			'elevation' => $request->site()->elevation_meters(),
			'from_date' => $local->format( 'Y-m-d' ),
			'to_date'   => $local->format( 'Y-m-d' ),
			'time'      => $local->format( 'H:i:s' ),
			'output'    => 'rows',
		);
	}

	private function extract_rows( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return array();
		}
		if ( isset( $payload['data']['rows'] ) && is_array( $payload['data']['rows'] ) ) {
			return $payload['data']['rows'];
		}
		if ( isset( $payload['data']['table']['rows'] ) && is_array( $payload['data']['table']['rows'] ) ) {
			return $payload['data']['table']['rows'];
		}
		return array();
	}

	private function normalize_rows( array $rows, $requested_target, ORAS_AI_Current_Data_Request $request ) {
		$facts      = array();
		$calculated = $this->clock->now();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$entry = isset( $row['entry'] ) && is_array( $row['entry'] ) ? $row['entry'] : ( $row['body'] ?? array() );
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$body = strtolower( trim( (string) ( $entry['id'] ?? $entry['name'] ?? '' ) ) );
			if ( ! in_array( $body, self::allowed_bodies(), true ) ) {
				continue;
			}
			$target = 'planet:' . $body;
			if ( 'planet:all' !== $requested_target && $requested_target !== $target ) {
				continue;
			}
			$positions = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : ( $row['positions'] ?? array() );
			$position = isset( $positions[0] ) && is_array( $positions[0] ) ? $positions[0] : array();
			$horizontal = $position['position']['horizontal'] ?? null;
			$altitude   = is_array( $horizontal ) ? ( $horizontal['altitude']['degrees'] ?? null ) : null;
			$azimuth    = is_array( $horizontal ) ? ( $horizontal['azimuth']['degrees'] ?? null ) : null;
			if ( ! is_numeric( $altitude ) || ! is_numeric( $azimuth ) || ! is_finite( (float) $altitude ) || ! is_finite( (float) $azimuth ) ) {
				continue;
			}
			$altitude = (float) $altitude;
			$azimuth  = (float) $azimuth;
			if ( $altitude < -90.0 || $altitude > 90.0 || $azimuth < 0.0 || $azimuth > 360.0 ) {
				continue;
			}
			// Documented responses can also contain the historical misspelling.
			// Keep horizontal authoritative, but reject ambiguous duplicate values.
			if ( array_key_exists( 'horizonal', $position['position'] ) ) {
				$legacy = $position['position']['horizonal'];
				$legacy_altitude = is_array( $legacy ) ? ( $legacy['altitude']['degrees'] ?? null ) : null;
				$legacy_azimuth = is_array( $legacy ) ? ( $legacy['azimuth']['degrees'] ?? null ) : null;
				if ( ! is_numeric( $legacy_altitude ) || ! is_numeric( $legacy_azimuth ) || (float) $legacy_altitude !== $altitude || (float) $legacy_azimuth !== $azimuth ) {
					continue;
				}
			}
			$valid_at = $this->qualified_provider_time( $position['date'] ?? null, $request );
			if ( null === $valid_at ) {
				continue;
			}

			$fact_root = 'astronomy:' . $target;
			$facts[] = new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::PLANET_POSITION, $altitude, 'degrees', $calculated, $valid_at, $target, $fact_root . ':altitude', 'astronomyapi_v2' );
			$facts[] = new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::PLANET_POSITION, $azimuth, 'degrees', $calculated, $valid_at, $target, $fact_root . ':azimuth', 'astronomyapi_v2' );
			$facts[] = new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::PLANET_POSITION, $altitude > 0.0 ? 'above' : 'below', 'geometric_horizon', $calculated, $valid_at, $target, $fact_root . ':geometric_horizon', 'astronomyapi_v2' );
		}
		return $facts;
	}

	/**
	 * Positions are for the requested observer time, not a latest-observation feed.
	 * The wire query uses H:i:s; require exactly that second after UTC/offset
	 * normalization. No nonzero time tolerance or request-time fallback is used.
	 * https://docs.astronomyapi.com/endpoints/bodies/positions
	 */
	private function qualified_provider_time( $value, ORAS_AI_Current_Data_Request $request ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value ) ) {
			return null;
		}
		try {
			$instant = new DateTimeImmutable( $value );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
				return null;
			}
			if ( $instant->format( 'U.u' ) !== $request->requested_at()->format( 'U' ) . '.000000' ) {
				return null;
			}
			return $instant->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Throwable $throwable ) {
			return null;
		}
	}
}
