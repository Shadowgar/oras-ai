<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Target_Resolution {
	const RESOLVED  = 'resolved';
	const UNKNOWN   = 'unknown';
	const AMBIGUOUS = 'ambiguous';

	private $status;
	private $target;

	private function __construct( $status, ?ORAS_AI_Resolved_Target $target = null ) {
		$this->status = $status;
		$this->target = $target;
	}

	public static function resolved( ORAS_AI_Resolved_Target $target ) { return new self( self::RESOLVED, $target ); }
	public static function unknown() { return new self( self::UNKNOWN ); }
	public static function ambiguous() { return new self( self::AMBIGUOUS ); }
	public function status() { return $this->status; }
	public function target() { return $this->target; }
}
