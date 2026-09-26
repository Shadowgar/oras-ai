<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Aligns qualified current-data facts without creating an independent score. */
final class ORAS_AI_Observing_Planner {
	private $weather;
	private $astronomy;
	private $score;
	private $clock;

	public function __construct( ORAS_AI_Current_Weather_Service $weather, ORAS_AI_Current_Astronomy_Service $astronomy, ORAS_AI_Member_Hub_Score_Adapter $score, ORAS_AI_Clock_Interface $clock ) {
		$this->weather   = $weather;
		$this->astronomy = $astronomy;
		$this->score     = $score;
		$this->clock     = $clock;
	}

	public function query( ORAS_AI_Authorized_Request $request ) {
		$question = strtolower( trim( wp_strip_all_tags( $request->question(), true ) ) );
		if ( ! $this->matches( $question ) ) {
			return new ORAS_AI_Observing_Plan_Result( false, 'unavailable', new ORAS_AI_Evidence_Packet() );
		}
		if ( preg_match( '/\bweekend\b/', $question ) ) {
			return new ORAS_AI_Observing_Plan_Result( true, 'unavailable', new ORAS_AI_Evidence_Packet( array(
				$this->planning_status( 'weekend_comparison_unavailable', 'A definitive best-night comparison this weekend is unavailable without equivalent qualified intervals for each night.' ),
			) ) );
		}
		$weather = $this->weather->query( $request, null, null, true );
		$weather_items = $weather->evidence_packet()->items();
		$items = $weather->has_facts() ? array() : $weather_items;
		$start = $weather->requested_at();
		$end   = $weather->window_end();
		if ( ! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable ) {
			return new ORAS_AI_Observing_Plan_Result( true, 'unavailable', new ORAS_AI_Evidence_Packet( $items ) );
		}
		$items[] = $this->window_evidence( $start, $end );
		$broad_planets = (bool) preg_match( '/\b(?:what can i see|which planets|what planets)\b/', $question );
		$partial = ! $weather->has_facts();
		$scores = array();
		$periods = array();
		foreach ( $weather->values() as $index => $snapshot ) {
			if ( ! $snapshot instanceof ORAS_AI_Weather_Snapshot ) {
				continue;
			}
			$data = $snapshot->to_array();
			$from = new DateTimeImmutable( $data['valid_from'] );
			$until = new DateTimeImmutable( $data['valid_until'] );
			$outside_window = $start->getTimestamp() === $end->getTimestamp()
				? $until <= $start || $from > $start
				: $until <= $start || $from >= $end;
			if ( ORAS_AI_Weather_Snapshot::FORECAST !== $data['designation'] || $outside_window || $until <= $from ) {
				continue;
			}
			if ( isset( $weather_items[ $index ] ) ) {
				$items[] = $weather_items[ $index ];
			}
			$overlap_from  = $from > $start ? $from : $start;
			$overlap_until = $until < $end ? $until : $end;
			$midpoint = new DateTimeImmutable( '@' . (int) floor( ( $overlap_from->getTimestamp() + $overlap_until->getTimestamp() ) / 2 ) );
			$astronomy = $this->astronomy->query( $request, $midpoint, true, $broad_planets );
			$items = array_merge( $items, $astronomy->evidence_packet()->items() );
			if ( ! $astronomy->has_facts() || $this->has_astronomy_failure( $astronomy->evidence_packet() ) ) {
				$partial = true;
			}
			$score = $this->score->score( $snapshot, $astronomy->values(), $midpoint );
			if ( ORAS_AI_Current_Data_Result::SUCCESS !== $score->status() ) {
				$partial = true;
				$items[] = $this->score_unavailable_evidence( $midpoint, $score->reason() );
			} else {
				$value = $score->values()[0];
				$items[] = $this->score_evidence( $value, $midpoint, $from, $until );
				$scores[] = $value->to_array()['score'];
				$periods[] = array( 'valid_from' => $from->format( DATE_ATOM ), 'valid_until' => $until->format( DATE_ATOM ), 'evaluation_at' => $midpoint->format( DATE_ATOM ) );
			}
		}
		if ( empty( $weather->values() ) ) {
			// Dusk remains a specifically timed astronomy fact when NWS cannot supply periods.
			$astronomy = $this->astronomy->query( $request, $start, true, $broad_planets );
			$items = array_merge( $items, $astronomy->evidence_packet()->items() );
			$partial = true;
		}
		$best = array();
		if ( ! $partial && ! empty( $scores ) && preg_match( '/\b(?:best|better)\b/', $question ) ) {
			$highest = max( $scores );
			foreach ( $scores as $index => $score ) {
				if ( $score === $highest ) {
					$best[] = $periods[ $index ];
				}
			}
			$items[] = $this->best_evidence( $best, $highest );
		}
		$state = empty( $items ) ? 'unavailable' : ( $partial || empty( $scores ) ? 'partially_grounded' : 'grounded' );
		return new ORAS_AI_Observing_Plan_Result( true, $state, new ORAS_AI_Evidence_Packet( $items ), $best );
	}

