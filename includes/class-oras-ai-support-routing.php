<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Protected WordPress configuration; Fluent Support access is read-only. */
final class ORAS_AI_Support_Routing {
	const OPTION_ROUTING = 'oras_ai_support_routing';
	const MAX_TAGS = 8;
	private $adapter;

	public function __construct( $adapter = null ) {
		$this->adapter = $adapter ?: new ORAS_AI_Fluent_Support_Adapter();
	}

	public function configuration() {
		$stored = get_option( self::OPTION_ROUTING, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Validate saved format without inspecting provider mailboxes/tags or exposing IDs. */
	public function local_configuration_status() {
		$stored = $this->configuration();
		if ( ! $stored ) {
			return array( 'general' => 'missing', 'topics' => 'missing' );
		}
		$general = $stored;
		$general['topic_routes'] = array();
		if ( null === $this->normalize_config( $general ) ) {
			return array( 'general' => 'invalid', 'topics' => 'invalid' );
		}
		$normalized = $this->normalize_config( $stored );
		return array(
			'general' => 'configured',
			'topics' => null === $normalized ? 'invalid_general_fallback' : ( empty( $normalized['topic_routes'] ) ? 'general_fallback' : 'configured' ),
		);
	}

	public function save( array $input ) {
		$config = $this->normalize_config( $input );
		if ( null === $config || 'available' !== $this->adapter->status()->status() ) {
			return $this->invalid();
		}
		if ( 'route_valid' !== $this->adapter->validate_provider_route( $config['primary_mailbox_id'], $config['general_tag_ids'] )->status() ) {
			return $this->invalid();
		}
		foreach ( $config['topic_routes'] as $route ) {
			if ( 'route_valid' !== $this->adapter->validate_provider_route( $route['mailbox_id'], $route['tag_ids'] )->status() ) {
				return $this->invalid();
			}
		}
		if ( $config === $this->configuration() ) {
			return true;
		}
		if ( ! update_option( self::OPTION_ROUTING, $config, false ) ) {
			return $this->invalid();
		}
		ORAS_AI_Audit_Log::log_m7_routing_changed();
		return true;
	}

	public function route_for( $topic ) {
		$unavailable = array( 'status' => 'routing_unavailable', 'topic' => self::general(), 'route' => null, 'destination' => '', 'fallback' => false );
		$stored = $this->configuration();
		// A stale topic entry must not invalidate an otherwise sound General route.
		$config = $this->normalize_config( array(
			'primary_mailbox_id' => $stored['primary_mailbox_id'] ?? null,
			'destination_name'   => $stored['destination_name'] ?? '',
			'general_tag_ids'    => $stored['general_tag_ids'] ?? array(),
			'topic_routes'       => array(),
		) );
		if ( null === $config || 'available' !== $this->adapter->status()->status() ) {
			return $unavailable;
		}
		$general = $this->adapter->validate_provider_route( $config['primary_mailbox_id'], $config['general_tag_ids'] );
		if ( 'route_valid' !== $general->status() ) {
			return $unavailable;
		}
		$topic = ORAS_AI_Support_Topic::normalize( $topic );
		$fallback = array( 'status' => 'route_valid', 'topic' => self::general(), 'route' => $general->route(), 'destination' => $config['destination_name'], 'fallback' => true );
		if ( self::general() === $topic || ! isset( $stored['topic_routes'][ $topic ] ) ) {
			return $fallback;
		}
		$mapped = $this->normalize_topic_route( $stored['topic_routes'][ $topic ], $config['primary_mailbox_id'] );
		if ( null === $mapped ) {
			return $fallback;
		}
		$result = $this->adapter->validate_provider_route( $mapped['mailbox_id'], $mapped['tag_ids'] );
		if ( 'route_valid' !== $result->status() ) {
			return $fallback;
		}
		return array( 'status' => 'route_valid', 'topic' => $topic, 'route' => $result->route(), 'destination' => $config['destination_name'], 'fallback' => false );
	}

	private static function general() {
		return ORAS_AI_Support_Topic::GENERAL;
	}

	private function normalize_config( array $input ) {
		$primary = $this->positive_id( $input['primary_mailbox_id'] ?? null );
		$name = isset( $input['destination_name'] ) && is_string( $input['destination_name'] ) ? sanitize_text_field( $input['destination_name'] ) : '';
		$name = trim( $name );
		$general_tags = $this->tag_ids( $input['general_tag_ids'] ?? array() );
		$routes = $input['topic_routes'] ?? array();
		if ( null === $primary || '' === $name || strlen( $name ) > 80 || null === $general_tags || ! is_array( $routes ) ) {
			return null;
		}
		$normalized = array();
		foreach ( $routes as $topic => $route ) {
			if ( ! ORAS_AI_Support_Topic::allowed( $topic ) || self::general() === $topic || ! is_array( $route ) ) {
				return null;
			}
			$blank_mailbox = ! isset( $route['mailbox_id'] ) || '' === $route['mailbox_id'];
			$tag_value = $route['tag_ids'] ?? array();
			$blank_tags = array() === $tag_value || ( is_string( $tag_value ) && '' === trim( $tag_value ) );
			if ( $blank_mailbox && $blank_tags ) {
				continue;
			}
			$mapped = $this->normalize_topic_route( $route, $primary );
			if ( null === $mapped ) {
				return null;
			}
			$normalized[ $topic ] = $mapped;
		}
		return array( 'primary_mailbox_id' => $primary, 'destination_name' => $name, 'general_tag_ids' => $general_tags, 'topic_routes' => $normalized );
	}

	private function normalize_topic_route( $route, $primary ) {
		if ( ! is_array( $route ) ) {
			return null;
		}
		$mailbox_value = $route['mailbox_id'] ?? $primary;
		$mailbox = $this->positive_id( '' === $mailbox_value ? $primary : $mailbox_value );
		$tags = $this->tag_ids( $route['tag_ids'] ?? array() );
		return null === $mailbox || null === $tags ? null : array( 'mailbox_id' => $mailbox, 'tag_ids' => $tags );
	}

	private function positive_id( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$value = (string) $value;
		return preg_match( '/^[1-9][0-9]*$/', $value ) && (string) (int) $value === $value ? (int) $value : null;
	}

	private function tag_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : explode( ',', $value );
		}
		if ( ! is_array( $value ) || count( $value ) > self::MAX_TAGS ) {
			return null;
		}
		$ids = array();
		foreach ( $value as $id ) {
			$id = is_string( $id ) ? trim( $id ) : $id;
			$id = $this->positive_id( $id );
			if ( null === $id ) {
				return null;
			}
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}

	private function invalid() {
		return new WP_Error( 'oras_ai_invalid_support_routing', __( 'Support routing settings are invalid.', 'oras-ai-assistant' ) );
	}
}
