<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Connector_Observability {

	const OPTION            = 'oras_ai_connector_health';
	const MAX_FAILURE_COUNT = 999999;

	private const CONNECTORS = array( 'events_calendar', 'woocommerce', 'pmpro' );

	private const OPERATIONAL_REASONS = array(
		'connector_failed',
		'connector_result_malformed',
		'live_facts_missing',
		'unsafe_canonical_url',
		'events_connector_unavailable',
		'event_lookup_failed',
		'event_data_malformed',
		'woocommerce_connector_unavailable',
		'product_lookup_failed',
		'product_data_malformed',
		'invalid_product_price',
		'invalid_product_availability',
		'invalid_product_purchasability',
		'ambiguous_product_match',
		'ambiguous_product_variation',
		'pmpro_connector_unavailable',
		'membership_lookup_failed',
		'membership_data_malformed',
	);

	public function record_outcome( $connector_id, $status, $reason = '' ) {
		$connector_id = sanitize_key( $connector_id );
		$status       = sanitize_key( $status );
		$reason       = sanitize_key( $reason );
		if ( ! in_array( $connector_id, self::CONNECTORS, true ) ) {
			return false;
		}

		$state = $this->snapshot();
		if ( ORAS_AI_Live_Result::SUCCESS === $status ) {
			$state[ $connector_id ]['operational_state'] = 'healthy';
			return update_option( self::OPTION, $state, false );
		}

		if ( ! in_array( $reason, self::OPERATIONAL_REASONS, true ) ) {
			return false;
		}

		$state[ $connector_id ]['operational_state']  = ORAS_AI_Live_Result::UNAVAILABLE === $status ? 'unavailable' : 'failing';
		$state[ $connector_id ]['failure_count']      = min( self::MAX_FAILURE_COUNT, $state[ $connector_id ]['failure_count'] + 1 );
		$state[ $connector_id ]['last_failure_reason'] = $reason;
		$state[ $connector_id ]['last_failure_at']     = current_time( 'mysql', true );

		return update_option( self::OPTION, $state, false );
	}

	public function snapshot() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array();
		foreach ( self::CONNECTORS as $connector_id ) {
			$item  = isset( $stored[ $connector_id ] ) && is_array( $stored[ $connector_id ] ) ? $stored[ $connector_id ] : array();
			$state[ $connector_id ] = array(
				'operational_state'  => in_array( $item['operational_state'] ?? '', array( 'unknown', 'healthy', 'failing', 'unavailable' ), true )
					? $item['operational_state']
					: 'unknown',
				'failure_count'      => min( self::MAX_FAILURE_COUNT, max( 0, (int) ( $item['failure_count'] ?? 0 ) ) ),
				'last_failure_reason' => in_array( $item['last_failure_reason'] ?? '', self::OPERATIONAL_REASONS, true )
					? $item['last_failure_reason']
					: '',
				'last_failure_at'     => sanitize_text_field( (string) ( $item['last_failure_at'] ?? '' ) ),
			);
		}

		return $state;
	}
}
