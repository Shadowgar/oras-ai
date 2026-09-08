<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invokes only registered relevant connectors and admits normalized facts.
 */
final class ORAS_AI_Live_Service {

	private $connectors;
	private $url_policy;

	public function __construct( array $connectors, ORAS_AI_URL_Policy $url_policy ) {
		$this->connectors = array_values(
			array_filter(
				$connectors,
				static function ( $connector ) {
					return $connector instanceof ORAS_AI_Live_Connector_Interface;
				}
			)
		);
		$this->url_policy = $url_policy;
	}

	/**
	 * @return ORAS_AI_Live_Result|null Null means no registered connector applies.
	 */
	public function query( ORAS_AI_Live_Request $request ) {
		$facts   = array();
		$matched = false;

		foreach ( $this->connectors as $connector ) {
			try {
				if ( ! $connector->supports( $request ) ) {
					continue;
				}

				$matched = true;
				$result = $connector->fetch( $request );
			} catch ( Throwable $throwable ) {
				return ORAS_AI_Live_Result::unknown( 'connector_failed' );
			}

			if ( ! $result instanceof ORAS_AI_Live_Result ) {
				return ORAS_AI_Live_Result::unknown( 'connector_result_malformed' );
			}
			if ( ! $result->successful() ) {
				return $result;
			}

			foreach ( $result->facts() as $fact ) {
				if ( ! $this->url_policy->allows( $fact->field( 'canonical_url' ) ) ) {
					return ORAS_AI_Live_Result::unknown( 'unsafe_canonical_url' );
				}
				$facts[] = $fact;
			}
		}

		if ( ! $matched ) {
			return null;
		}

		return ORAS_AI_Live_Result::success( $facts );
	}

	public function evidence_packet( $result ) {
		if ( ! $result instanceof ORAS_AI_Live_Result || ! $result->successful() ) {
			return new ORAS_AI_Evidence_Packet();
		}

		$items = array();
		foreach ( $result->facts() as $fact ) {
			$items[] = ORAS_AI_Evidence::from_array(
				array(
					'artifact_id'           => 0,
					'source_record_id'      => 0,
					'source_wp_object_id'   => $fact->field( 'source_wp_object_id' ),
					'source_type'           => $fact->field( 'source_type' ),
					'artifact_title'        => $fact->field( 'source_title' ),
					'source_title'          => $fact->field( 'source_title' ),
					'canonical_url'         => $fact->field( 'canonical_url' ),
					'relevant_text'         => $fact->field( 'relevant_text' ),
					'category'              => '',
					'visibility'            => $fact->field( 'visibility' ),
					'lifecycle'             => 'approved',
					'source_classification' => 'live_data',
					'authority_class'       => ORAS_AI_Source_Precedence::LIVE_ORAS_STATE,
					'source_modified_gmt'   => $fact->field( 'source_modified_gmt' ),
					'synced_at'             => $fact->field( 'retrieved_at' ),
					'historical_event'      => false,
					'fact_key'              => $fact->fact_key(),
					'fact_keys'             => array( $fact->fact_key() ),
				)
			);
		}

		return new ORAS_AI_Evidence_Packet( $items );
	}
}
