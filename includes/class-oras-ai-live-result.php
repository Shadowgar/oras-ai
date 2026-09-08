<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Live_Result {

	const SUCCESS     = 'success';
	const UNAVAILABLE = 'unavailable';
	const UNKNOWN     = 'unknown';
	const DENIED      = 'denied';

	private $status;
	private $reason;
	private $facts;

	private function __construct( $status, $reason, array $facts = array() ) {
		$this->status = $status;
		$this->reason = sanitize_key( $reason );
		$this->facts  = array_values(
			array_filter(
				$facts,
				static function ( $fact ) {
					return $fact instanceof ORAS_AI_Live_Fact;
				}
			)
		);
	}

	public static function success( array $facts ) {
		$facts = array_values(
			array_filter(
				$facts,
				static function ( $fact ) {
					return $fact instanceof ORAS_AI_Live_Fact;
				}
			)
		);

		return empty( $facts )
			? self::unknown( 'live_facts_missing' )
			: new self( self::SUCCESS, '', $facts );
	}

	public static function unavailable( $reason ) {
		return new self( self::UNAVAILABLE, $reason );
	}

	public static function unknown( $reason ) {
		return new self( self::UNKNOWN, $reason );
	}

	public static function denied( $reason ) {
		return new self( self::DENIED, $reason );
	}

	public function status() {
		return $this->status;
	}

	public function reason() {
		return $this->reason;
	}

	public function facts() {
		return $this->facts;
	}

	public function fact_keys() {
		$keys = array();
		foreach ( $this->facts as $fact ) {
			$keys[ $fact->fact_key() ] = $fact->fact_key();
		}

		return array_values( $keys );
	}

	public function successful() {
		return self::SUCCESS === $this->status;
	}
}
