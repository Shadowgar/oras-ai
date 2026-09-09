<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only least-privilege adapter for the authorized user's current PMPro state.
 */
final class ORAS_AI_PMPro_Context_Connector implements ORAS_AI_Observable_Live_Connector_Interface {

	const CONNECTOR = 'pmpro';
	const SUBJECT   = 'self';

	private $levels_loader;
	private $now_provider;

	public function __construct( $levels_loader = null, $now_provider = null ) {
		$this->levels_loader = is_callable( $levels_loader ) ? $levels_loader : null;
		$this->now_provider  = is_callable( $now_provider ) ? $now_provider : null;
	}

	public function supports( ORAS_AI_Live_Request $request ) {
		return null !== $this->route( $request );
	}

	public function connector_id() {
		return self::CONNECTOR;
	}

	public function is_available() {
		return null !== $this->levels_loader || function_exists( 'pmpro_getMembershipLevelsForUser' );
	}

	public function required_fact_keys( ORAS_AI_Live_Request $request ) {
		$route = $this->route( $request );
		return is_array( $route ) && empty( $route['reason'] ) ? $route['fact_keys'] : array();
	}

	public function fetch( ORAS_AI_Live_Request $request ) {
		$route = $this->route( $request );
		if ( null === $route ) {
			return ORAS_AI_Live_Result::unknown( 'membership_query_not_supported' );
		}
		if ( ! empty( $route['reason'] ) ) {
			return 'non_self_membership_request' === $route['reason']
				? ORAS_AI_Live_Result::denied( $route['reason'] )
				: ORAS_AI_Live_Result::unknown( $route['reason'] );
		}
		if ( ! in_array( 'members', $request->allowed_visibilities(), true ) ) {
			return ORAS_AI_Live_Result::denied( 'membership_visibility_denied' );
		}
		if ( 0 === $request->user_id() ) {
			return ORAS_AI_Live_Result::denied( 'membership_identity_invalid' );
		}

		$routed_request = $request->with_route( self::CONNECTOR, self::SUBJECT, $route['fact_keys'] );
		try {
			$lookup = null === $this->levels_loader
				? array( $this, 'load_from_pmpro' )
				: $this->levels_loader;
			$levels = $routed_request->is_administrator()
				? $this->without_admin_virtual_access( $lookup, $routed_request->user_id() )
				: call_user_func( $lookup, $routed_request->user_id() );
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Live_Result::unknown( 'membership_lookup_failed' );
		}

		if ( is_wp_error( $levels ) ) {
			return 'oras_ai_pmpro_unavailable' === $levels->get_error_code()
				? ORAS_AI_Live_Result::unavailable( 'pmpro_connector_unavailable' )
				: ORAS_AI_Live_Result::unknown( 'membership_lookup_failed' );
		}
		if ( ! is_array( $levels ) ) {
			return ORAS_AI_Live_Result::unknown( 'membership_data_malformed' );
		}

		$level_names = array();
		foreach ( $levels as $level ) {
			if ( is_object( $level ) ) {
				$name = $level->name ?? '';
			} elseif ( is_array( $level ) ) {
				$name = $level['name'] ?? '';
			} else {
				return ORAS_AI_Live_Result::unknown( 'membership_data_malformed' );
			}

			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				return ORAS_AI_Live_Result::unknown( 'membership_data_malformed' );
			}
			$level_names[] = $name;
		}

		if (
			count( $level_names ) > 1
			&& in_array( 'member:self:membership-level', $routed_request->fact_keys(), true )
		) {
			return ORAS_AI_Live_Result::unknown( 'membership_level_ambiguous' );
		}

		$facts = array();
		foreach ( $routed_request->fact_keys() as $fact_key ) {
			$text = $this->fact_text( $fact_key, $level_names );
			$fact = ORAS_AI_Live_Fact::from_array(
				array(
					'fact_key'            => $fact_key,
					'source_title'        => 'Current ORAS membership',
					'source_wp_object_id' => 0,
					'source_type'         => 'pmpro_membership',
					'canonical_url'       => '',
					'relevant_text'       => $text,
					'comparison_value'    => $this->comparison_value( $fact_key, $level_names ),
					'visibility'          => 'members',
					'source_modified_gmt' => '',
					'retrieved_at'        => $this->now_value(),
				)
			);
			if ( is_wp_error( $fact ) ) {
				return ORAS_AI_Live_Result::unknown( 'membership_data_malformed' );
			}
			$facts[] = $fact;
		}

