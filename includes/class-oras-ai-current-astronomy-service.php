<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provider-independent planner and evidence mapper for Task 2 astronomy. */
final class ORAS_AI_Current_Astronomy_Service {
	private $local_provider;
	private $catalog_provider;
	private $planet_provider;
	private $resolver;
	private $clock;
	private $observability;

	public function __construct(
		ORAS_AI_Astronomy_Provider_Interface $local_provider,
		ORAS_AI_Astronomy_Provider_Interface $catalog_provider,
		ORAS_AI_Astronomy_Provider_Interface $planet_provider,
		ORAS_AI_OpenNGC_Target_Resolver $resolver,
		ORAS_AI_Clock_Interface $clock,
		?ORAS_AI_Astronomy_Observability $observability = null
	) {
		$this->local_provider   = $local_provider;
		$this->catalog_provider = $catalog_provider;
		$this->planet_provider  = $planet_provider;
		$this->resolver         = $resolver;
		$this->clock            = $clock;
		$this->observability    = $observability;
	}

	public function query( ORAS_AI_Authorized_Request $authorized_request ) {
		$question = strtolower( trim( wp_strip_all_tags( $authorized_request->question(), true ) ) );
		$plans    = array();
		$items    = array();
		$matched  = false;
		$unsupported_items = array();
		$local_types = array();

		if ( preg_match( '/\b(sunset|sunrise|sun)\b/', $question ) ) {
			$local_types[] = ORAS_AI_Current_Data_Request::SUNSET;
			$matched = true;
		}
		if ( preg_match( '/\b(astronomical (?:twilight|darkness|dusk|dawn)|darkness)\b/', $question ) ) {
			$local_types[] = ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS;
			$matched = true;
		}
		if ( preg_match( '/\b(moon|lunar|moonrise|moonset)\b/', $question ) ) {
			$local_types[] = ORAS_AI_Current_Data_Request::MOON_STATE;
			$matched = true;
		}
		if ( ! empty( $local_types ) ) {
			$plans[] = array( $this->local_provider, array_values( array_unique( $local_types ) ), '' );
		}

		$planet_names = array();
		foreach ( ORAS_AI_Planet_Targets::allowed() as $body ) {
			if ( preg_match( '/\b' . preg_quote( $body, '/' ) . '\b/', $question ) ) {
				$planet_names[] = $body;
			}
		}
		$general_planets = (bool) preg_match( '/\b(planets|planet set|solar system planets)\b/', $question );
		if ( $general_planets || ! empty( $planet_names ) ) {
			$target = $general_planets || count( $planet_names ) > 1 ? 'planet:all' : 'planet:' . $planet_names[0];
			$plans[] = array( $this->planet_provider, array( ORAS_AI_Current_Data_Request::PLANET_POSITION ), $target );
			$unsupported_items = array_merge( $unsupported_items, $this->unsupported_window_evidence( $question, 'planet', $target ) );
			$matched = true;
		}

		$target_resolution = $this->resolver->resolve_from_question( $question );
		if ( ORAS_AI_Target_Resolution::RESOLVED === $target_resolution->status() ) {
			$resolved_identity = $target_resolution->target()->identity();
			$plans[] = array( $this->catalog_provider, array( ORAS_AI_Current_Data_Request::TARGET_POSITION ), $resolved_identity );
			$unsupported_items = array_merge( $unsupported_items, $this->unsupported_window_evidence( $question, 'target', $resolved_identity ) );
			$matched = true;
		} elseif ( preg_match( '/\b(?:m\s*\d+|ngc\s*\d+|ic\s*\d+|galaxy|nebula|deep sky)\b/', $question ) ) {
			$items[] = $this->failure_evidence( 'Catalog target', 'target_not_resolved', array( 'astronomy:target:unknown:position' ) );
			$matched = true;
		}

		$fact_count = 0;
		$seen = array();
		foreach ( $plans as $plan ) {
			$key = implode( ',', $plan[1] ) . '|' . $plan[2] . '|' . $this->clock->now()->format( DATE_ATOM );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			try {
				$request = ORAS_AI_Current_Data_Request::from_authorized_request( $authorized_request, $this->clock, $plan[1], null, null, $plan[2] );
				$result  = $plan[0]->fetch( $request );
			} catch ( Throwable $throwable ) {
				$result = ORAS_AI_Current_Data_Result::unavailable( $plan[0]->provider_id(), 'provider_failed' );
			}
			if ( ! $result instanceof ORAS_AI_Current_Data_Result ) {
				$result = ORAS_AI_Current_Data_Result::unknown( $plan[0]->provider_id(), 'provider_result_malformed' );
			}
			if ( null !== $this->observability ) {
				$this->observability->record_outcome( $result->provider_id(), $result->status(), $result->reason() );
			}
			if ( ORAS_AI_Current_Data_Result::SUCCESS !== $result->status() ) {
				$items[] = $this->failure_evidence( $this->provider_label( $result->provider_id() ), $result->reason(), $this->required_fact_keys( $plan[1], $plan[2] ) );
				continue;
			}
			foreach ( $result->values() as $fact ) {
				$items[] = $this->fact_evidence( $fact );
				$fact_count++;
			}
		}
		$items = array_merge( $items, $unsupported_items );

		return new ORAS_AI_Current_Astronomy_Query_Result( $matched, $fact_count, new ORAS_AI_Evidence_Packet( $items ) );
	}

