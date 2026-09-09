<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Current_Data_Result {
	const SUCCESS     = 'success';
	const UNAVAILABLE = 'unavailable';
	const UNKNOWN     = 'unknown';
	const DENIED      = 'denied';

	private $provider_id;
	private $status;
	private $reason;
	private $values;

	private function __construct( $provider_id, $status, $reason, array $values = array() ) {
		$this->provider_id = $provider_id;
		$this->status      = $status;
		$this->reason      = $reason;
		$this->values      = $values;
	}

	public static function success( $provider_id, array $values ) {
		$provider_id = self::require_provider_id( $provider_id );
		if ( empty( $values ) ) {
			throw new InvalidArgumentException( 'Successful current-data result requires values.' );
		}
		foreach ( $values as $value ) {
			if ( ! $value instanceof ORAS_AI_Current_Data_Value_Interface || $provider_id !== $value->provider_id() ) {
				throw new InvalidArgumentException( 'Current-data result requires normalized provider values.' );
			}
		}

		return new self( $provider_id, self::SUCCESS, '', array_values( $values ) );
	}

	public static function unavailable( $provider_id, $reason ) {
		return self::without_values( $provider_id, self::UNAVAILABLE, $reason );
	}

	public static function unknown( $provider_id, $reason ) {
		return self::without_values( $provider_id, self::UNKNOWN, $reason );
	}

	public static function denied( $provider_id, $reason ) {
		return self::without_values( $provider_id, self::DENIED, $reason );
	}

	public static function normalize_provider_id( $provider_id ) {
		$provider_id = strtolower( trim( (string) $provider_id ) );
		return strlen( $provider_id ) <= 64 && preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $provider_id ) ? $provider_id : '';
	}

	public function provider_id() {
		return $this->provider_id;
	}

	public function status() {
		return $this->status;
	}

	public function reason() {
		return $this->reason;
	}

	public function values() {
		return $this->values;
	}

	public function to_array() {
		return array(
			'provider' => $this->provider_id,
			'status'   => $this->status,
			'reason'   => $this->reason,
			'values'   => array_map(
				static function ( ORAS_AI_Current_Data_Value_Interface $value ) {
					return $value->to_array();
				},
				$this->values
			),
		);
	}

	private static function without_values( $provider_id, $status, $reason ) {
		$provider_id = self::require_provider_id( $provider_id );
		$reason      = sanitize_key( $reason );
		if ( '' === $reason || strlen( $reason ) > 64 ) {
			throw new InvalidArgumentException( 'Invalid current-data reason.' );
		}

		return new self( $provider_id, $status, $reason );
	}

	private static function require_provider_id( $provider_id ) {
		$provider_id = self::normalize_provider_id( $provider_id );
		if ( '' === $provider_id ) {
			throw new InvalidArgumentException( 'Invalid provider identity.' );
		}
		return $provider_id;
	}
}
