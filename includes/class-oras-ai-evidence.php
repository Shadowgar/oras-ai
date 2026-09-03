<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Evidence {

	private $fields;

	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Create one immutable evidence value.
	 *
	 * @param array $fields Evidence and provenance fields.
	 * @return self
	 */
	public static function from_array( array $fields ) {
		$defaults = array(
			'artifact_id'           => 0,
			'source_record_id'      => 0,
			'source_wp_object_id'   => 0,
			'source_type'           => '',
			'artifact_title'        => '',
			'source_title'          => '',
			'canonical_url'         => '',
			'relevant_text'         => '',
			'category'              => '',
			'visibility'            => '',
			'lifecycle'             => '',
			'source_classification' => '',
			'authority_class'       => '',
			'source_hash'           => '',
			'source_modified_gmt'   => '',
			'synced_at'             => '',
			'historical_event'      => false,
			'fact_key'              => '',
			'content_role'          => 'untrusted_evidence',
		);

		$fields                         = array_merge( $defaults, $fields );
		$fields['artifact_id']          = (int) $fields['artifact_id'];
		$fields['source_record_id']     = (int) $fields['source_record_id'];
		$fields['source_wp_object_id']  = (int) $fields['source_wp_object_id'];
		$fields['historical_event']     = (bool) $fields['historical_event'];
		$fields['content_role']         = 'untrusted_evidence';

		return new self( $fields );
	}

	public function field( $name ) {
		return $this->fields[ $name ] ?? null;
	}

	public function to_array() {
		return $this->fields;
	}
}
