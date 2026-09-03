<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central, server-side limits and local provider pricing for paid member work.
 */
final class ORAS_AI_Cost_Config {

	const OPTION = 'oras_ai_cost_controls';

	const MAX_DAILY_QUOTA              = 1000;
	const MAX_MONTHLY_QUOTA            = 10000;
	const MAX_BURST_PER_MINUTE         = 60;
	const MAX_INPUT_CHARACTERS         = 20000;
	const MAX_OUTPUT_TOKENS            = 16000;
	const MAX_EXECUTION_TIMEOUT_SECONDS = 120;
	const MAX_THRESHOLD_MICRODOLLARS   = 10000000000;
	const MAX_RATE_MICRODOLLARS        = 100000000000;

	public static function defaults() {
		return array(
			'daily_quota'              => 25,
			'monthly_quota'            => 150,
			'burst_per_minute'         => 5,
			'max_input_characters'     => 4000,
			'max_output_tokens'        => 800,
			'execution_timeout_seconds' => 30,
			'warning_microdollars'     => 10000000,
			'hard_stop_microdollars'   => 20000000,
			'pricing'                   => array(),
		);
	}

	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$validated = self::validate( array_replace( self::defaults(), $stored ) );
		return is_wp_error( $validated ) ? self::defaults() : $validated;
	}

	public static function update( array $configuration ) {
		$validated = self::validate( $configuration );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return update_option( self::OPTION, $validated, false );
	}

	public static function validate( array $configuration ) {
		$integer_limits = array(
			'daily_quota'               => array( 1, self::MAX_DAILY_QUOTA ),
			'monthly_quota'             => array( 1, self::MAX_MONTHLY_QUOTA ),
			'burst_per_minute'          => array( 1, self::MAX_BURST_PER_MINUTE ),
			'max_input_characters'      => array( 100, self::MAX_INPUT_CHARACTERS ),
			'max_output_tokens'         => array( 1, self::MAX_OUTPUT_TOKENS ),
			'execution_timeout_seconds' => array( 1, self::MAX_EXECUTION_TIMEOUT_SECONDS ),
			'warning_microdollars'      => array( 1, self::MAX_THRESHOLD_MICRODOLLARS ),
			'hard_stop_microdollars'    => array( 1, self::MAX_THRESHOLD_MICRODOLLARS ),
		);

		$validated = array();
		foreach ( $integer_limits as $key => $bounds ) {
			if ( ! isset( $configuration[ $key ] ) || ! is_int( $configuration[ $key ] ) ) {
				return self::invalid();
			}

			$value = $configuration[ $key ];
			if ( $value < $bounds[0] || $value > $bounds[1] ) {
				return self::invalid();
			}
			$validated[ $key ] = $value;
		}

		if (
			$validated['monthly_quota'] < $validated['daily_quota']
			|| $validated['warning_microdollars'] >= $validated['hard_stop_microdollars']
		) {
			return self::invalid();
		}

		$pricing = isset( $configuration['pricing'] ) && is_array( $configuration['pricing'] )
			? $configuration['pricing']
			: array();
		$validated['pricing'] = array();

		foreach ( $pricing as $model => $rates ) {
			if ( ! in_array( $model, ORAS_AI_Config::allowed_openai_models(), true ) || ! is_array( $rates ) ) {
				return self::invalid();
			}

			$input  = $rates['input_microdollars_per_million_tokens'] ?? null;
			$output = $rates['output_microdollars_per_million_tokens'] ?? null;
			$unit   = $rates['unit'] ?? '';
			if (
				! is_int( $input ) || ! is_int( $output )
				|| $input <= 0 || $output <= 0
				|| $input > self::MAX_RATE_MICRODOLLARS || $output > self::MAX_RATE_MICRODOLLARS
				|| 'per_million_tokens' !== $unit
			) {
				return self::invalid();
			}

			$validated['pricing'][ $model ] = array(
				'input_microdollars_per_million_tokens'  => $input,
				'output_microdollars_per_million_tokens' => $output,
				'unit'                                    => 'per_million_tokens',
			);
		}

		return $validated;
	}

	public static function from_admin_request( array $request ) {
		$integer_fields = array(
			'daily_quota',
			'monthly_quota',
			'burst_per_minute',
			'max_input_characters',
			'max_output_tokens',
			'execution_timeout_seconds',
		);
		$configuration = array();

		foreach ( $integer_fields as $field ) {
			$value = isset( $request[ $field ] ) ? wp_unslash( $request[ $field ] ) : '';
			if ( ! is_string( $value ) || ! preg_match( '/^[0-9]+$/', $value ) ) {
				return self::invalid();
			}
			$configuration[ $field ] = (int) $value;
		}

		$warning = self::parse_usd( isset( $request['warning_usd'] ) ? wp_unslash( $request['warning_usd'] ) : '' );
		$hard    = self::parse_usd( isset( $request['hard_stop_usd'] ) ? wp_unslash( $request['hard_stop_usd'] ) : '' );
		if ( null === $warning || null === $hard ) {
			return self::invalid();
		}
		$configuration['warning_microdollars']   = $warning;
		$configuration['hard_stop_microdollars'] = $hard;
		$configuration['pricing']                 = array();

		$submitted_pricing = isset( $request['pricing'] ) && is_array( $request['pricing'] )
			? wp_unslash( $request['pricing'] )
			: array();
		foreach ( array_keys( $submitted_pricing ) as $submitted_model ) {
			if ( ! in_array( $submitted_model, ORAS_AI_Config::allowed_openai_models(), true ) ) {
				return self::invalid();
			}
		}
		foreach ( ORAS_AI_Config::allowed_openai_models() as $model ) {
			$rates      = isset( $submitted_pricing[ $model ] ) && is_array( $submitted_pricing[ $model ] )
				? $submitted_pricing[ $model ]
				: array();
			$input_raw  = isset( $rates['input_usd_per_million_tokens'] ) ? trim( (string) $rates['input_usd_per_million_tokens'] ) : '';
			$output_raw = isset( $rates['output_usd_per_million_tokens'] ) ? trim( (string) $rates['output_usd_per_million_tokens'] ) : '';

			if ( '' === $input_raw && '' === $output_raw ) {
				continue;
			}

			$input  = self::parse_usd( $input_raw );
			$output = self::parse_usd( $output_raw );
			if ( null === $input || null === $output ) {
				return self::invalid();
			}

			$configuration['pricing'][ $model ] = array(
				'input_microdollars_per_million_tokens'  => $input,
				'output_microdollars_per_million_tokens' => $output,
				'unit'                                    => 'per_million_tokens',
			);
		}

		return self::validate( $configuration );
	}

	public static function format_usd( $microdollars ) {
		return number_format( max( 0, (int) $microdollars ) / 1000000, 6, '.', '' );
	}

	private static function parse_usd( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^[0-9]+(?:\.[0-9]{1,6})?$/', $value ) ) {
			return null;
		}

		list( $whole, $fraction ) = array_pad( explode( '.', $value, 2 ), 2, '' );
		$microdollars = ( (int) $whole * 1000000 ) + (int) str_pad( $fraction, 6, '0' );
		return $microdollars > 0 ? $microdollars : null;
	}

	private static function invalid() {
		return new WP_Error(
			'oras_ai_invalid_cost_configuration',
			__( 'Invalid usage and cost configuration.', 'oras-ai-assistant' )
		);
	}
}
