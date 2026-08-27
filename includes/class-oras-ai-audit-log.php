<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Audit_Log {

	const OPTION_EVENTS = 'oras_ai_config_audit_events';
	const MAX_EVENTS    = 100;
	const RECENT_EVENTS = 20;

	const CONFIG_OPENAI_MODEL     = 'config.openai_model';
	const CONFIG_MEMBER_AI        = 'config.member_ai_enabled';
	const CONFIG_OPENAI_API_KEY   = 'config.openai_api_key';

	public static function log_openai_model_changed( $old_model, $new_model ) {
		return self::record(
			self::CONFIG_OPENAI_MODEL,
			'changed',
			sanitize_text_field( (string) $old_model ),
			sanitize_text_field( (string) $new_model )
		);
	}

	public static function log_member_ai_changed( $old_enabled, $new_enabled ) {
		return self::record(
			self::CONFIG_MEMBER_AI,
			$new_enabled ? 'enabled' : 'disabled',
			$old_enabled ? 'enabled' : 'disabled',
			$new_enabled ? 'enabled' : 'disabled'
		);
	}

	public static function log_openai_api_key_changed( $action ) {
		$allowed_actions = array( 'set', 'replaced', 'removed' );
		$action          = sanitize_key( $action );

		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return false;
		}

		return self::record( self::CONFIG_OPENAI_API_KEY, $action, null, null );
	}

	public static function recent_events( $limit = self::RECENT_EVENTS ) {
		$events = get_option( self::OPTION_EVENTS, array() );

		if ( ! is_array( $events ) ) {
			return array();
		}

		return array_slice( $events, 0, absint( $limit ) );
	}

	private static function record( $config_item, $action, $old_state, $new_state ) {
		$event = array(
			'timestamp'     => current_time( 'mysql' ),
			'actor_user_id' => get_current_user_id(),
			'config_item'   => $config_item,
			'action'        => $action,
			'outcome'       => 'success',
			'old_state'     => $old_state,
			'new_state'     => $new_state,
		);

		$events = get_option( self::OPTION_EVENTS, array() );
		$events = is_array( $events ) ? $events : array();
		array_unshift( $events, $event );

		return update_option(
			self::OPTION_EVENTS,
			array_slice( $events, 0, self::MAX_EVENTS ),
			false
		);
	}
}
