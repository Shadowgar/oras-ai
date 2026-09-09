<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Config {

	const OPTION_OPENAI_API_KEY = 'oras_ai_openai_api_key';
	const OPTION_OPENAI_MODEL   = 'oras_ai_openai_model';
	const OPTION_MEMBER_AI_ENABLED = 'oras_ai_member_ai_enabled';
	const OPTION_ASTRONOMYAPI_APPLICATION_ID     = 'oras_ai_astronomyapi_application_id';
	const OPTION_ASTRONOMYAPI_APPLICATION_SECRET = 'oras_ai_astronomyapi_application_secret';
	const OPTION_NWS_CONTACT                       = 'oras_ai_nws_contact';
	const DEFAULT_OPENAI_MODEL  = 'gpt-5.6-luna';

	public static function member_ai_enabled() {
		return '1' === (string) get_option( self::OPTION_MEMBER_AI_ENABLED, '1' );
	}

	public static function set_member_ai_enabled( $enabled ) {
		return update_option( self::OPTION_MEMBER_AI_ENABLED, $enabled ? '1' : '0', false );
	}

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

	public static function has_stored_openai_api_key() {
		return '' !== trim( (string) get_option( self::OPTION_OPENAI_API_KEY, '' ) );
	}

	public static function stored_openai_api_key_matches( $candidate ) {
		$stored    = trim( (string) get_option( self::OPTION_OPENAI_API_KEY, '' ) );
		$candidate = trim( (string) $candidate );

		return '' !== $stored && $stored === $candidate;
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

	public static function astronomyapi_credentials_from_constants() {
		return defined( 'ORAS_AI_ASTRONOMYAPI_APPLICATION_ID' ) || defined( 'ORAS_AI_ASTRONOMYAPI_APPLICATION_SECRET' );
	}

	public static function get_astronomyapi_application_id() {
		if ( self::astronomyapi_credentials_from_constants() ) {
			return defined( 'ORAS_AI_ASTRONOMYAPI_APPLICATION_ID' )
				? trim( (string) ORAS_AI_ASTRONOMYAPI_APPLICATION_ID )
				: '';
		}

		return trim( (string) get_option( self::OPTION_ASTRONOMYAPI_APPLICATION_ID, '' ) );
	}

	public static function get_astronomyapi_application_secret() {
		if ( self::astronomyapi_credentials_from_constants() ) {
			return defined( 'ORAS_AI_ASTRONOMYAPI_APPLICATION_SECRET' )
				? trim( (string) ORAS_AI_ASTRONOMYAPI_APPLICATION_SECRET )
				: '';
		}

		return trim( (string) get_option( self::OPTION_ASTRONOMYAPI_APPLICATION_SECRET, '' ) );
	}

	public static function has_astronomyapi_credentials() {
		return '' !== self::get_astronomyapi_application_id() && '' !== self::get_astronomyapi_application_secret();
	}

	public static function validate_astronomyapi_credentials( $application_id, $application_secret, $allow_empty = false ) {
		$application_id     = trim( (string) $application_id );
		$application_secret = trim( (string) $application_secret );
		if ( $allow_empty && '' === $application_id && '' === $application_secret ) {
			return array( '', '' );
		}
		if (
			! preg_match( '/^[A-Za-z0-9._-]{1,128}$/', $application_id )
			|| '' === $application_secret
			|| strlen( $application_secret ) > 255
			|| preg_match( '/[\x00-\x1F\x7F]/', $application_secret )
		) {
			return self::invalid_provider_configuration();
		}

		return array( $application_id, $application_secret );
	}

	public static function update_stored_astronomyapi_credentials( $application_id, $application_secret ) {
		if ( self::astronomyapi_credentials_from_constants() ) {
			return false;
		}

		$validated = self::validate_astronomyapi_credentials( $application_id, $application_secret );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		update_option( self::OPTION_ASTRONOMYAPI_APPLICATION_ID, $validated[0], false );
		update_option( self::OPTION_ASTRONOMYAPI_APPLICATION_SECRET, $validated[1], false );
		return true;
	}

	public static function delete_stored_astronomyapi_credentials() {
		if ( self::astronomyapi_credentials_from_constants() ) {
			return false;
		}

		delete_option( self::OPTION_ASTRONOMYAPI_APPLICATION_ID );
		delete_option( self::OPTION_ASTRONOMYAPI_APPLICATION_SECRET );
		return true;
	}

	public static function normalize_nws_contact( $contact ) {
		$contact = trim( (string) $contact );
		if ( '' === $contact ) {
			return '';
		}
		if ( strlen( $contact ) > 200 || preg_match( '/[\x00-\x1F\x7F]/', $contact ) ) {
			return null;
		}
		if ( false !== filter_var( $contact, FILTER_VALIDATE_EMAIL ) ) {
			return $contact;
		}
		if ( false !== filter_var( $contact, FILTER_VALIDATE_URL ) && 'https' === strtolower( (string) wp_parse_url( $contact, PHP_URL_SCHEME ) ) ) {
			return $contact;
		}

		return null;
	}

	public static function update_nws_contact( $contact ) {
		$contact = self::normalize_nws_contact( $contact );
		if ( null === $contact ) {
			return self::invalid_provider_configuration();
		}
		if ( '' === $contact ) {
			delete_option( self::OPTION_NWS_CONTACT );
			return true;
		}

		return update_option( self::OPTION_NWS_CONTACT, $contact, false );
	}

	public static function get_nws_contact() {
		$contact = self::normalize_nws_contact( get_option( self::OPTION_NWS_CONTACT, '' ) );
		return is_string( $contact ) ? $contact : '';
	}

	public static function get_nws_user_agent() {
		$contact = self::get_nws_contact();
		return '' === $contact ? '' : sprintf( 'ORAS AI Assistant/%s (%s)', ORAS_AI_VERSION, $contact );
	}

	private static function invalid_provider_configuration() {
		return new WP_Error(
			'oras_ai_invalid_provider_configuration',
			__( 'Invalid astronomy or weather provider configuration.', 'oras-ai-assistant' )
		);
	}
}
