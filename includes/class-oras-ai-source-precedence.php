<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Source_Precedence {

	const LIVE_ORAS_STATE             = 'live_oras_state';
	const APPROVED_ORAS_POLICY        = 'approved_oras_policy';
	const SYNCHRONIZED_ORAS_KNOWLEDGE = 'synchronized_oras_knowledge';
	const CURRENT_ASTRONOMY_WEATHER   = 'current_astronomy_weather';
	const GENERAL_MODEL_ASTRONOMY     = 'general_model_astronomy';
	const NO_ANSWER_ESCALATION        = 'no_answer_escalation';

	public static function priority( $authority_class ) {
		$priorities = array(
			self::LIVE_ORAS_STATE             => 600,
			self::APPROVED_ORAS_POLICY        => 500,
			self::SYNCHRONIZED_ORAS_KNOWLEDGE => 400,
			self::CURRENT_ASTRONOMY_WEATHER   => 300,
			self::GENERAL_MODEL_ASTRONOMY     => 200,
			self::NO_ANSWER_ESCALATION        => 100,
		);

		return $priorities[ $authority_class ] ?? 0;
	}

	/**
	 * Select the authoritative injected evidence for one fact.
	 *
	 * @param ORAS_AI_Evidence[] $candidates Evidence from retrieval or a future live boundary.
	 * @param string             $fact_key Fact identity.
	 * @param string             $intent Trusted intent.
	 * @return ORAS_AI_Evidence|null
	 */
	public function select_for_fact( array $candidates, $fact_key, $intent ) {
		$eligible = array_values(
			array_filter(
				$candidates,
				static function ( $candidate ) use ( $fact_key, $intent ) {
					if ( ! $candidate instanceof ORAS_AI_Evidence ) {
						return false;
					}

					if ( (string) $candidate->field( 'fact_key' ) !== (string) $fact_key ) {
						return false;
					}

					return ! (
						ORAS_AI_Retrieval_Request::INTENT_CURRENT === $intent
						&& $candidate->field( 'historical_event' )
					);
				}
			)
		);

		usort(
			$eligible,
			static function ( ORAS_AI_Evidence $left, ORAS_AI_Evidence $right ) {
				$priority = self::priority( $right->field( 'authority_class' ) )
					<=> self::priority( $left->field( 'authority_class' ) );
				if ( 0 !== $priority ) {
					return $priority;
				}

				return (int) $left->field( 'artifact_id' ) <=> (int) $right->field( 'artifact_id' );
			}
		);

		return $eligible[0] ?? null;
	}
}
