<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_URL_Policy {

	private $allowed_hosts;

	public function __construct( array $allowed_hosts = array() ) {
		$this->allowed_hosts = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $host ) {
							return strtolower( rtrim( trim( (string) $host ), '.' ) );
						},
						$allowed_hosts
					)
				)
			)
		);
	}

	public function allows( $url ) {
		if ( ! is_string( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			return false;
		}

		$host = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( $this->is_local_or_private_host( $host ) ) {
			return false;
		}

		return in_array( $host, $this->allowed_hosts, true );
	}

	private function is_local_or_private_host( $host ) {
		if (
			'localhost' === $host
			|| preg_match( '/\.(?:localhost|local|internal)$/', $host )
		) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return false;
	}
}
