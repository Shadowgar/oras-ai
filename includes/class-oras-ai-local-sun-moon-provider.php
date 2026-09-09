<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tlab\SunCalc\SunCalc;
use Tlab\SunCalc\Utils as SunCalcUtils;

/**
 * Deterministic local Sun/Moon adapter backed by tuxonice/suncalc-php 1.0.1.
 */
final class ORAS_AI_Local_Sun_Moon_Provider implements ORAS_AI_Astronomy_Provider_Interface {
	const PROVIDER_ID = 'suncalc';
	const VERSION     = '1.0.1';

	private $clock;

	public function __construct( ?ORAS_AI_Clock_Interface $clock = null ) {
		$this->clock = $clock ?: new ORAS_AI_System_Clock();
	}

	public function provider_id() {
		return self::PROVIDER_ID;
	}

	public function fetch( ORAS_AI_Current_Data_Request $request ) {
		$requested = array_intersect(
			$request->fact_types(),
			array(
				ORAS_AI_Current_Data_Request::SUNSET,
				ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS,
				ORAS_AI_Current_Data_Request::MOON_STATE,
			)
		);
		if ( empty( $requested ) ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'unsupported_fact_type' );
		}

		try {
			$local = new DateTime( $request->local_requested_at()->format( DATE_ATOM ) );
			$calc  = new SunCalc( $local, $request->site()->latitude(), $request->site()->longitude() );
			$facts = array();
			if ( in_array( ORAS_AI_Current_Data_Request::SUNSET, $requested, true ) ) {
				$facts = array_merge( $facts, $this->sunset_facts( $calc ) );
			}
			if ( in_array( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS, $requested, true ) ) {
				$facts = array_merge( $facts, $this->darkness_facts( $calc ) );
			}
			if ( in_array( ORAS_AI_Current_Data_Request::MOON_STATE, $requested, true ) ) {
				$facts = array_merge( $facts, $this->moon_facts( $calc, $request ) );
			}
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'local_calculation_failed' );
		}

		return empty( $facts )
			? ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'calculation_unavailable' )
			: ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, $facts );
	}

	private function sunset_facts( SunCalc $calc ) {
		$times = $calc->getSunTimes();
		return $this->time_fact( ORAS_AI_Current_Data_Request::SUNSET, 'astronomy:sun:sunset', $times['sunset'] ?? null, 'sun' );
	}

	private function darkness_facts( SunCalc $calc ) {
		$times = $calc->getSunTimes();
		return array_merge(
			$this->time_fact( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS, 'astronomy:sun:astronomical-dawn', $times['nightEnd'] ?? null, 'sun' ),
			$this->time_fact( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS, 'astronomy:sun:astronomical-dusk', $times['night'] ?? null, 'sun' )
		);
	}

	private function moon_facts( SunCalc $calc, ORAS_AI_Current_Data_Request $request ) {
		$instant = new DateTime( $request->requested_at()->format( DATE_ATOM ) );
		$site    = $request->site();
		$days    = SunCalcUtils::toDays( $instant );
		$coords  = $this->qualified_moon_coordinates( $days );
		$hour_angle = SunCalcUtils::siderealTime( $days, deg2rad( -$site->longitude() ) ) - $coords['right_ascension'];
		$topocentric = $this->topocentric_coordinates( $hour_angle, $coords['declination'], $coords['distance_km'], $site );
		$altitude = SunCalcUtils::altitude( $topocentric['hour_angle'], deg2rad( $site->latitude() ), $topocentric['declination'] );
		$azimuth  = SunCalcUtils::azimuth( $topocentric['hour_angle'], deg2rad( $site->latitude() ), $topocentric['declination'] );
		$altitude_degrees = rad2deg( $altitude );
		$azimuth_degrees  = fmod( rad2deg( $azimuth ) + 180.0 + 360.0, 360.0 );
		$illumination     = $calc->getMoonIllumination();
		$times            = $calc->getMoonTimes();
		$calculated       = $this->clock->now();
		$valid            = $request->requested_at();

		$facts = array(
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::MOON_STATE, (float) $illumination['phase'], 'phase_cycle', $calculated, $valid, 'moon', 'astronomy:moon:phase', self::VERSION ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::MOON_STATE, (float) $illumination['fraction'], 'fraction', $calculated, $valid, 'moon', 'astronomy:moon:illumination', self::VERSION ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::MOON_STATE, $altitude_degrees, 'degrees', $calculated, $valid, 'moon', 'astronomy:moon:altitude', self::VERSION ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::MOON_STATE, $azimuth_degrees, 'degrees', $calculated, $valid, 'moon', 'astronomy:moon:azimuth', self::VERSION ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::MOON_STATE, $altitude_degrees > 0.0 ? 'above' : 'below', 'geometric_horizon', $calculated, $valid, 'moon', 'astronomy:moon:geometric_horizon', self::VERSION ),
		);
		$facts = array_merge( $facts, $this->time_fact( ORAS_AI_Current_Data_Request::MOON_STATE, 'astronomy:moon:rise', $times['moonrise'] ?? null, 'moon' ) );
		$facts = array_merge( $facts, $this->time_fact( ORAS_AI_Current_Data_Request::MOON_STATE, 'astronomy:moon:set', $times['moonset'] ?? null, 'moon' ) );
		return $facts;
	}

	/**
	 * Low-precision geocentric Moon coordinates referred to the mean ecliptic
	 * and equinox of date. The base J2000 day count and L/M/F series are the
	 * pinned SunCalc 1.0.1 Utils::moonCoords implementation. Added longitude and
	 * latitude terms reproduce Paul Schlyter, "Computing planetary positions",
	 * sections 7-8; its 2000 Jan 0.0 elements are shifted by 1.5 days to
	 * SunCalc's JD 2451545.0 epoch. The three retained distance amplitudes are
	 * the corresponding 20905/3699/2956 km dominant terms documented in lunar
	 * laser-ranging literature. Angles are radians internally and distance is
	 * kilometres. Nutation, aberration, and atmospheric refraction are omitted.
	 *
	 * @see https://github.com/tuxonice/suncalc-php/blob/8b8ca60f8af20d38c00121615beab999ca5dcd55/src/Utils.php#L112-L128
	 * @see https://stjarnhimlen.se/comp/tutorial.html#7
	 * @see https://ilrs.gsfc.nasa.gov/lw13/docs/papers/sci_williams_1m.pdf
	 */
	private function qualified_moon_coordinates( $days ) {
		$rad = M_PI / 180.0;
		$mean_longitude = $rad * ( 218.316 + 13.176396 * $days );
		$moon_anomaly   = $rad * ( 134.963 + 13.064993 * $days );
		$argument_lat   = $rad * ( 93.272 + 13.229350 * $days );
		$sun_anomaly    = $rad * ( 357.5291 + 0.98560028 * $days );
		$sun_longitude  = $sun_anomaly + $rad * 102.9372 + M_PI;
		$elongation     = $mean_longitude - $sun_longitude;

		$longitude = $mean_longitude + $rad * (
			6.289 * sin( $moon_anomaly )
			- 1.274 * sin( $moon_anomaly - 2.0 * $elongation )
			+ 0.658 * sin( 2.0 * $elongation )
			- 0.186 * sin( $sun_anomaly )
			- 0.059 * sin( 2.0 * $moon_anomaly - 2.0 * $elongation )
			- 0.057 * sin( $moon_anomaly - 2.0 * $elongation + $sun_anomaly )
			+ 0.053 * sin( $moon_anomaly + 2.0 * $elongation )
			+ 0.046 * sin( 2.0 * $elongation - $sun_anomaly )
			+ 0.041 * sin( $moon_anomaly - $sun_anomaly )
			- 0.035 * sin( $elongation )
			- 0.031 * sin( $moon_anomaly + $sun_anomaly )
			- 0.015 * sin( 2.0 * $argument_lat - 2.0 * $elongation )
			+ 0.011 * sin( $moon_anomaly - 4.0 * $elongation )
		);
		$latitude = $rad * (
			5.128 * sin( $argument_lat )
			- 0.173 * sin( $argument_lat - 2.0 * $elongation )
			- 0.055 * sin( $moon_anomaly - $argument_lat - 2.0 * $elongation )
			- 0.046 * sin( $moon_anomaly + $argument_lat - 2.0 * $elongation )
			+ 0.033 * sin( $argument_lat + 2.0 * $elongation )
			+ 0.017 * sin( 2.0 * $moon_anomaly + $argument_lat )
		);
		$distance = 385001.0
			- 20905.0 * cos( $moon_anomaly )
			- 3699.0 * cos( $moon_anomaly - 2.0 * $elongation )
			- 2956.0 * cos( 2.0 * $elongation );
		$obliquity = $rad * ( 23.4393 - 3.563E-7 * $days );

		return array(
			'right_ascension' => atan2( sin( $longitude ) * cos( $obliquity ) - tan( $latitude ) * sin( $obliquity ), cos( $longitude ) ),
			'declination'     => asin( sin( $latitude ) * cos( $obliquity ) + cos( $latitude ) * sin( $obliquity ) * sin( $longitude ) ),
			'distance_km'     => $distance,
		);
	}

	/**
	 * Geocentric-to-topocentric equatorial parallax from Jean Meeus,
	 * Astronomical Algorithms (2nd ed., 1998), chapter 40, pp. 279-280. Site
	 * latitude and hour angle are radians, elevation is metres, lunar distance
	 * is kilometres, and 6378.14 km is the Earth equatorial radius. The returned
	 * coordinates are geometric: atmospheric refraction is intentionally omitted.
	 */
	private function topocentric_coordinates( $hour_angle, $declination, $distance_km, ORAS_AI_Observing_Site $site ) {
		$latitude  = deg2rad( $site->latitude() );
		$u         = atan( 0.99664719 * tan( $latitude ) );
		$height    = $site->elevation_meters() / 6378140.0;
		$rho_sin   = 0.99664719 * sin( $u ) + $height * sin( $latitude );
		$rho_cos   = cos( $u ) + $height * cos( $latitude );
		$sin_parallax = 6378.14 / (float) $distance_km;
		$delta_ra  = atan2(
			-$rho_cos * $sin_parallax * sin( $hour_angle ),
			cos( $declination ) - $rho_cos * $sin_parallax * cos( $hour_angle )
		);
		$topocentric_dec = atan2(
			( sin( $declination ) - $rho_sin * $sin_parallax ) * cos( $delta_ra ),
			cos( $declination ) - $rho_cos * $sin_parallax * cos( $hour_angle )
		);
		return array(
			'hour_angle' => $hour_angle - $delta_ra,
			'declination' => $topocentric_dec,
		);
	}

	private function time_fact( $fact_type, $fact_key, $time, $target ) {
		if ( ! $time instanceof DateTimeInterface ) {
			return array();
		}
		$utc   = new DateTimeZone( 'UTC' );
		$valid = DateTimeImmutable::createFromInterface( $time )->setTimezone( $utc );
		return array(
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, $fact_type, $valid->format( DATE_ATOM ), 'iso8601', $this->clock->now(), $valid, $target, $fact_key, self::VERSION ),
		);
	}
}
