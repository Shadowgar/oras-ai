<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts qualified ORAS AI facts for the authoritative Member Hub score API. */
final class ORAS_AI_Member_Hub_Score_Adapter {
	const PROVIDER_ID = 'oras_observing_score';
	const METERS_PER_SECOND_TO_MPH = 2.2369362921;

	private $clock;
	private $score_class;

	public function __construct( ORAS_AI_Clock_Interface $clock, $score_class = 'ORAS_MH_Conditions_Service' ) {
		$this->clock       = $clock;
		$this->score_class = (string) $score_class;
	}

	public function score( ORAS_AI_Weather_Snapshot $weather, array $moon_facts, DateTimeImmutable $instant ) {
		if ( ! class_exists( $this->score_class ) || ! is_callable( array( $this->score_class, 'compute_observing_score_for_context' ) ) ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'score_provider_unavailable' );
		}
		$data = $weather->to_array();
		$at   = $instant->setTimezone( new DateTimeZone( 'UTC' ) );
		if (
			ORAS_AI_Weather_Snapshot::FORECAST !== $data['designation']
			|| $at < new DateTimeImmutable( $data['valid_from'] )
			|| $at >= new DateTimeImmutable( $data['valid_until'] )
			|| null === $data['cloud_cover_percent']
			|| null === $data['precipitation_probability_percent']
			|| null === $data['wind_speed_mps']
		) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'score_inputs_unavailable' );
		}

		$moon = $this->moon_context( $moon_facts, $at );
		if ( null === $moon ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'score_inputs_unavailable' );
		}
		$conditions = array(
			'cloud_cover_pct'     => $data['cloud_cover_percent'],
			'precip_probability' => $data['precipitation_probability_percent'],
			'wind_mph'           => $data['wind_speed_mps'] * self::METERS_PER_SECOND_TO_MPH,
		);
		$health = array(
			'weather' => array( 'status' => 'ok' ),
			'smoke'   => array( 'status' => 'missing' ),
		);
		try {
			$score_class = $this->score_class;
			$result = $score_class::compute_observing_score_for_context(
				$conditions,
				array(),
				array( 'illumination' => $moon['illumination'] ),
				$health,
				$at,
				$moon['above_horizon']
			);
			if ( is_wp_error( $result ) ) {
				return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'score_provider_unavailable' );
			}
			if ( ! $this->valid_result( $result ) ) {
				return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'score_result_malformed' );
			}
			$value = new ORAS_AI_Observing_Score_Result(
				$result['score'], $result['category'], $result['model_version'],
				$result['confidence_band'] ?? '', $result['dominant_limiter'] ?? '', '',
				$this->clock->now(), new DateTimeImmutable( $data['fresh_at'] ),
				$result['method_version'], $result['threshold_profile'] ?? ''
			);
			return ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, array( $value ) );
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'score_result_malformed' );
		}
	}

	private function moon_context( array $facts, DateTimeImmutable $instant ) {
		$illumination = null;
		$horizon      = null;
		foreach ( $facts as $fact ) {
			if ( ! $fact instanceof ORAS_AI_Astronomy_Fact ) {
				continue;
			}
			$data = $fact->to_array();
			if ( $data['valid_at'] !== $instant->format( DATE_ATOM ) || 'oras_observatory' !== $data['site_identity'] ) {
				continue;
			}
			if ( 'astronomy:moon:illumination' === $data['fact_key'] && 'fraction' === $data['unit'] && is_numeric( $data['value'] ) && (float) $data['value'] >= 0.0 && (float) $data['value'] <= 1.0 ) {
				$illumination = (float) $data['value'] * 100.0;
			}
			if ( 'astronomy:moon:geometric_horizon' === $data['fact_key'] && in_array( $data['value'], array( 'above', 'below' ), true ) ) {
				$horizon = 'above' === $data['value'];
			}
		}
		return null === $illumination || null === $horizon
			? null
			: array( 'illumination' => $illumination, 'above_horizon' => $horizon );
	}

	private function valid_result( $result ) {
		if ( ! is_array( $result ) ) {
			return false;
		}
		foreach ( array( 'score', 'category', 'model_version', 'method_version' ) as $field ) {
			if ( ! array_key_exists( $field, $result ) ) {
				return false;
			}
		}
		return is_int( $result['score'] )
			&& $result['score'] >= 0 && $result['score'] <= 100
			&& in_array( $result['category'], array( 'clear', 'marginal', 'poor' ), true )
			&& is_string( $result['model_version'] ) && (bool) preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $result['model_version'] )
			&& is_string( $result['method_version'] ) && (bool) preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $result['method_version'] )
			&& in_array( $result['confidence_band'] ?? '', array( '', 'low', 'medium', 'high' ), true )
			&& $this->valid_code( $result['dominant_limiter'] ?? '' )
			&& $this->valid_code( $result['threshold_profile'] ?? '' );
	}

	private function valid_code( $value ) {
		return is_string( $value ) && ( '' === $value || (bool) preg_match( '/^[a-z0-9_]{1,64}$/', $value ) );
	}
}
