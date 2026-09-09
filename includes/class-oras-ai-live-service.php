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
	private $observability;

	public function __construct( array $connectors, ORAS_AI_URL_Policy $url_policy, ?ORAS_AI_Connector_Observability $observability = null ) {
		$this->connectors = array_values(
			array_filter(
				$connectors,
				static function ( $connector ) {
					return $connector instanceof ORAS_AI_Live_Connector_Interface;
				}
			)
		);
		$this->url_policy    = $url_policy;
		$this->observability = $observability;
	}

	/**
	 * @return ORAS_AI_Live_Result|null Null means no registered connector applies.
	 */
	public function query( ORAS_AI_Live_Request $request ) {
		$facts   = array();
		$failures = array();
		$matched = false;
		$fallback = null;

		foreach ( $this->connectors as $connector ) {
			$connector_id = $connector instanceof ORAS_AI_Observable_Live_Connector_Interface ? $connector->connector_id() : '';
			$required_fact_keys = array();
			try {
				if ( ! $connector->supports( $request ) ) {
					continue;
				}

				$matched = true;
				if ( $connector instanceof ORAS_AI_Observable_Live_Connector_Interface ) {
					$required_fact_keys = $connector->required_fact_keys( $request );
				}
				$result = $connector->fetch( $request );
			} catch ( Throwable $throwable ) {
				$matched = true;
				$result = ORAS_AI_Live_Result::unknown( 'connector_failed' );
			}

			if ( ! $result instanceof ORAS_AI_Live_Result ) {
				$result = ORAS_AI_Live_Result::unknown( 'connector_result_malformed' );
			}
			if ( ! $result->successful() ) {
				if ( ORAS_AI_Live_Result::DENIED === $result->status() ) {
					return $result;
				}
				$this->record_outcome( $connector_id, $result );
				if ( null === $fallback ) {
					$fallback = $result;
				}
				if ( '' !== $connector_id && ! empty( $required_fact_keys ) ) {
					$failures[] = array(
						'connector' => $connector_id,
						'reason'    => $result->reason(),
						'fact_keys' => $required_fact_keys,
					);
				}
				continue;
			}

			$connector_facts = array();
			$connector_failure = null;
			foreach ( $result->facts() as $fact ) {
				$canonical_url = (string) $fact->field( 'canonical_url' );
				if ( '' !== $canonical_url && ! $this->url_policy->allows( $canonical_url ) ) {
					$connector_failure = ORAS_AI_Live_Result::unknown( 'unsafe_canonical_url' );
					break;
				}
				$connector_facts[] = $fact;
			}
			if ( $connector_failure ) {
				$this->record_outcome( $connector_id, $connector_failure );
				if ( null === $fallback ) {
					$fallback = $connector_failure;
				}
				if ( '' !== $connector_id && ! empty( $required_fact_keys ) ) {
					$failures[] = array(
						'connector' => $connector_id,
						'reason'    => $connector_failure->reason(),
						'fact_keys' => $required_fact_keys,
					);
				}
				continue;
			}
			$facts = array_merge( $facts, $connector_facts );
			$this->record_outcome( $connector_id, $result );
		}

		if ( ! $matched ) {
			return null;
		}
		if ( empty( $facts ) ) {
			return $fallback ?: ORAS_AI_Live_Result::unknown( 'live_facts_missing' );
		}

		return ORAS_AI_Live_Result::success( $facts, $failures );
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
					'comparison_value'      => $fact->field( 'comparison_value' ),
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
		$labels = array(
			'events_calendar' => 'The Events Calendar',
			'woocommerce'     => 'WooCommerce',
			'pmpro'           => 'PMPro',
		);
		foreach ( $result->failures() as $failure ) {
			$items[] = ORAS_AI_Evidence::from_array(
				array(
					'source_title'          => $labels[ $failure['connector'] ],
					'source_type'           => 'live_connector_status',
					'relevant_text'         => sprintf(
						'Current %1$s information required by this request could not be established (%2$s).',
						$labels[ $failure['connector'] ],
						$failure['reason']
					),
					'visibility'            => 'members',
					'lifecycle'             => 'approved',
					'source_classification' => 'live_data',
					'authority_class'       => ORAS_AI_Source_Precedence::LIVE_ORAS_STATE,
					'fact_keys'             => $failure['fact_keys'],
				)
			);
		}

		return new ORAS_AI_Evidence_Packet( $items );
	}

	private function record_outcome( $connector_id, ORAS_AI_Live_Result $result ) {
		if ( null !== $this->observability && '' !== $connector_id ) {
			$this->observability->record_outcome( $connector_id, $result->status(), $result->reason() );
		}
	}
}
