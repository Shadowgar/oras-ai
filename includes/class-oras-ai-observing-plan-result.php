<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded factual state for one authorized observing question. */
final class ORAS_AI_Observing_Plan_Result {
	private $matched;
	private $state;
	private $evidence_packet;
	private $best_intervals;

	public function __construct( $matched, $state, ORAS_AI_Evidence_Packet $evidence_packet, array $best_intervals = array() ) {
		if ( ! in_array( $state, array( 'grounded', 'partially_grounded', 'unavailable' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid observing plan state.' );
		}
		$this->matched        = (bool) $matched;
		$this->state          = $state;
		$this->evidence_packet = $evidence_packet;
		$this->best_intervals = $best_intervals;
	}

	public function matched() { return $this->matched; }
	public function state() { return $this->state; }
	public function evidence_packet() { return $this->evidence_packet; }
	public function best_intervals() { return $this->best_intervals; }
}
