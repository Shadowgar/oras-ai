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
	private $failures;

	private function __construct( $status, $reason, array $facts = array(), array $failures = array() ) {
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
		$this->failures = array_slice(
			array_values(
				array_filter(
					array_map(
						static function ( $failure ) {
							if ( ! is_array( $failure ) ) {
								return null;
							}
							$connector = sanitize_key( $failure['connector'] ?? '' );
							$reason    = sanitize_key( $failure['reason'] ?? '' );
							$fact_keys = ORAS_AI_Live_Request::normalize_fact_keys( (array) ( $failure['fact_keys'] ?? array() ) );
							if ( ! in_array( $connector, array( 'events_calendar', 'woocommerce', 'pmpro' ), true ) || '' === $reason || empty( $fact_keys ) ) {
								return null;
							}

							return array(
								'connector' => $connector,
								'reason'    => $reason,
								'fact_keys' => $fact_keys,
							);
						},
						$failures
					)
				)
		),
			0,
			3
		);
	}

	public static function success( array $facts, array $failures = array() ) {
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
			: new self( self::SUCCESS, '', $facts, $failures );
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

	public function failures() {
		return $this->failures;
	}

	public function failed_fact_keys() {
		$keys = array();
		foreach ( $this->failures as $failure ) {
			foreach ( $failure['fact_keys'] as $fact_key ) {
				$keys[ $fact_key ] = $fact_key;
			}
		}

		return array_values( $keys );
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