		return ORAS_AI_Live_Result::success( $facts );
	}

	private function comparison_value( $fact_key, array $level_names ) {
		if ( 'member:self:membership-status' === $fact_key ) {
			return empty( $level_names ) ? 'inactive' : 'active';
		}
		if ( 'member:self:membership-level' === $fact_key ) {
			return empty( $level_names ) ? 'none' : $level_names[0];
		}

		return '';
	}

	private function route( ORAS_AI_Live_Request $request ) {
		$question = strtolower( trim( wp_strip_all_tags( $request->question(), true ) ) );
		$email_level_request = (bool) preg_match( '/\b(level|tier)\b/', $question )
			&& (bool) preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $question );
		if ( ! preg_match( '/\b(member|membership|membership level|membership tier)\b/', $question ) && ! $email_level_request ) {
			return null;
		}

		if ( $this->is_non_self_request( $question ) ) {
			return array(
				'fact_keys' => array(),
				'reason'    => 'non_self_membership_request',
			);
		}

		$self = (bool) preg_match( '/\b(my|mine|me|am i|do i|have i|i have)\b/', $question );
		if ( ! $self ) {
			return null;
		}
		if ( ORAS_AI_Retrieval_Request::INTENT_HISTORICAL === $request->intent() ) {
			return array(
				'fact_keys' => array(),
				'reason'    => 'historical_membership_not_supported',
			);
		}

		$fact_keys = array();
		if ( preg_match( '/\b(status|active|inactive|am i|do i)\b/', $question ) ) {
			$fact_keys[] = 'member:self:membership-status';
		}
		if ( preg_match( '/\b(level|tier)\b/', $question ) ) {
			$fact_keys[] = 'member:self:membership-level';
		}
		if ( empty( $fact_keys ) ) {
			return null;
		}

		return array(
			'fact_keys' => ORAS_AI_Live_Request::normalize_fact_keys( $fact_keys ),
			'reason'    => '',
		);
	}

	private function is_non_self_request( $question ) {
		return (bool) preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $question )
			|| (bool) preg_match( '/\b(?:user|user_id|userid|member_id|membership_id)\s*(?:=|#|:)?\s*[0-9]+\b/', $question )
			|| (bool) preg_match( '/\b(?:another|other|someone else|somebody else|their)\b/', $question )
			|| (bool) preg_match( '/\bwhat\s+(?:membership\s+)?(?:level|tier)\s+does\s+(?!i\b|my\b)[a-z][a-z\' -]{0,60}\s+have\b/', $question )
			|| (bool) preg_match( '/\bis\s+(?!i\b|me\b|my\b)[a-z][a-z\' -]{0,60}\s+(?:an?\s+)?(?:active\s+)?member\b/', $question );
	}

	private function fact_text( $fact_key, array $level_names ) {
		if ( 'member:self:membership-status' === $fact_key ) {
			return sprintf( 'Current membership status: %s.', empty( $level_names ) ? 'inactive' : 'active' );
		}
		if ( 'member:self:membership-level' === $fact_key ) {
			return empty( $level_names )
				? 'There is no current active membership level.'
				: sprintf( 'Current membership level: %s.', $level_names[0] );
		}

		return '';
	}

	private function now_value() {
		return null === $this->now_provider ? current_time( 'mysql' ) : call_user_func( $this->now_provider );
	}

	private function without_admin_virtual_access( $lookup, $user_id ) {
		$disable_admin_virtual_access = static function () {
			return true;
		};
		$priority = PHP_INT_MAX;
		add_filter( 'pmpro_disable_admin_membership_access', $disable_admin_virtual_access, $priority );
		try {
			return call_user_func( $lookup, $user_id );
		} finally {
			remove_filter( 'pmpro_disable_admin_membership_access', $disable_admin_virtual_access, $priority );
		}
	}

	private function load_from_pmpro( $user_id ) {
		if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
			return new WP_Error( 'oras_ai_pmpro_unavailable', __( 'Paid Memberships Pro is unavailable.', 'oras-ai-assistant' ) );
		}

		$levels = pmpro_getMembershipLevelsForUser( absint( $user_id ), false );

		return false === $levels
			? new WP_Error( 'oras_ai_pmpro_lookup_failed', __( 'Current membership could not be established.', 'oras-ai-assistant' ) )
			: $levels;
	}
}
