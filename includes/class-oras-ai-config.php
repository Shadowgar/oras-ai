<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Config {

	const OPTION_OPENAI_API_KEY = 'oras_ai_openai_api_key';
	const OPTION_OPENAI_MODEL   = 'oras_ai_openai_model';
	const DEFAULT_OPENAI_MODEL  = 'gpt-5.6-luna';

	public static function allowed_openai_models() {
		return array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol' );
	}

	public static function normalize_openai_model( $model ) {
		return in_array( $model, self::allowed_openai_models(), true )
			? $model
			: self::DEFAULT_OPENAI_MODEL;
	}

	public static function get_openai_model() {
		$model = get_option( self::OPTION_OPENAI_MODEL, self::DEFAULT_OPENAI_MODEL );
		return self::normalize_openai_model( $model );
	}

	public static function update_openai_model( $model ) {
		return update_option(
			self::OPTION_OPENAI_MODEL,
			self::normalize_openai_model( $model ),
			false
		);
	}

	public static function is_openai_api_key_constant_defined() {
		return defined( 'ORAS_AI_OPENAI_API_KEY' );
	}

	public static function get_openai_api_key() {
		if ( self::is_openai_api_key_constant_defined() && ORAS_AI_OPENAI_API_KEY ) {
			return trim( (string) ORAS_AI_OPENAI_API_KEY );
		}

		return trim( (string) get_option( self::OPTION_OPENAI_API_KEY, '' ) );
	}

	public static function has_openai_api_key() {
		return '' !== self::get_openai_api_key();
	}

	public static function update_stored_openai_api_key( $api_key ) {
		if ( self::is_openai_api_key_constant_defined() ) {
			return false;
		}

		return update_option( self::OPTION_OPENAI_API_KEY, trim( (string) $api_key ), false );
	}

	public static function delete_stored_openai_api_key() {
		if ( self::is_openai_api_key_constant_defined() ) {
			return false;
		}

		return delete_option( self::OPTION_OPENAI_API_KEY );
	}
}