	private function fact_evidence( ORAS_AI_Astronomy_Fact $fact ) {
		$data = $fact->to_array();
		return ORAS_AI_Evidence::from_array(
			array(
				'source_type'           => 'current_astronomy',
				'artifact_title'        => $this->provider_label( $data['provider'] ),
				'source_title'          => $this->provider_label( $data['provider'] ),
				'canonical_url'         => '',
				'relevant_text'         => $this->fact_text( $data ),
				'comparison_value'      => (string) $data['value'],
				'visibility'            => 'members',
				'lifecycle'             => 'approved',
				'source_classification' => 'current_data',
				'authority_class'       => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
				'source_modified_gmt'   => $data['valid_at'],
				'synced_at'             => $data['calculated_at'],
				'fact_key'              => $data['fact_key'],
				'fact_keys'             => array( $data['fact_key'] ),
				'provider_version'      => $data['provider_version'],
				'site_identity'         => $data['site_identity'],
			)
		);
	}

	private function failure_evidence( $provider_label, $reason, array $fact_keys, $source_type = 'current_astronomy_status' ) {
		$reason = sanitize_key( $reason );
		return ORAS_AI_Evidence::from_array(
			array(
				'source_type'           => sanitize_key( $source_type ),
				'source_title'          => sanitize_text_field( $provider_label ),
				'relevant_text'         => 'Required current astronomy information could not be established (' . $reason . ').',
				'visibility'            => 'members',
				'lifecycle'             => 'approved',
				'source_classification' => 'current_data',
				'authority_class'       => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
				'fact_keys'             => $fact_keys,
			)
		);
	}

	private function unsupported_window_evidence( $question, $kind, $target ) {
		$fields = array();
		foreach ( array( 'rise' => '/\b(?:rise|rises)\b/', 'transit' => '/\btransit\b/', 'set' => '/\b(?:set|sets)\b/' ) as $field => $pattern ) {
			if ( preg_match( $pattern, $question ) ) {
				$fields[] = $field;
			}
		}
		if ( empty( $fields ) ) {
			return array();
		}
		$identities = array();
		if ( 'planet' === $kind ) {
			$bodies = 'planet:all' === $target ? ORAS_AI_Planet_Targets::allowed() : array( substr( $target, 7 ) );
			foreach ( $bodies as $body ) {
				foreach ( $fields as $field ) {
					$identities[] = 'astronomy:planet:' . $body . ':' . $field;
				}
			}
		} else {
			foreach ( $fields as $field ) {
				$identities[] = 'astronomy:target:' . $target . ':' . $field;
			}
		}
		return array( $this->failure_evidence( 'Qualified astronomy provider', 'rise_transit_set_unavailable', $identities, 'rise_transit_set_unavailable' ) );
	}

	private function required_fact_keys( array $types, $target ) {
		$keys = array();
		foreach ( $types as $type ) {
			if ( ORAS_AI_Current_Data_Request::SUNSET === $type ) {
				$keys[] = 'astronomy:sun:sunset';
			} elseif ( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS === $type ) {
				$keys = array_merge( $keys, array( 'astronomy:sun:astronomical-dawn', 'astronomy:sun:astronomical-dusk' ) );
			} elseif ( ORAS_AI_Current_Data_Request::MOON_STATE === $type ) {
				foreach ( array( 'phase', 'illumination', 'altitude', 'azimuth', 'geometric_horizon', 'rise', 'set' ) as $field ) {
					$keys[] = 'astronomy:moon:' . $field;
				}
			} elseif ( ORAS_AI_Current_Data_Request::PLANET_POSITION === $type ) {
				$bodies = 'planet:all' === $target ? ORAS_AI_Planet_Targets::allowed() : array( substr( $target, 7 ) );
				foreach ( $bodies as $body ) {
					foreach ( array( 'altitude', 'azimuth', 'geometric_horizon' ) as $field ) {
						$keys[] = 'astronomy:planet:' . $body . ':' . $field;
					}
				}
			} elseif ( ORAS_AI_Current_Data_Request::TARGET_POSITION === $type ) {
				foreach ( array( 'altitude', 'azimuth', 'geometric_horizon' ) as $field ) {
					$keys[] = 'astronomy:target:' . $target . ':' . $field;
				}
			}
		}
		return $keys;
	}

	private function fact_text( array $data ) {
		$label = str_replace( array( 'planet:', '_' ), array( '', ' ' ), $data['target_identity'] );
		$label = '' === $label ? 'astronomy' : ucfirst( $label );
		$key   = $data['fact_key'];
		$value = is_float( $data['value'] ) ? round( $data['value'], 4 ) : $data['value'];
		if ( substr( $key, -strlen( ':geometric_horizon' ) ) === ':geometric_horizon' ) {
			return $label . ' is ' . $value . ' the geometric horizon at the requested observation time.';
		}
		$field = str_replace( '_', ' ', substr( $key, strrpos( $key, ':' ) + 1 ) );
		return $label . ' ' . $field . ' is ' . $value . ( '' !== $data['unit'] ? ' ' . $data['unit'] : '' ) . ' at the requested observation time.';
	}

	private function provider_label( $provider_id ) {
		$labels = array(
			'suncalc'       => 'Local Sun and Moon calculation',
			'openngc_local' => 'OpenNGC local calculation',
			'astronomy_api' => 'AstronomyAPI',
		);
		return $labels[ $provider_id ] ?? 'Qualified astronomy provider';
	}
}
