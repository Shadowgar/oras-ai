<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-independent result of classifying and, when applicable, extracting a source.
 */
final class ORAS_AI_Source_Classification_Result {

	const EXTRACTION_VERSION = 1;

	private $source_kind;
	private $category;
	private $visibility;
	private $confidence;
	private $knowledge_title;
	private $reason;
	private $historical_event;
	private $stable_fragments;
	private $excluded_dynamic_claims;
	private $dynamic_fact_types;
	private $validation_status;
	private $validation_errors;
	private $requires_review;
	private $classified_by;

	private function __construct( $values ) {
		$this->source_kind             = $values['source_kind'];
		$this->category                = $values['category'];
		$this->visibility              = $values['visibility'];
		$this->confidence              = $values['confidence'];
		$this->knowledge_title         = $values['knowledge_title'];
		$this->reason                  = $values['reason'];
		$this->historical_event        = $values['historical_event'];
		$this->stable_fragments        = $values['stable_fragments'];
		$this->excluded_dynamic_claims = $values['excluded_dynamic_claims'];
		$this->dynamic_fact_types      = $values['dynamic_fact_types'];
		$this->validation_status       = $values['validation_status'];
		$this->validation_errors       = $values['validation_errors'];
		$this->requires_review         = $values['requires_review'];
		$this->classified_by           = $values['classified_by'];
	}

	public static function supported_source_kinds() {
		return array( 'static_knowledge', 'live_data', 'mixed', 'ignore', 'review' );
	}

