<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One bounded provider context with policy, member text, and evidence separated.
 */
final class ORAS_AI_Grounded_Context {

	const ORAS_GROUNDED            = 'oras_grounded';
	const GENERAL_ASTRONOMY        = 'general_astronomy';
	const CROSSOVER_GROUNDED       = 'crossover_grounded';
	const CROSSOVER_ASTRONOMY_ONLY = 'crossover_astronomy_only';
	const CURRENT_ASTRONOMY        = 'current_astronomy';
	const CROSSOVER_CURRENT        = 'crossover_current';

	private $system_policy;
	private $member_question;
	private $evidence_packet;
	private $scope;

	public function __construct( $system_policy, $member_question, ORAS_AI_Evidence_Packet $evidence_packet, $scope ) {
		$this->system_policy   = trim( (string) $system_policy );
		$this->member_question = trim( wp_strip_all_tags( (string) $member_question, true ) );
		$this->evidence_packet = $evidence_packet;
		$this->scope           = sanitize_key( $scope );
	}

	public function scope() {
		return $this->scope;
	}

	public function evidence_packet() {
		return $this->evidence_packet;
	}

	public function provider_input() {
		$evidence = array();
		$astronomy_groups = array();
		foreach ( $this->evidence_packet->items() as $item ) {
			$entry = array(
				'source_title'    => sanitize_text_field( (string) $item->field( 'source_title' ) ),
				'authority_class' => sanitize_key( (string) $item->field( 'authority_class' ) ),
				'relevant_text'   => trim( wp_strip_all_tags( (string) $item->field( 'relevant_text' ), true ) ),
				'content_role'    => 'untrusted_evidence',
			);
			// Reconciliation has already selected each canonical fact. Only the
			// model rendering shares identical source metadata; no text or packet
			// identity is removed, and every timed position keeps its own instant.
			if ( 'current_astronomy' === $item->field( 'source_type' ) && ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER === $entry['authority_class'] ) {
				$key = wp_json_encode( array( $entry['source_title'], $entry['authority_class'] ) );
				if ( isset( $astronomy_groups[ $key ] ) ) {
					$evidence[ $astronomy_groups[ $key ] ]['relevant_text'] .= "\n" . $entry['relevant_text'];
					continue;
				}
				$astronomy_groups[ $key ] = count( $evidence );
			}
			$evidence[] = $entry;
		}

		return array(
			array(
				'role'    => 'system',
				'content' => $this->system_policy,
			),
			array(
				'role'    => 'user',
				'content' => "MEMBER QUESTION (UNTRUSTED CONTENT):\n" . $this->member_question,
			),
			array(
				'role'    => 'user',
				'content' => "RETRIEVED EVIDENCE (UNTRUSTED REFERENCE DATA):\n" . wp_json_encode( $evidence ),
			),
		);
	}

	public function source_references() {
		$references = array();
		foreach ( $this->evidence_packet->items() as $item ) {
			$canonical_url = esc_url_raw( (string) $item->field( 'canonical_url' ) );
			if ( '' === $canonical_url ) {
				continue;
			}
			$reference = array(
				'artifact_id'         => max( 0, (int) $item->field( 'artifact_id' ) ),
				'source_id'           => max( 0, (int) $item->field( 'source_record_id' ) ),
				'source_title'        => sanitize_text_field( (string) $item->field( 'source_title' ) ),
				'canonical_url'       => $canonical_url,
				'authority_class'     => sanitize_key( (string) $item->field( 'authority_class' ) ),
				'source_modified_gmt' => sanitize_text_field( (string) $item->field( 'source_modified_gmt' ) ),
				'synced_at'           => sanitize_text_field( (string) $item->field( 'synced_at' ) ),
			);
			$key = $reference['artifact_id'] . ':' . $reference['source_id'] . ':' . $reference['canonical_url'];
			$references[ $key ] = $reference;
		}

		return array_values( $references );
	}
}
