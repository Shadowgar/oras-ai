<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Current_Weather_Query_Result {
	private $matched;
	private $fact_count;
	private $evidence_packet;
	private $values;
	private $requested_at;
	private $window_end;

	public function __construct( $matched, $fact_count, ORAS_AI_Evidence_Packet $evidence_packet, array $values = array(), ?DateTimeImmutable $requested_at = null, ?DateTimeImmutable $window_end = null ) {
		$this->matched = (bool) $matched;
		$this->fact_count = max( 0, (int) $fact_count );
		$this->evidence_packet = $evidence_packet;
		$this->values = $values;
		$this->requested_at = $requested_at;
		$this->window_end = $window_end;
	}

	public function matched() { return $this->matched; }
	public function has_facts() { return $this->fact_count > 0; }
	public function fact_count() { return $this->fact_count; }
	public function evidence_packet() { return $this->evidence_packet; }
	public function values() { return $this->values; }
	public function requested_at() { return $this->requested_at; }
	public function window_end() { return $this->window_end; }
}
