<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One normalized least-field fact returned by a live connector.
 */
final class ORAS_AI_Live_Fact {

	private $fields;

	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	public static function from_array( array $fields ) {
		$fact_key = ORAS_AI_Live_Request::normalize_fact_key( $fields['fact_key'] ?? '' );
		$title    = sanitize_text_field( (string) ( $fields['source_title'] ?? '' ) );
		$text     = trim( wp_strip_all_tags( (string) ( $fields['relevant_text'] ?? '' ), true ) );
		$comparison_value = sanitize_text_field( (string) ( $fields['comparison_value'] ?? '' ) );
		$visibility = sanitize_key( $fields['visibility'] ?? '' );

		if (
			'' === $fact_key
			|| '' === $title
			|| '' === $text
			|| ! in_array( $visibility, array( 'public', 'members', 'admin' ), true )
		) {
			return new WP_Error( 'oras_ai_live_fact_invalid', __( 'The live fact was malformed.', 'oras-ai-assistant' ) );
		}

		return new self(
			array(
				'fact_key'              => $fact_key,
				'source_title'          => $title,
				'source_wp_object_id'   => absint( $fields['source_wp_object_id'] ?? 0 ),
				'source_type'           => sanitize_key( $fields['source_type'] ?? '' ),
				'canonical_url'         => trim( (string) ( $fields['canonical_url'] ?? '' ) ),
				'relevant_text'         => substr( $text, 0, 1000 ),
				'comparison_value'      => substr( $comparison_value, 0, 500 ),
				'visibility'            => $visibility,
				'source_modified_gmt'   => sanitize_text_field( (string) ( $fields['source_modified_gmt'] ?? '' ) ),
				'retrieved_at'          => sanitize_text_field( (string) ( $fields['retrieved_at'] ?? '' ) ),
			)
		);
	}

	public function fact_key() {
		return $this->fields['fact_key'];
	}

	public function relevant_text() {
		return $this->fields['relevant_text'];
	}

	public function field( $name ) {
		return $this->fields[ $name ] ?? null;
	}

	public function to_array() {
		return $this->fields;
	}
}
