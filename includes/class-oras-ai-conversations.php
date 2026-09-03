<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private WordPress-native member conversation storage with bounded text retention.
 */
final class ORAS_AI_Conversations {

	const CONVERSATION_POST_TYPE = 'oras_ai_conversation';
	const MESSAGE_POST_TYPE      = 'oras_ai_message';
	const CLEANUP_HOOK           = 'oras_ai_prune_conversation_text';
	const RETENTION_DAYS         = 30;
	const RETENTION_LABEL        = '30 days';
	const RETENTION_CLASS        = 'conversation_text_30_days';

	const META_STATUS     = '_oras_ai_conversation_status';
	const META_RETENTION  = '_oras_ai_retention_class';
	const META_CREATED_AT = '_oras_ai_created_at_utc';
	const META_UPDATED_AT = '_oras_ai_updated_at_utc';
	const META_ROLE       = '_oras_ai_message_role';

	private $sensitive_input_guard;
	private $clock;

	public function __construct( ?ORAS_AI_Sensitive_Input_Guard $sensitive_input_guard = null, $clock = null ) {
		$this->sensitive_input_guard = $sensitive_input_guard ?: new ORAS_AI_Sensitive_Input_Guard();
		$this->clock                 = is_callable( $clock ) ? $clock : 'time';

		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'prune_expired' ) );
	}

	public function register_post_types() {
		$args = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'query_var'           => false,
			'rewrite'             => false,
			'supports'            => array(),
		);

		register_post_type( self::CONVERSATION_POST_TYPE, $args );
		register_post_type( self::MESSAGE_POST_TYPE, $args );
	}

	public function schedule_cleanup() {
		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( $this->now() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public function create_conversation( array $client_claims = array() ) {
		unset( $client_claims );
		$this->prune_expired();
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->denied();
		}

		$now             = $this->now();
		$conversation_id = wp_insert_post(
			array(
				'post_type'         => self::CONVERSATION_POST_TYPE,
				'post_status'       => 'private',
				'post_author'       => $user_id,
				'post_title'        => '',
				'post_content'      => '',
				'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $now ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			true
		);

		if ( is_wp_error( $conversation_id ) || (int) $conversation_id <= 0 ) {
			return $this->storage_error();
		}

		$conversation_id = (int) $conversation_id;
		update_post_meta( $conversation_id, self::META_STATUS, 'active' );
		update_post_meta( $conversation_id, self::META_RETENTION, self::RETENTION_CLASS );
		update_post_meta( $conversation_id, self::META_CREATED_AT, $now );
		update_post_meta( $conversation_id, self::META_UPDATED_AT, $now );

		return $conversation_id;
	}

	public function get_conversation( $conversation_id ) {
		$this->prune_expired();
		$conversation = $this->owned_conversation( $conversation_id );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		return array(
			'id'              => (int) $conversation->ID,
			'user_id'         => (int) $conversation->post_author,
			'status'          => (string) get_post_meta( $conversation->ID, self::META_STATUS, true ),
			'retention_class' => (string) get_post_meta( $conversation->ID, self::META_RETENTION, true ),
			'created_at_utc'  => (int) get_post_meta( $conversation->ID, self::META_CREATED_AT, true ),
			'updated_at_utc'  => (int) get_post_meta( $conversation->ID, self::META_UPDATED_AT, true ),
		);
	}

	public function append_message( $conversation_id, $role, $content ) {
		$this->prune_expired();
		$conversation = $this->owned_conversation( $conversation_id );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		if ( ! is_string( $role ) || ! in_array( $role, array( 'member', 'assistant' ), true ) || ! is_string( $content ) ) {
			return $this->invalid_message();
		}

		$content = sanitize_textarea_field( $content );
		if ( '' === $content ) {
			return $this->invalid_message();
		}

		if ( 'member' === $role ) {
			$safe = $this->sensitive_input_guard->validate( $content );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
		}

		$now        = $this->now();
		$message_id = wp_insert_post(
			array(
				'post_type'         => self::MESSAGE_POST_TYPE,
				'post_status'       => 'private',
				'post_author'       => (int) $conversation->post_author,
				'post_parent'       => (int) $conversation->ID,
				'post_title'        => '',
				'post_content'      => $content,
				'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $now ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			true
		);

		if ( is_wp_error( $message_id ) || (int) $message_id <= 0 ) {
			return $this->storage_error();
		}

		$message_id = (int) $message_id;
		update_post_meta( $message_id, self::META_ROLE, $role );
		update_post_meta( $message_id, self::META_CREATED_AT, $now );
		update_post_meta( $conversation->ID, self::META_UPDATED_AT, $now );
		wp_update_post(
			array(
				'ID'                => (int) $conversation->ID,
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			true
		);

		return $message_id;
	}

	public function get_messages( $conversation_id ) {
		$this->prune_expired();
		$conversation = $this->owned_conversation( $conversation_id );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::MESSAGE_POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => (int) $conversation->ID,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$messages = array();

		foreach ( $posts as $post ) {
			$role = (string) get_post_meta( $post->ID, self::META_ROLE, true );
			if ( (int) $post->post_author !== (int) $conversation->post_author || ! in_array( $role, array( 'member', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array(
				'id'              => (int) $post->ID,
				'conversation_id' => (int) $conversation->ID,
				'role'            => $role,
				'content'         => (string) $post->post_content,
				'created_at_utc'  => (int) get_post_meta( $post->ID, self::META_CREATED_AT, true ),
			);
		}

		return $messages;
	}

	public function prune_expired() {
		$cutoff                = $this->now() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$deleted_messages      = 0;
		$deleted_conversations = 0;
		$message_ids           = get_posts(
			array(
				'post_type'      => self::MESSAGE_POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $message_ids as $message_id ) {
			$created_at = (int) get_post_meta( $message_id, self::META_CREATED_AT, true );
			if ( $created_at <= 0 || $created_at <= $cutoff ) {
				if ( wp_delete_post( $message_id, true ) ) {
					$deleted_messages++;
				}
			}
		}

		$conversation_ids = get_posts(
			array(
				'post_type'      => self::CONVERSATION_POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $conversation_ids as $conversation_id ) {
			$messages = get_posts(
				array(
					'post_type'      => self::MESSAGE_POST_TYPE,
					'post_status'    => 'private',
					'post_parent'    => (int) $conversation_id,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$updated_at = (int) get_post_meta( $conversation_id, self::META_UPDATED_AT, true );
			if ( empty( $messages ) && ( $updated_at <= 0 || $updated_at <= $cutoff ) ) {
				if ( wp_delete_post( $conversation_id, true ) ) {
					$deleted_conversations++;
				}
			}
		}

		return array(
			'messages'      => $deleted_messages,
			'conversations' => $deleted_conversations,
		);
	}

	private function owned_conversation( $conversation_id ) {
		$conversation_id = absint( $conversation_id );
		$user_id          = get_current_user_id();
		$conversation     = $conversation_id > 0 ? get_post( $conversation_id ) : null;

		if (
			$user_id <= 0
			|| ! $conversation
			|| self::CONVERSATION_POST_TYPE !== $conversation->post_type
			|| 'private' !== $conversation->post_status
			|| $user_id !== (int) $conversation->post_author
		) {
			return $this->denied();
		}

		return $conversation;
	}

	private function now() {
		return (int) call_user_func( $this->clock );
	}

	private function denied() {
		return new WP_Error(
			'oras_ai_conversation_denied',
			__( 'Conversation unavailable.', 'oras-ai-assistant' ),
			array( 'status' => 403 )
		);
	}

	private function invalid_message() {
		return new WP_Error(
			'oras_ai_invalid_message',
			__( 'Invalid message.', 'oras-ai-assistant' ),
			array( 'status' => 400 )
		);
	}

	private function storage_error() {
		return new WP_Error(
			'oras_ai_conversation_storage_failed',
			__( 'Conversation could not be saved.', 'oras-ai-assistant' ),
			array( 'status' => 500 )
		);
	}
}
