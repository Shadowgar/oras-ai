<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fixed-slot, normalized local cache for the NWS adapter. */
final class ORAS_AI_NWS_Cache {
	const OPTION = 'oras_ai_nws_normalized_cache';

	private const MAX_TTLS = array(
		'point_mapping'      => 86400,
		'station_mapping'    => 86400,
		'latest_observation' => 300,
		'forecast_grid'      => 600,
	);

	private $clock;

	public function __construct( ORAS_AI_Clock_Interface $clock ) {
		$this->clock = $clock;
	}

	public function get( $slot ) {
		$slot = sanitize_key( $slot );
		if ( ! isset( self::MAX_TTLS[ $slot ] ) ) {
			return null;
		}
		$stored = get_option( self::OPTION, array() );
		$item   = is_array( $stored ) && isset( $stored[ $slot ] ) && is_array( $stored[ $slot ] ) ? $stored[ $slot ] : array();
		if ( ! isset( $item['expires_at'], $item['value'] ) || (int) $item['expires_at'] <= $this->clock->now()->getTimestamp() || ! is_array( $item['value'] ) ) {
			return null;
		}
		return $item['value'];
	}

	public function put( $slot, array $value, $ttl ) {
		$slot = sanitize_key( $slot );
		if ( ! isset( self::MAX_TTLS[ $slot ] ) ) {
			return false;
		}
		$ttl = min( self::MAX_TTLS[ $slot ], max( 0, (int) $ttl ) );
		if ( 0 === $ttl ) {
			return false;
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$bounded = array();
		foreach ( self::MAX_TTLS as $key => $unused ) {
			if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$bounded[ $key ] = $stored[ $key ];
			}
			if ( $key === $slot ) {
				$bounded[ $key ] = array(
					'expires_at' => $this->clock->now()->getTimestamp() + $ttl,
					'value'      => $value,
				);
			}
		}
		return update_option( self::OPTION, $bounded, false );
	}

	public function delete( $slot ) {
		$slot = sanitize_key( $slot );
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $slot ] ) ) {
			return false;
		}
		unset( $stored[ $slot ] );
		return update_option( self::OPTION, $stored, false );
	}
}
