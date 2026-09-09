<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded aggregate health for Task 2 astronomy providers only. */
final class ORAS_AI_Astronomy_Observability {
	const OPTION            = 'oras_ai_astronomy_provider_health';
	const MAX_FAILURE_COUNT = 999999;

	private const PROVIDERS = array( 'suncalc', 'openngc_local', 'astronomy_api' );
	private const OPERATIONAL_REASONS = array(
		'provider_request_failed',
		'provider_unavailable',
		'malformed_provider_response',
		'incomplete_provider_response',
		'local_calculation_failed',
		'calculation_unavailable',
		'provider_failed',
		'provider_result_malformed',
	);

	public function record_outcome( $provider_id, $status, $reason = '' ) {
		$provider_id = sanitize_key( $provider_id );
		$status      = sanitize_key( $status );
		$reason      = sanitize_key( $reason );
		if ( ! in_array( $provider_id, self::PROVIDERS, true ) ) {
			return false;
		}
		$state = $this->snapshot();
		if ( ORAS_AI_Current_Data_Result::SUCCESS === $status ) {
			$state[ $provider_id ]['operational_state'] = 'healthy';
			return update_option( self::OPTION, $state, false );
		}
		if ( ! in_array( $reason, self::OPERATIONAL_REASONS, true ) ) {
			return false;
		}
		$state[ $provider_id ]['operational_state']   = ORAS_AI_Current_Data_Result::UNAVAILABLE === $status ? 'unavailable' : 'failing';
		$state[ $provider_id ]['failure_count']       = min( self::MAX_FAILURE_COUNT, $state[ $provider_id ]['failure_count'] + 1 );
		$state[ $provider_id ]['last_failure_reason'] = $reason;
		$state[ $provider_id ]['last_failure_at']     = current_time( 'mysql', true );
		return update_option( self::OPTION, $state, false );
	}

	public function snapshot() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array();
		foreach ( self::PROVIDERS as $provider_id ) {
			$item = isset( $stored[ $provider_id ] ) && is_array( $stored[ $provider_id ] ) ? $stored[ $provider_id ] : array();
			$state[ $provider_id ] = array(
				'operational_state'  => in_array( $item['operational_state'] ?? '', array( 'unknown', 'healthy', 'failing', 'unavailable' ), true ) ? $item['operational_state'] : 'unknown',
				'failure_count'      => min( self::MAX_FAILURE_COUNT, max( 0, (int) ( $item['failure_count'] ?? 0 ) ) ),
				'last_failure_reason'=> in_array( $item['last_failure_reason'] ?? '', self::OPERATIONAL_REASONS, true ) ? $item['last_failure_reason'] : '',
				'last_failure_at'    => sanitize_text_field( (string) ( $item['last_failure_at'] ?? '' ) ),
			);
		}
		return $state;
	}
}
