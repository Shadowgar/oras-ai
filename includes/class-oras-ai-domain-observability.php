<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Domain_Observability {

	const OPTION_COUNTS = 'oras_ai_domain_outcome_counts';

	public static function record( $outcome ) {
		$outcome = sanitize_key( $outcome );
		if ( ! in_array( $outcome, ORAS_AI_Domain_Result::outcomes(), true ) ) {
			$outcome = ORAS_AI_Domain_Result::AMBIGUOUS;
		}

		$counts = self::counts();
		$counts[ $outcome ]++;

		return update_option( self::OPTION_COUNTS, $counts, false );
	}

	public static function counts() {
		$defaults = array(
			ORAS_AI_Domain_Result::ORAS      => 0,
			ORAS_AI_Domain_Result::ASTRONOMY => 0,
			ORAS_AI_Domain_Result::CROSSOVER => 0,
			ORAS_AI_Domain_Result::OFF_TOPIC => 0,
			ORAS_AI_Domain_Result::AMBIGUOUS => 0,
		);
		$stored   = get_option( self::OPTION_COUNTS, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		foreach ( $defaults as $outcome => $default ) {
			$defaults[ $outcome ] = max( 0, (int) ( $stored[ $outcome ] ?? $default ) );
		}

		return $defaults;
	}
}
