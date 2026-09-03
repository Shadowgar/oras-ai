<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Retrieval_Request {

	const INTENT_GENERAL    = 'general';
	const INTENT_POLICY     = 'policy';
	const INTENT_HISTORICAL = 'historical';
	const INTENT_CURRENT    = 'current';

	private $query;
	private $allowed_visibilities;
	private $intent;
	private $category;
	private $fact_key;
	private $top_k;
	private $text_budget;

	private function __construct( array $args ) {
		$this->query                = $args['query'];
		$this->allowed_visibilities = $args['allowed_visibilities'];
		$this->intent               = $args['intent'];
		$this->category             = $args['category'];
		$this->fact_key             = $args['fact_key'];
		$this->top_k                = $args['top_k'];
		$this->text_budget          = $args['text_budget'];
	}

	/**
	 * Build a request from inputs already authorized by the caller.
	 *
	 * Identity and membership resolution intentionally remain outside Task 1.
	 *
	 * @param array $args Trusted retrieval inputs.
	 * @return self
	 */
	public static function from_trusted_context( array $args ) {
		$allowed_visibility = array( 'public', 'members', 'admin' );
		$visibilities       = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) ( $args['allowed_visibilities'] ?? array() ) ),
					static function ( $visibility ) use ( $allowed_visibility ) {
						return in_array( $visibility, $allowed_visibility, true );
					}
				)
			)
		);

		$allowed_intents = array(
			self::INTENT_GENERAL,
			self::INTENT_POLICY,
			self::INTENT_HISTORICAL,
			self::INTENT_CURRENT,
		);
		$intent          = sanitize_key( $args['intent'] ?? self::INTENT_GENERAL );
		if ( ! in_array( $intent, $allowed_intents, true ) ) {
			$intent = self::INTENT_GENERAL;
		}

		return new self(
			array(
				'query'                => sanitize_text_field( $args['query'] ?? '' ),
				'allowed_visibilities' => $visibilities,
				'intent'               => $intent,
				'category'             => sanitize_text_field( $args['category'] ?? '' ),
				'fact_key'             => sanitize_key( $args['fact_key'] ?? '' ),
				'top_k'                => isset( $args['top_k'] ) ? (int) $args['top_k'] : 5,
				'text_budget'          => isset( $args['text_budget'] ) ? (int) $args['text_budget'] : 6000,
			)
		);
	}

	public function query() {
		return $this->query;
	}

	public function allowed_visibilities() {
		return $this->allowed_visibilities;
	}

	public function intent() {
		return $this->intent;
	}

	public function category() {
		return $this->category;
	}

	public function fact_key() {
		return $this->fact_key;
	}

	public function top_k() {
		return $this->top_k;
	}

	public function text_budget() {
		return $this->text_budget;
	}
}
