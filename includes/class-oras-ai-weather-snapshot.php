<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized weather values with separate issue, freshness, and validity time.
 */
final class ORAS_AI_Weather_Snapshot implements ORAS_AI_Current_Data_Value_Interface {
	const CURRENT  = 'current';
	const FORECAST = 'forecast';

	private $data;

	public function __construct(
		$provider_id,
		DateTimeImmutable $issued_at,
		DateTimeImmutable $fresh_at,
		DateTimeImmutable $valid_from,
		DateTimeImmutable $valid_until,
		$designation,
		$cloud_cover_percent,
		$precipitation_probability_percent,
		$precipitation_type,
		$temperature_celsius,
		$wind_speed_mps,
		$wind_gust_mps,
		$humidity_percent,
		$visibility_meters,
		$uncertainty
	) {
		$provider_id = ORAS_AI_Current_Data_Result::normalize_provider_id( $provider_id );
		$utc = new DateTimeZone( 'UTC' );
		$valid_from  = $valid_from->setTimezone( $utc );
		$valid_until = $valid_until->setTimezone( $utc );
		$precipitation_type = self::normalize_code( $precipitation_type );
		$uncertainty        = self::normalize_code( $uncertainty );

		if (
			'' === $provider_id
			|| ! in_array( $designation, array( self::CURRENT, self::FORECAST ), true )
			|| $valid_until < $valid_from
			|| ! self::valid_nullable_number( $cloud_cover_percent, 0.0, 100.0 )
			|| ! self::valid_nullable_number( $precipitation_probability_percent, 0.0, 100.0 )
			|| ! self::valid_nullable_number( $temperature_celsius, -100.0, 100.0 )
			|| ! self::valid_nullable_number( $wind_speed_mps, 0.0, 200.0 )
			|| ! self::valid_nullable_number( $wind_gust_mps, 0.0, 200.0 )
			|| ! self::valid_nullable_number( $humidity_percent, 0.0, 100.0 )
			|| ! self::valid_nullable_number( $visibility_meters, 0.0, 10000000.0 )
		) {
			throw new InvalidArgumentException( 'Invalid weather snapshot.' );
		}

		$this->data = array(
			'provider'                              => $provider_id,
			'issued_at'                             => $issued_at->setTimezone( $utc ),
			'fresh_at'                              => $fresh_at->setTimezone( $utc ),
			'valid_from'                            => $valid_from,
			'valid_until'                           => $valid_until,
			'designation'                           => $designation,
			'cloud_cover_percent'                   => self::nullable_float( $cloud_cover_percent ),
			'precipitation_probability_percent'     => self::nullable_float( $precipitation_probability_percent ),
			'precipitation_type'                    => $precipitation_type,
			'temperature_celsius'                   => self::nullable_float( $temperature_celsius ),
			'wind_speed_mps'                        => self::nullable_float( $wind_speed_mps ),
			'wind_gust_mps'                         => self::nullable_float( $wind_gust_mps ),
			'humidity_percent'                      => self::nullable_float( $humidity_percent ),
			'visibility_meters'                     => self::nullable_float( $visibility_meters ),
			'uncertainty'                           => $uncertainty,
		);
	}

	public function provider_id() {
		return $this->data['provider'];
	}

	public function authority_class() {
		return ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER;
	}

	public function to_array() {
		return array(
			'provider'                          => $this->data['provider'],
			'issued_at'                         => $this->data['issued_at']->format( DATE_ATOM ),
			'fresh_at'                          => $this->data['fresh_at']->format( DATE_ATOM ),
			'valid_from'                        => $this->data['valid_from']->format( DATE_ATOM ),
			'valid_until'                       => $this->data['valid_until']->format( DATE_ATOM ),
			'designation'                       => $this->data['designation'],
			'cloud_cover_percent'               => $this->data['cloud_cover_percent'],
			'precipitation_probability_percent' => $this->data['precipitation_probability_percent'],
			'precipitation_type'                => $this->data['precipitation_type'],
			'temperature_celsius'               => $this->data['temperature_celsius'],
			'wind_speed_mps'                    => $this->data['wind_speed_mps'],
			'wind_gust_mps'                     => $this->data['wind_gust_mps'],
			'humidity_percent'                  => $this->data['humidity_percent'],
			'visibility_meters'                 => $this->data['visibility_meters'],
			'seeing'                            => 'unavailable',
			'transparency'                      => 'unavailable',
			'uncertainty'                       => $this->data['uncertainty'],
			'authority_class'                   => $this->authority_class(),
		);
	}

	private static function normalize_code( $value ) {
		$value = sanitize_key( (string) $value );
		if ( strlen( $value ) > 64 ) {
			throw new InvalidArgumentException( 'Invalid weather code.' );
		}
		return $value;
	}

	private static function valid_nullable_number( $value, $minimum, $maximum ) {
		if ( null === $value ) {
			return true;
		}
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= $minimum && (float) $value <= $maximum;
	}

	private static function nullable_float( $value ) {
		return null === $value ? null : (float) $value;
	}
}