	public static function from_array( $payload, $classified_by = 'ai', $fallback_title = '' ) {
		$payload        = is_array( $payload ) ? $payload : array();
		$errors         = array();
		$required       = array(
			'source_kind',
			'category',
			'visibility',
			'confidence',
			'knowledge_title',
			'reason',
			'historical_event',
			'stable_fragments',
			'excluded_dynamic_claims',
			'dynamic_fact_types',
			'validation',
		);

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				$errors[] = 'missing_' . $field;
			}
		}

		$source_kind     = isset( $payload['source_kind'] ) ? sanitize_key( $payload['source_kind'] ) : '';
		$category        = isset( $payload['category'] ) ? sanitize_text_field( $payload['category'] ) : '';
		$visibility      = isset( $payload['visibility'] ) ? sanitize_key( $payload['visibility'] ) : '';
		$confidence      = isset( $payload['confidence'] ) ? sanitize_key( $payload['confidence'] ) : '';
		$title           = isset( $payload['knowledge_title'] ) ? sanitize_text_field( $payload['knowledge_title'] ) : '';
		$reason          = isset( $payload['reason'] ) ? sanitize_textarea_field( $payload['reason'] ) : '';
		$classified_by   = sanitize_key( $classified_by );
		$historical_event = isset( $payload['historical_event'] ) && true === $payload['historical_event'];

		if ( ! in_array( $source_kind, self::supported_source_kinds(), true ) ) {
			$errors[] = 'invalid_source_kind';
		}

		if ( ! in_array( $category, ORAS_AI_Knowledge_Base::default_categories(), true ) ) {
			$errors[] = 'invalid_category';
		}

		if ( ! in_array( $visibility, array( 'public', 'members', 'admin' ), true ) ) {
			$errors[] = 'invalid_visibility';
		}

		if ( ! in_array( $confidence, array( 'high', 'medium', 'low' ), true ) ) {
			$errors[] = 'invalid_confidence';
		}

		if ( '' === $title ) {
			$errors[] = 'invalid_knowledge_title';
		}

		if ( '' === $reason ) {
			$errors[] = 'invalid_reason';
		}

		if ( ! array_key_exists( 'historical_event', $payload ) || ! is_bool( $payload['historical_event'] ) ) {
			$errors[] = 'invalid_historical_event';
		}

		if (
			$historical_event &&
			(
				! in_array( $source_kind, array( 'static_knowledge', 'mixed' ), true ) ||
				! in_array( $category, array( 'Events', 'AstroBlast', 'Public Nights' ), true )
			)
		) {
			$errors[] = 'invalid_historical_event_classification';
		}

		if ( ! in_array( $classified_by, array( 'rule', 'ai' ), true ) ) {
			$classified_by = 'ai';
			$errors[]       = 'invalid_classifier';
		}

		$stable_fragments = self::normalize_stable_fragments(
			isset( $payload['stable_fragments'] ) ? $payload['stable_fragments'] : null,
			$errors
		);
		$excluded_dynamic_claims = self::normalize_string_list(
			isset( $payload['excluded_dynamic_claims'] ) ? $payload['excluded_dynamic_claims'] : null,
			'invalid_excluded_dynamic_claims',
			$errors
		);
		$dynamic_fact_types = self::normalize_string_list(
			isset( $payload['dynamic_fact_types'] ) ? $payload['dynamic_fact_types'] : null,
			'invalid_dynamic_fact_types',
			$errors,
			true
		);

		$validation = isset( $payload['validation'] ) && is_array( $payload['validation'] ) ? $payload['validation'] : array();
		$separated  = isset( $validation['stable_dynamic_separation'] ) && true === $validation['stable_dynamic_separation'];
		$qualified  = isset( $validation['critical_qualifications_preserved'] ) && true === $validation['critical_qualifications_preserved'];

		if ( ! array_key_exists( 'stable_dynamic_separation', $validation ) || ! is_bool( $validation['stable_dynamic_separation'] ) ) {
			$errors[] = 'invalid_stable_dynamic_separation';
		} elseif ( ! $separated ) {
			$errors[] = 'stable_dynamic_separation_failed';
		}

		if ( ! array_key_exists( 'critical_qualifications_preserved', $validation ) || ! is_bool( $validation['critical_qualifications_preserved'] ) ) {
			$errors[] = 'invalid_critical_qualifications';
		} elseif ( ! $qualified ) {
			$errors[] = 'critical_qualifications_missing';
		}

		if ( 'mixed' === $source_kind ) {
			if ( empty( $stable_fragments ) ) {
				$errors[] = 'mixed_missing_stable_content';
			}

			if ( empty( $excluded_dynamic_claims ) && empty( $dynamic_fact_types ) ) {
				$errors[] = 'mixed_missing_dynamic_data';
			}

			foreach ( $stable_fragments as $fragment ) {
				if ( self::contains_dynamic_claim( $fragment['stable_content'] ) ) {
					$errors[] = 'dynamic_claim_in_stable_content';
					break;
				}
			}
		} elseif ( ! empty( $stable_fragments ) || ! empty( $excluded_dynamic_claims ) || ! empty( $dynamic_fact_types ) ) {
			$errors[] = 'unexpected_mixed_fields';
		}

		$errors = array_values( array_unique( $errors ) );

		if ( ! empty( $errors ) ) {
			return self::review_fallback( $payload, $classified_by, $fallback_title, $errors );
		}

		return new self(
			array(
				'source_kind'             => $source_kind,
				'category'                => $category,
				'visibility'              => $visibility,
				'confidence'              => $confidence,
				'knowledge_title'         => $title,
				'reason'                  => $reason,
				'historical_event'        => $historical_event,
				'stable_fragments'        => $stable_fragments,
				'excluded_dynamic_claims' => $excluded_dynamic_claims,
				'dynamic_fact_types'      => $dynamic_fact_types,
				'validation_status'       => 'valid',
				'validation_errors'       => array(),
				'requires_review'         => 'review' === $source_kind || 'high' !== $confidence || $historical_event,
				'classified_by'           => $classified_by,
			)
		);
	}

	private static function review_fallback( $payload, $classified_by, $fallback_title, $errors ) {
		$category = isset( $payload['category'] ) ? sanitize_text_field( $payload['category'] ) : '';
		if ( ! in_array( $category, ORAS_AI_Knowledge_Base::default_categories(), true ) ) {
			$category = 'General FAQ';
		}

		$visibility = isset( $payload['visibility'] ) ? sanitize_key( $payload['visibility'] ) : '';
		if ( ! in_array( $visibility, array( 'public', 'members', 'admin' ), true ) ) {
			$visibility = 'public';
		}

		$title = isset( $payload['knowledge_title'] ) ? sanitize_text_field( $payload['knowledge_title'] ) : '';
		if ( '' === $title ) {
			$title = sanitize_text_field( $fallback_title );
		}
		if ( '' === $title ) {
			$title = __( 'Source requires review', 'oras-ai-assistant' );
		}

		$reason = isset( $payload['reason'] ) ? sanitize_textarea_field( $payload['reason'] ) : '';
		if ( '' === $reason ) {
			$reason = __( 'The structured classification result did not pass validation.', 'oras-ai-assistant' );
		}

		$classified_by = sanitize_key( $classified_by );
		if ( ! in_array( $classified_by, array( 'rule', 'ai' ), true ) ) {
			$classified_by = 'ai';
		}

		$historical_event = isset( $payload['historical_event'] ) && true === $payload['historical_event'];

		return new self(
			array(
				'source_kind'             => 'review',
				'category'                => $category,
				'visibility'              => $visibility,
				'confidence'              => 'low',
				'knowledge_title'         => $title,
				'reason'                  => $reason,
				'historical_event'        => $historical_event,
				'stable_fragments'        => array(),
				'excluded_dynamic_claims' => array(),
				'dynamic_fact_types'      => array(),
				'validation_status'       => 'invalid',
				'validation_errors'       => array_values( array_unique( $errors ) ),
				'requires_review'         => true,
				'classified_by'           => $classified_by,
			)
		);
	}

	private static function normalize_stable_fragments( $fragments, &$errors ) {
		if ( ! is_array( $fragments ) ) {
			$errors[] = 'invalid_stable_fragments';
			return array();
		}

		$normalized = array();
		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) || ! isset( $fragment['stable_title'], $fragment['stable_content'] ) ) {
				$errors[] = 'invalid_stable_fragments';
				continue;
			}

			$title   = sanitize_text_field( $fragment['stable_title'] );
			$content = sanitize_textarea_field( $fragment['stable_content'] );

			if ( '' === $title || '' === $content ) {
				$errors[] = 'invalid_stable_fragments';
				continue;
			}

			$normalized[] = array(
				'stable_title'   => $title,
				'stable_content' => $content,
			);
		}

		return $normalized;
	}

	private static function normalize_string_list( $values, $error_code, &$errors, $sanitize_keys = false ) {
		if ( ! is_array( $values ) ) {
			$errors[] = $error_code;
			return array();
		}

		$normalized = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				$errors[] = $error_code;
				continue;
			}

			$value = $sanitize_keys ? sanitize_key( $value ) : sanitize_textarea_field( $value );
			if ( '' === $value ) {
				$errors[] = $error_code;
				continue;
			}

			$normalized[] = $value;
		}

		return array_values( array_unique( $normalized ) );
	}

	private static function contains_dynamic_claim( $content ) {
		$patterns = array(
			'/[$£€]\s*\d/u',
			'/\b\d+(?:\.\d{1,2})?\s*(?:usd|dollars?)\b/i',
			'/\b(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?\b/i',
			'/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/',
			'/\b(?:registration deadline|registration opens|registration closes|currently available|currently unavailable|sold out)\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	public function source_kind() {
		return $this->source_kind;
	}

	public function category() {
		return $this->category;
	}

	public function visibility() {
		return $this->visibility;
	}

	public function confidence() {
		return $this->confidence;
	}

	public function knowledge_title() {
		return $this->knowledge_title;
	}

	public function reason() {
		return $this->reason;
	}

	public function is_historical_event() {
		return $this->historical_event;
	}

	public function stable_fragments() {
		return $this->stable_fragments;
	}

	public function excluded_dynamic_claims() {
		return $this->excluded_dynamic_claims;
	}

	public function dynamic_fact_types() {
		return $this->dynamic_fact_types;
	}

	public function validation_status() {
		return $this->validation_status;
	}

	public function validation_errors() {
		return $this->validation_errors;
	}

	public function requires_review() {
		return $this->requires_review;
	}

	public function classified_by() {
		return $this->classified_by;
	}

	public function extraction_version() {
		return self::EXTRACTION_VERSION;
	}
}
