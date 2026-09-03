<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Domain_Result {

	const ORAS       = 'oras';
	const ASTRONOMY  = 'astronomy';
	const CROSSOVER  = 'crossover';
	const OFF_TOPIC  = 'off_topic';
	const AMBIGUOUS  = 'ambiguous';

	private $outcome;

	private function __construct( $outcome ) {
		$this->outcome = $outcome;
	}

	public static function from_outcome( $outcome ) {
		$outcome = sanitize_key( $outcome );
		if ( ! in_array( $outcome, self::outcomes(), true ) ) {
			$outcome = self::AMBIGUOUS;
		}

		return new self( $outcome );
	}

	public static function ambiguous() {
		return new self( self::AMBIGUOUS );
	}

	public static function outcomes() {
		return array( self::ORAS, self::ASTRONOMY, self::CROSSOVER, self::OFF_TOPIC, self::AMBIGUOUS );
	}

	public function outcome() {
		return $this->outcome;
	}

	public function is_allowed() {
		return in_array( $this->outcome, array( self::ORAS, self::ASTRONOMY, self::CROSSOVER ), true );
	}

	public function refusal_code() {
		if ( self::OFF_TOPIC === $this->outcome ) {
			return 'outside_supported_domain';
		}

		return self::AMBIGUOUS === $this->outcome ? 'classification_unavailable' : '';
	}

	public function refusal_message() {
		if ( self::OFF_TOPIC === $this->outcome ) {
			return __( 'ORAS AI supports ORAS and astronomy questions.', 'oras-ai-assistant' );
		}

		return self::AMBIGUOUS === $this->outcome
			? __( 'The request could not be classified safely.', 'oras-ai-assistant' )
			: '';
	}
}