	private function matches( $question ) {
		$observing_intent = (bool) preg_match( '/\b(?:can i see|what can i see|which planets|what planets|best.*(?:night|tonight|observing)|when.*best.*observing|observing recommendation)\b/', $question );
		$timed = (bool) preg_match( '/\b(?:tonight|tomorrow|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday|20\d{2}-\d{2}-\d{2})\b|\b(?:from|at)\s+\d{1,2}(?::[0-5]\d)?\s*(?:am|pm)\b|\b(?:from|at)\s+\d{1,2}:[0-5]\d\b/', $question );
		return $observing_intent && $timed;
	}

	private function has_astronomy_failure( ORAS_AI_Evidence_Packet $packet ) {
		foreach ( $packet->items() as $item ) {
			if ( in_array( $item->field( 'source_type' ), array( 'current_astronomy_status', 'rise_transit_set_unavailable' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function instant_key( DateTimeImmutable $instant ) {
		return 'at_' . strtolower( $instant->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis' ) );
	}

	private function window_evidence( DateTimeImmutable $from, DateTimeImmutable $until ) {
		return ORAS_AI_Evidence::from_array( array(
			'source_type' => 'current_astronomy', 'source_title' => 'ORAS observing interval',
			'relevant_text' => 'The requested ORAS forecast interval begins ' . $from->format( DATE_ATOM ) . ' and ends ' . $until->format( DATE_ATOM ) . '.',
			'visibility' => 'members', 'lifecycle' => 'approved', 'source_classification' => 'current_data',
			'authority_class' => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
			'fact_keys' => array( 'observing:window:' . $this->instant_key( $from ) . ':' . $this->instant_key( $until ) ),
		) );
	}

	private function score_evidence( ORAS_AI_Observing_Score_Result $score, DateTimeImmutable $instant, DateTimeImmutable $from, DateTimeImmutable $until ) {
		$data = $score->to_array();
		$text = 'ORAS Observing Score at ' . $instant->format( DATE_ATOM ) . ' for forecast period ' . $from->format( DATE_ATOM ) . ' to ' . $until->format( DATE_ATOM )
			. ': ' . $data['score'] . '/100 (' . $data['category'] . '); model ' . $data['model_version'] . '; method ' . $data['method_version']
			. '; confidence ' . ( $data['confidence'] ?: 'unavailable' ) . '; limiter ' . ( $data['limiter'] ?: 'unavailable' )
			. '; weather input fresh ' . $data['data_at'] . '. Smoke/AQI unavailable; this is a limited-confidence score, not measured transparency.';
		return ORAS_AI_Evidence::from_array( array(
			'source_type' => 'current_observing_score', 'source_title' => 'ORAS Observing Score', 'canonical_url' => '',
			'relevant_text' => $text, 'visibility' => 'members', 'lifecycle' => 'approved', 'source_classification' => 'current_data',
			'authority_class' => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
			'fact_key' => 'observing:score:oras_observatory:' . $this->instant_key( $instant ),
			'fact_keys' => array( 'observing:score:oras_observatory:' . $this->instant_key( $instant ) ),
			'source_modified_gmt' => $data['data_at'], 'synced_at' => $data['calculated_at'],
		) );
	}

	private function score_unavailable_evidence( DateTimeImmutable $instant, $reason ) {
		return ORAS_AI_Evidence::from_array( array(
			'source_type' => 'current_observing_score_status', 'source_title' => 'ORAS Observing Score',
			'relevant_text' => 'ORAS Observing Score unavailable at ' . $instant->format( DATE_ATOM ) . ' (' . sanitize_key( $reason ) . ').',
			'visibility' => 'members', 'lifecycle' => 'approved', 'source_classification' => 'current_data',
			'authority_class' => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
			'fact_keys' => array( 'observing:score:oras_observatory:' . $this->instant_key( $instant ) ),
		) );
	}

	private function best_evidence( array $intervals, $score ) {
		$times = array_map( static function ( $item ) { return $item['valid_from'] . ' to ' . $item['valid_until']; }, $intervals );
		return ORAS_AI_Evidence::from_array( array(
			'source_type' => 'current_observing_comparison', 'source_title' => 'ORAS Observing Score',
			'relevant_text' => 'The highest available authoritative interval score is ' . (int) $score . '/100 for: ' . implode( '; ', $times ) . '. Equal scores remain tied.',
			'visibility' => 'members', 'lifecycle' => 'approved', 'source_classification' => 'current_data',
			'authority_class' => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
			'fact_keys' => array( 'observing:interval_comparison' ),
		) );
	}

	private function planning_status( $reason, $message ) {
		return ORAS_AI_Evidence::from_array( array(
			'source_type' => 'current_observing_status', 'source_title' => 'ORAS observing planner',
			'relevant_text' => $message, 'visibility' => 'members', 'lifecycle' => 'approved', 'source_classification' => 'current_data',
			'authority_class' => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
			'fact_keys' => array( 'observing:status:' . sanitize_key( $reason ) ),
		) );
	}
}
