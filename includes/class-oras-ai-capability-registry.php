<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Capability_Registry {

	private $definitions = array();

	public function __construct( array $definitions = array() ) {
		foreach ( $definitions as $identifier => $definition ) {
			$identifier = (string) $identifier;
			if ( '' === $identifier || sanitize_key( $identifier ) !== $identifier || ! is_array( $definition ) ) {
				continue;
			}

			$arguments = isset( $definition['arguments'] ) && is_array( $definition['arguments'] )
				? $definition['arguments']
				: array();
			$required  = isset( $definition['required'] ) && is_array( $definition['required'] )
				? array_values( $definition['required'] )
				: array();

			$this->definitions[ $identifier ] = array(
				'arguments' => $arguments,
				'required'  => $required,
				'max_depth' => max( 0, (int) ( $definition['max_depth'] ?? 0 ) ),
			);
		}
	}

	public function identifiers() {
		return array_keys( $this->definitions );
	}

	public function authorize_invocation( $identifier, $arguments, $depth ) {
		if (
			! is_string( $identifier )
			|| ! isset( $this->definitions[ $identifier ] )
			|| ! is_array( $arguments )
			|| ! is_int( $depth )
			|| $depth < 0
		) {
			return $this->denied();
		}

		$definition = $this->definitions[ $identifier ];
		if ( $depth > $definition['max_depth'] ) {
			return $this->denied();
		}

		foreach ( $definition['required'] as $required ) {
			if ( ! array_key_exists( $required, $arguments ) ) {
				return $this->denied();
			}
		}

		foreach ( $arguments as $name => $value ) {
			if (
				! array_key_exists( $name, $definition['arguments'] )
				|| ! $this->matches_type( $value, $definition['arguments'][ $name ] )
			) {
				return $this->denied();
			}
		}

		return true;
	}

	private function matches_type( $value, $type ) {
		$checks = array(
			'string'  => 'is_string',
			'integer' => 'is_int',
			'boolean' => 'is_bool',
			'number'  => static function ( $candidate ) {
				return is_int( $candidate ) || is_float( $candidate );
			},
			'array'   => 'is_array',
		);

		return isset( $checks[ $type ] ) && call_user_func( $checks[ $type ], $value );
	}

	private function denied() {
		return new WP_Error(
			'oras_ai_capability_denied',
			__( 'Capability invocation denied.', 'oras-ai-assistant' )
		);
	}
}
