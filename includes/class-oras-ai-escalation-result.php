<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Escalation_Result {
	private $status;
	private $proposal;

	private function __construct( $status, $proposal = null ) {
		$this->status   = $status;
		$this->proposal = $proposal;
	}

	public static function none() { return new self( 'none' ); }
	public static function routing_unavailable() { return new self( 'routing_unavailable' ); }
	public static function proposed( ORAS_AI_Escalation_Proposal $proposal ) { return new self( 'proposed', $proposal ); }

	public function status() { return $this->status; }
	public function proposal() { return $this->proposal; }

	public function to_member_array() {
		return 'proposed' === $this->status
			? array( 'status' => $this->status, 'preview' => $this->proposal->to_member_array() )
			: array( 'status' => $this->status );
	}
}
