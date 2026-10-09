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
	private $refusal_reason;

	private function __construct( $outcome, $refusal_reason = '' ) {
		$this->outcome        = $outcome;
		$this->refusal_reason = $refusal_reason;
	}

	public static function from_outcome( $outcome ) {
		$outcome = sanitize_key( $outcome );
		if ( ! in_array( $outcome, self::outcomes(), true ) ) {
			$outcome = self::AMBIGUOUS;
		}

		return new self( $outcome );
	}

	/** Security explanations retain the denied domain and accept only fixed reasons. */
	public static function security_refusal( $reason ) {
		if ( ! in_array( $reason, array( 'private_account_access', 'arbitrary_url_access' ), true ) ) {
			$reason = '';
		}

		return new self( self::OFF_TOPIC, $reason );
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
			return $this->refusal_reason ?: 'outside_supported_domain';
		}

		return self::AMBIGUOUS === $this->outcome ? 'classification_unavailable' : '';
	}

	public function refusal_message() {
		if ( self::OFF_TOPIC === $this->outcome ) {
			if ( 'private_account_access' === $this->refusal_reason ) {
				return __( "I can only access membership information associated with your authenticated account. I cannot inspect another member's private information.", 'oras-ai-assistant' );
			}
			if ( 'arbitrary_url_access' === $this->refusal_reason ) {
				return __( 'I cannot access private or arbitrary URLs. I can still help with ORAS or astronomy questions using approved sources.', 'oras-ai-assistant' );
			}

			return __( 'ORAS AI supports ORAS and astronomy questions.', 'oras-ai-assistant' );
		}

		return self::AMBIGUOUS === $this->outcome
			? __( 'The request could not be classified safely.', 'oras-ai-assistant' )
			: '';
	}
}
