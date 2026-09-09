<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trusted provider-independent request for current astronomy/weather data.
 */
final class ORAS_AI_Current_Data_Request {
	const SUNSET                = 'sunset';
	const ASTRONOMICAL_DARKNESS = 'astronomical_darkness';
	const MOON_STATE            = 'moon_state';
	const PLANET_POSITION       = 'planet_position';
	const TARGET_POSITION       = 'target_position';
	const WEATHER_CONDITIONS    = 'weather_conditions';
	const OBSERVING_SCORE       = 'observing_score';

	private $authorized_request;
	private $site;
	private $requested_at;
	private $window_end;
	private $fact_types;
	private $target_identity;

	private function __construct(
		ORAS_AI_Authorized_Request $authorized_request,
		ORAS_AI_Observing_Site $site,
		DateTimeImmutable $requested_at,
		DateTimeImmutable $window_end,
		array $fact_types,
		$target_identity
	) {
		$this->authorized_request = $authorized_request;
		$this->site               = $site;
		$this->requested_at       = $requested_at;
		$this->window_end         = $window_end;
		$this->fact_types         = $fact_types;
		$this->target_identity    = $target_identity;
	}

	public static function from_authorized_request(
		ORAS_AI_Authorized_Request $authorized_request,
		ORAS_AI_Clock_Interface $clock,
		array $fact_types,
		?DateTimeImmutable $requested_at = null,
		?DateTimeImmutable $window_end = null,
		$target_identity = ''
	) {
		$utc = new DateTimeZone( 'UTC' );
		$now = $clock->now()->setTimezone( $utc );
		$requested_at = ( $requested_at ?: $now )->setTimezone( $utc );
		$window_end   = ( $window_end ?: $requested_at )->setTimezone( $utc );

		// This contract carries a finite closed interval. Providers own their
		// supported forecast or calculation horizons and reject unsupported times.
		if ( $requested_at < $now || $window_end < $requested_at ) {
			throw new InvalidArgumentException( 'Invalid current-data time window.' );
		}

		$fact_types = self::normalize_fact_types( $fact_types );
		if ( empty( $fact_types ) ) {
			throw new InvalidArgumentException( 'Current-data fact types are required.' );
		}

		$target_identity = self::normalize_target_identity( $target_identity );
		if (
			( in_array( self::PLANET_POSITION, $fact_types, true ) || in_array( self::TARGET_POSITION, $fact_types, true ) )
			&& '' === $target_identity
		) {
			throw new InvalidArgumentException( 'A normalized target identity is required.' );
		}

		return new self(
			$authorized_request,
			ORAS_AI_Observing_Site::oras_observatory(),
			$requested_at,
			$window_end,
			$fact_types,
			$target_identity
		);
	}

	public static function allowed_fact_types() {
		return array(
			self::SUNSET,
			self::ASTRONOMICAL_DARKNESS,
			self::MOON_STATE,
			self::PLANET_POSITION,
			self::TARGET_POSITION,
			self::WEATHER_CONDITIONS,
			self::OBSERVING_SCORE,
		);
	}

	public static function normalize_fact_types( array $fact_types ) {
		$normalized = array();
		foreach ( $fact_types as $fact_type ) {
			if ( is_string( $fact_type ) && in_array( $fact_type, self::allowed_fact_types(), true ) ) {
				$normalized[ $fact_type ] = $fact_type;
			} else {
				throw new InvalidArgumentException( 'Invalid current-data fact type.' );
			}
		}

		return array_values( $normalized );
	}

	public static function normalize_target_identity( $target_identity ) {
		$target_identity = strtolower( trim( (string) $target_identity ) );
		if ( '' === $target_identity ) {
			return '';
		}
		if (
			strlen( $target_identity ) > 100
			|| ! preg_match( '/^[a-z0-9_-]+(?:[:.][a-z0-9_-]+)*$/', $target_identity )
		) {
			throw new InvalidArgumentException( 'Invalid target identity.' );
		}

		return $target_identity;
	}

	public function authorized_request() {
		return $this->authorized_request;
	}

	public function user_id() {
		return $this->authorized_request->user_id();
	}

	public function site() {
		return $this->site;
	}

	public function requested_at() {
		return $this->requested_at;
	}

	public function local_requested_at() {
		return $this->requested_at->setTimezone( $this->site->timezone() );
	}

	public function window_end() {
		return $this->window_end;
	}

	public function fact_types() {
		return $this->fact_types;
	}

	public function target_identity() {
		return $this->target_identity;
	}
}
