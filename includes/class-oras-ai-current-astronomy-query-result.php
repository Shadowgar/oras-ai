<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Current_Astronomy_Query_Result {
	private $matched;
	private $fact_count;
	private $evidence_packet;
	private $values;

	public function __construct( $matched, $fact_count, ORAS_AI_Evidence_Packet $evidence_packet, array $values = array() ) {
		$this->matched         = (bool) $matched;
		$this->fact_count      = max( 0, (int) $fact_count );
		$this->evidence_packet = $evidence_packet;
		$this->values = $values;
	}

	public function matched() { return $this->matched; }
	public function has_facts() { return $this->fact_count > 0; }
	public function evidence_packet() { return $this->evidence_packet; }
	public function values() { return $this->values; }
}
