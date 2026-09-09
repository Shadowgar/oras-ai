<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICRS/J2000 equatorial to unrefracted local horizontal coordinates.
 *
 * The precession-bias and sidereal-time expressions follow the IAU SOFA
 * routines iauPfw06, iauFw2m, iauEra00, and iauGmst06. UT1 is approximated by
 * UTC, which is comfortably inside the qualified 0.1-degree product bound.
 * https://www.iausofa.org/current_C.html
 */
final class ORAS_AI_Horizontal_Position_Calculator {
	const ARCSECONDS_TO_RADIANS = M_PI / ( 180.0 * 3600.0 );

	public function calculate( $right_ascension_degrees, $declination_degrees, DateTimeImmutable $instant, ORAS_AI_Observing_Site $site ) {
		if (
			! is_numeric( $right_ascension_degrees )
			|| ! is_numeric( $declination_degrees )
			|| ! is_finite( (float) $right_ascension_degrees )
			|| ! is_finite( (float) $declination_degrees )
			|| (float) $declination_degrees < -90.0
			|| (float) $declination_degrees > 90.0
		) {
			throw new InvalidArgumentException( 'Invalid equatorial coordinates.' );
		}

		$ra  = deg2rad( $this->normalize_degrees( (float) $right_ascension_degrees ) );
		$dec = deg2rad( (float) $declination_degrees );
		$jd_utc = 2440587.5 + ( (float) $instant->format( 'U.u' ) / 86400.0 );
		$jd_tt  = $jd_utc + 69.184 / 86400.0;
		$t      = ( $jd_tt - 2451545.0 ) / 36525.0;

		$vector = array( cos( $dec ) * cos( $ra ), cos( $dec ) * sin( $ra ), sin( $dec ) );
		$vector = $this->matrix_vector( $this->precession_bias_matrix( $t ), $vector );
		$ra_date  = atan2( $vector[1], $vector[0] );
		$dec_date = atan2( $vector[2], sqrt( $vector[0] * $vector[0] + $vector[1] * $vector[1] ) );

		$earth_rotation_angle = $this->normalize_radians(
			2.0 * M_PI * ( 0.7790572732640 + 1.00273781191135448 * ( $jd_utc - 2451545.0 ) )
		);
		$gmst = $this->normalize_radians(
			$earth_rotation_angle + self::ARCSECONDS_TO_RADIANS * (
				0.014506 + (
					4612.156534 + (
						1.3915817 + (
							-0.00000044 + (
								-0.000029956 - 0.0000000368 * $t
							) * $t
						) * $t
					) * $t
				) * $t
			)
		);
		$hour_angle = $this->normalize_signed_radians( $gmst + deg2rad( $site->longitude() ) - $ra_date );
		$latitude   = deg2rad( $site->latitude() );
		$altitude   = asin( sin( $latitude ) * sin( $dec_date ) + cos( $latitude ) * cos( $dec_date ) * cos( $hour_angle ) );
		$azimuth    = atan2(
			sin( $hour_angle ),
			cos( $hour_angle ) * sin( $latitude ) - tan( $dec_date ) * cos( $latitude )
		) + M_PI;
		$altitude_degrees = rad2deg( $altitude );

		return array(
			'altitude'          => $altitude_degrees,
			'azimuth'           => $this->normalize_degrees( rad2deg( $azimuth ) ),
			'geometric_horizon' => $altitude_degrees > 0.0 ? 'above' : 'below',
		);
	}

	private function precession_bias_matrix( $t ) {
		$gamma = ( -0.052928 + ( 10.556378 + ( 0.4932044 + ( -0.00031238 + ( -0.000002788 + 0.0000000260 * $t ) * $t ) * $t ) * $t ) * $t ) * self::ARCSECONDS_TO_RADIANS;
		$phi   = ( 84381.412819 + ( -46.811016 + ( 0.0511268 + ( 0.00053289 + ( -0.000000440 - 0.0000000176 * $t ) * $t ) * $t ) * $t ) * $t ) * self::ARCSECONDS_TO_RADIANS;
		$psi   = ( -0.041775 + ( 5038.481484 + ( 1.5584175 + ( -0.00018522 + ( -0.000026452 - 0.0000000148 * $t ) * $t ) * $t ) * $t ) * $t ) * self::ARCSECONDS_TO_RADIANS;
		$eps   = ( 84381.406 + ( -46.836769 + ( -0.0001831 + ( 0.00200340 + ( -0.000000576 - 0.0000000434 * $t ) * $t ) * $t ) * $t ) * $t ) * self::ARCSECONDS_TO_RADIANS;

		$matrix = $this->identity();
		$matrix = $this->multiply( $this->rotation_z( $gamma ), $matrix );
		$matrix = $this->multiply( $this->rotation_x( $phi ), $matrix );
		$matrix = $this->multiply( $this->rotation_z( -$psi ), $matrix );
		return $this->multiply( $this->rotation_x( -$eps ), $matrix );
	}

	private function identity() {
		return array( array( 1.0, 0.0, 0.0 ), array( 0.0, 1.0, 0.0 ), array( 0.0, 0.0, 1.0 ) );
	}

	private function rotation_x( $angle ) {
		$c = cos( $angle );
		$s = sin( $angle );
		return array( array( 1.0, 0.0, 0.0 ), array( 0.0, $c, $s ), array( 0.0, -$s, $c ) );
	}

	private function rotation_z( $angle ) {
		$c = cos( $angle );
		$s = sin( $angle );
		return array( array( $c, $s, 0.0 ), array( -$s, $c, 0.0 ), array( 0.0, 0.0, 1.0 ) );
	}

	private function multiply( array $left, array $right ) {
		$result = $this->identity();
		for ( $row = 0; $row < 3; $row++ ) {
			for ( $column = 0; $column < 3; $column++ ) {
				$result[ $row ][ $column ] = 0.0;
				for ( $index = 0; $index < 3; $index++ ) {
					$result[ $row ][ $column ] += $left[ $row ][ $index ] * $right[ $index ][ $column ];
				}
			}
		}
		return $result;
	}

	private function matrix_vector( array $matrix, array $vector ) {
		return array(
			$matrix[0][0] * $vector[0] + $matrix[0][1] * $vector[1] + $matrix[0][2] * $vector[2],
			$matrix[1][0] * $vector[0] + $matrix[1][1] * $vector[1] + $matrix[1][2] * $vector[2],
			$matrix[2][0] * $vector[0] + $matrix[2][1] * $vector[1] + $matrix[2][2] * $vector[2],
		);
	}

	private function normalize_degrees( $degrees ) {
		$value = fmod( $degrees, 360.0 );
		return $value < 0.0 ? $value + 360.0 : $value;
	}

	private function normalize_radians( $radians ) {
		$value = fmod( $radians, 2.0 * M_PI );
		return $value < 0.0 ? $value + 2.0 * M_PI : $value;
	}

	private function normalize_signed_radians( $radians ) {
		$value = $this->normalize_radians( $radians );
		return $value > M_PI ? $value - 2.0 * M_PI : $value;
	}
}
