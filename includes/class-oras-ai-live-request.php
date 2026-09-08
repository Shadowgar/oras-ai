<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-derived request context passed to registered live connectors.
 */
final class ORAS_AI_Live_Request {

	private $authorized_request;
	private $intent;
	private $connector;
	private $subject;
	private $fact_keys;

	private function __construct( ORAS_AI_Authorized_Request $authorized_request, $intent, $connector = '', $subject = '', array $fact_keys = array() ) {
		$this->authorized_request = $authorized_request;
		$this->intent             = sanitize_key( $intent );
		$this->connector          = sanitize_key( $connector );
		$this->subject            = sanitize_key( $subject );
		$this->fact_keys          = self::normalize_fact_keys( $fact_keys );
	}

	public static function from_authorized_request( ORAS_AI_Authorized_Request $authorized_request, $intent ) {
		return new self( $authorized_request, $intent );
	}

	public function with_route( $connector, $subject, array $fact_keys ) {
		return new self( $this->authorized_request, $this->intent, $connector, $subject, $fact_keys );
	}

	public static function normalize_fact_key( $fact_key ) {
		$fact_key  = strtolower( trim( (string) $fact_key ) );
		$normalized = preg_replace( '/[^a-z0-9:._-]/', '', $fact_key );

		if (
			! is_string( $normalized )
			|| $normalized !== $fact_key
			|| ! preg_match( '/^[a-z0-9_-]+(?:[.:][a-z0-9_-]+)*$/', $normalized )
		) {
			return '';
		}

		return $normalized;
	}

	public static function normalize_fact_keys( array $fact_keys ) {
		$normalized = array();
		foreach ( $fact_keys as $fact_key ) {
			$fact_key = self::normalize_fact_key( $fact_key );
			if ( '' !== $fact_key ) {
				$normalized[ $fact_key ] = $fact_key;
			}
		}

		return array_values( $normalized );
	}

	public function user_id() {
		return $this->authorized_request->user_id();
	}

	public function question() {
		return $this->authorized_request->question();
	}

	public function allowed_visibilities() {
		return $this->authorized_request->allowed_visibilities();
	}

	public function is_administrator() {
		return $this->authorized_request->is_administrator();
	}

	public function intent() {
		return $this->intent;
	}

	public function connector() {
		return $this->connector;
	}

	public function subject() {
		return $this->subject;
	}

	public function fact_keys() {
		return $this->fact_keys;
	}
}
