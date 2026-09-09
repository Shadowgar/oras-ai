<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Live_Conflict_Observer {

	const REASON = 'live_static_conflict';

	public function observe( $fact_key, ORAS_AI_Evidence $selected, array $candidates ) {
		$fact_key = ORAS_AI_Live_Request::normalize_fact_key( $fact_key );
		if ( '' === $fact_key || ORAS_AI_Source_Precedence::LIVE_ORAS_STATE !== $selected->field( 'authority_class' ) ) {
			return;
		}

		$live_value = $this->normalized_value( $fact_key, $selected, true );
		if ( null === $live_value ) {
			return;
		}

		foreach ( $candidates as $candidate ) {
			if (
				! $candidate instanceof ORAS_AI_Evidence
				|| ORAS_AI_Source_Precedence::SYNCHRONIZED_ORAS_KNOWLEDGE !== $candidate->field( 'authority_class' )
			) {
				continue;
			}
			$static_value = $this->normalized_value( $fact_key, $candidate, false );
			if ( null !== $static_value && $this->values_differ( $live_value, $static_value ) ) {
				$this->signal_artifact( $candidate );
			}
		}
	}

	private function normalized_value( $fact_key, ORAS_AI_Evidence $evidence, $is_live ) {
		$value = $is_live
			? trim( (string) $evidence->field( 'comparison_value' ) )
			: trim( (string) $evidence->field( 'relevant_text' ) );
		if ( '' === $value ) {
			return null;
		}

		$field = substr( $fact_key, strrpos( $fact_key, ':' ) + 1 );
		if ( in_array( $field, array( 'start', 'end' ), true ) ) {
			return $this->datetime_value( $value );
		}
		if ( 'price' === $field ) {
			return $this->price_value( $value );
		}
		if ( 'membership-status' === $field ) {
			return $this->status_value( $value );
		}
		if ( 'purchasable' === $field ) {
			return $this->boolean_value( $value, 'purchasable' );
		}
		if ( 'availability' === $field ) {
			return $this->availability_value( $value );
		}
		if ( in_array( $field, array( 'venue', 'membership-level' ), true ) ) {
			return $this->text_value( $value, $field, $is_live );
		}

		return null;
	}

	private function datetime_value( $value ) {
		$iso_pattern = '/\b[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:Z|[+-][0-9]{2}:[0-9]{2})\b/';
		if ( preg_match( $iso_pattern, $value, $match ) ) {
			try {
				return array(
					'type'    => 'datetime',
					'instant' => ( new DateTimeImmutable( $match[0] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
					'date'    => substr( $match[0], 0, 10 ),
				);
			} catch ( Throwable $throwable ) {
				return null;
			}
		}

		$months = 'January|February|March|April|May|June|July|August|September|October|November|December';
		if ( preg_match( '/\b(' . $months . ')\s+([0-9]{1,2}),\s+([0-9]{4})\b/i', $value, $match ) ) {
			try {
				return array(
					'type'    => 'datetime',
					'instant' => '',
					'date'    => ( new DateTimeImmutable( $match[0], new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' ),
				);
			} catch ( Throwable $throwable ) {
				return null;
			}
		}

		return null;
	}

	private function values_differ( $live_value, $static_value ) {
		if (
			is_array( $live_value )
			&& is_array( $static_value )
			&& 'datetime' === ( $live_value['type'] ?? '' )
			&& 'datetime' === ( $static_value['type'] ?? '' )
		) {
			if ( '' !== $live_value['instant'] && '' !== $static_value['instant'] ) {
				return $live_value['instant'] !== $static_value['instant'];
			}

			return $live_value['date'] !== $static_value['date'];
		}

		return $live_value !== $static_value;
	}

	private function price_value( $value ) {
		preg_match_all( '/(?:\$\s*)?\b([0-9]+(?:\.[0-9]+)?)\s*(USD)?\b/i', $value, $matches, PREG_SET_ORDER );
		$prices = array();
		foreach ( $matches as $match ) {
			if ( empty( $match[2] ) && false === strpos( $match[0], '$' ) ) {
				continue;
			}
			$parts   = explode( '.', $match[1], 2 );
			$integer = ltrim( $parts[0], '0' );
			$decimal = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
			$number  = ( '' === $integer ? '0' : $integer ) . ( '' === $decimal ? '' : '.' . $decimal );
			$prices[ 'USD:' . $number ] = 'USD:' . $number;
		}

		return 1 === count( $prices ) ? reset( $prices ) : null;
	}

	private function status_value( $value ) {
		$value = strtolower( $value );
		if ( preg_match( '/\b(?:inactive|no current active membership)\b/', $value ) ) {
			return 'inactive';
		}
		return preg_match( '/\bactive\b/', $value ) ? 'active' : null;
	}

	private function boolean_value( $value, $label ) {
		if ( preg_match( '/\b' . preg_quote( $label, '/' ) . '\s*:\s*(yes|no)\b/i', $value, $match ) ) {
			return strtolower( $match[1] );
		}

		return in_array( strtolower( trim( $value ) ), array( 'yes', 'no' ), true ) ? strtolower( trim( $value ) ) : null;
	}

	private function availability_value( $value ) {
		if ( preg_match( '/\bstock status\s+(instock|outofstock|onbackorder)\b/i', $value, $stock ) ) {
			$in_stock = $this->boolean_value( $value, 'in stock' );
			return null === $in_stock ? null : strtolower( $stock[1] ) . '|' . $in_stock;
		}

		return preg_match( '/^(instock|outofstock|onbackorder)\|(yes|no)$/i', trim( $value ) )
			? strtolower( trim( $value ) )
			: null;
	}

	private function text_value( $value, $field, $is_live ) {
		if ( ! $is_live ) {
			$pattern = 'membership-level' === $field
				? '/\bmembership (?:level|tier)\s*(?::|is)\s*([^.;]+)/i'
				: '/\b(?:venue|location)\s*(?::|is)\s*([^.;]+)/i';
			if ( ! preg_match( $pattern, $value, $match ) ) {
				return null;
			}
			$value = $match[1];
		}

		$value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ), " \t\n\r\0\x0B." ) );
		return '' === $value ? null : $value;
	}

	private function signal_artifact( ORAS_AI_Evidence $candidate ) {
		$artifact_id = absint( $candidate->field( 'artifact_id' ) );
		$source_id   = absint( $candidate->field( 'source_record_id' ) );
		if (
			! $artifact_id
			|| ! $source_id
			|| ORAS_AI_Knowledge_Base::POST_TYPE !== get_post_type( $artifact_id )
			|| ORAS_AI_Sources::POST_TYPE !== get_post_type( $source_id )
			|| ! ORAS_AI_Knowledge_Base::is_scanner_managed( $artifact_id )
			|| $source_id !== absint( get_post_meta( $artifact_id, '_oras_ai_source_record_id', true ) )
		) {
			return;
		}
		if (
			'review' === ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id )
			&& self::REASON === get_post_meta( $artifact_id, '_oras_ai_review_reason', true )
		) {
			return;
		}

		update_post_meta( $artifact_id, '_oras_ai_status', 'review' );
		update_post_meta( $artifact_id, '_oras_ai_review_reason', self::REASON );
		update_post_meta( $source_id, '_oras_ai_scan_status', 'review' );
		$count = min( 999999, absint( get_post_meta( $source_id, '_oras_ai_problem_count', true ) ) + 1 );
		update_post_meta( $source_id, '_oras_ai_problem_count', $count );
		update_post_meta( $source_id, '_oras_ai_last_problem_kind', 'review' );
		update_post_meta( $source_id, '_oras_ai_last_problem', __( 'Authoritative live data conflicts with synchronized knowledge.', 'oras-ai-assistant' ) );
		update_post_meta( $source_id, '_oras_ai_last_problem_at', current_time( 'mysql' ) );
	}
}
