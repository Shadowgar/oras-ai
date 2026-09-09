<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable, server-controlled observing location.
 */
final class ORAS_AI_Observing_Site {
	const ORAS_LATITUDE         = 41.321903;
	const ORAS_LONGITUDE        = -79.585394;
	const ORAS_ELEVATION_METERS = 432.816;
	const ORAS_TIMEZONE         = 'America/New_York';

	private $latitude;
	private $longitude;
	private $elevation_meters;
	private $timezone_name;

	public function __construct( $latitude, $longitude, $elevation_meters, $timezone_name ) {
		if (
			! self::valid_finite_number( $latitude )
			|| ! self::valid_finite_number( $longitude )
			|| ! self::valid_finite_number( $elevation_meters )
			|| (float) $latitude < -90.0
			|| (float) $latitude > 90.0
			|| (float) $longitude < -180.0
			|| (float) $longitude > 180.0
			|| ! self::valid_timezone( $timezone_name )
		) {
			throw new InvalidArgumentException( 'Invalid observing site.' );
		}

		$this->latitude         = (float) $latitude;
		$this->longitude        = (float) $longitude;
		$this->elevation_meters = (float) $elevation_meters;
		$this->timezone_name    = (string) $timezone_name;
	}

	public static function oras_observatory() {
		return new self(
			self::ORAS_LATITUDE,
			self::ORAS_LONGITUDE,
			self::ORAS_ELEVATION_METERS,
			self::ORAS_TIMEZONE
		);
	}

	public function latitude() {
		return $this->latitude;
	}

	public function longitude() {
		return $this->longitude;
	}

	public function elevation_meters() {
		return $this->elevation_meters;
	}

	public function timezone_name() {
		return $this->timezone_name;
	}

	public function timezone() {
		return new DateTimeZone( $this->timezone_name );
	}

	public function to_array() {
		return array(
			'latitude'         => $this->latitude,
			'longitude'        => $this->longitude,
			'elevation_meters' => $this->elevation_meters,
			'timezone'         => $this->timezone_name,
		);
	}

	private static function valid_finite_number( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value );
	}

	private static function valid_timezone( $timezone_name ) {
		return is_string( $timezone_name )
			&& in_array( $timezone_name, DateTimeZone::listIdentifiers(), true );
	}
}
