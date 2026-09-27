<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Private proposal storage. The option-name claim is unique at the database layer. */
final class ORAS_AI_Pending_Escalations {
	const POST_TYPE = 'oras_ai_escalation';
	const META_RECORD = '_oras_ai_escalation_record';
	const META_TOKEN_HASH = '_oras_ai_escalation_token_hash';
	const CLEANUP_HOOK = 'oras_ai_prune_escalations';
	const PROPOSAL_TTL_SECONDS = 3600;
	const STALE_CREATING_SECONDS = 120;
	const RETENTION_SECONDS = 30 * DAY_IN_SECONDS;

	private $clock;

	public function __construct( $clock = null ) {
		$this->clock = is_callable( $clock ) ? $clock : 'time';
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'prune_expired' ) );
	}

	public function now() { return (int) call_user_func( $this->clock ); }

	public function register_post_type() {
		register_post_type( self::POST_TYPE, array(
			'public' => false, 'publicly_queryable' => false, 'show_ui' => false,
			'show_in_rest' => false, 'exclude_from_search' => true,
			'query_var' => false, 'rewrite' => false, 'supports' => array(),
		) );
	}

	public function schedule_cleanup() {
		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( $this->now() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() { wp_clear_scheduled_hook( self::CLEANUP_HOOK ); }

	public function create( ORAS_AI_Escalation_Proposal $proposal ) {
		if ( $proposal->owner_user_id() <= 0 || $proposal->owner_user_id() !== get_current_user_id() || $proposal->conversation_id() <= 0 ) {
			return $this->denied();
		}
		$preview = $proposal->to_member_array();
		$route = $proposal->provider_route();
		$token = bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $token );
		$now = $this->now();
		$record = array(
			'user_id' => $proposal->owner_user_id(), 'conversation_id' => $proposal->conversation_id(),
			'token_hash' => $hash, 'topic' => $proposal->topic(),
			'subject' => $preview['subject'], 'summary' => $preview['summary'],
			'original_question' => $preview['original_question'], 'destination' => $preview['destination'],
			'mailbox_id' => $route->mailbox_id(), 'tag_ids' => $route->tag_ids(),
			'state' => 'awaiting_confirmation', 'created_at' => $now,
			'expires_at' => $now + self::PROPOSAL_TTL_SECONDS,
			'attempt_at' => 0, 'ticket_id' => null, 'reason' => '',
		);
		$id = wp_insert_post( array(
			'post_type' => self::POST_TYPE, 'post_status' => 'private',
			'post_author' => $proposal->owner_user_id(), 'post_title' => '', 'post_content' => '',
		), true );
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			return $this->storage_error();
		}
		if ( ! update_post_meta( $id, self::META_TOKEN_HASH, $hash ) || ! update_post_meta( $id, self::META_RECORD, $record ) ) {
			wp_delete_post( $id, true );
			return $this->storage_error();
		}
		return $this->member_result( $record, $token );
	}

	public function load( $token, $conversation_id ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ||
			( null !== $conversation_id && ( ! is_int( $conversation_id ) || $conversation_id <= 0 ) ) || get_current_user_id() <= 0 ) {
			return $this->denied();
		}
		$hash = hash( 'sha256', $token );
		$posts = get_posts( array(
			'post_type' => self::POST_TYPE, 'post_status' => 'private',
			'meta_key' => self::META_TOKEN_HASH, 'meta_value' => $hash,
			'posts_per_page' => 2,
		) );
		if ( ! $posts ) {
			foreach ( $this->owner_posts() as $candidate ) {
				$candidate_hash = get_post_meta( $candidate->ID, self::META_TOKEN_HASH, true );
				if ( is_string( $candidate_hash ) && hash_equals( $this->restore_token( $candidate->ID, $candidate_hash ), $token ) ) {
					$posts[] = $candidate;
				}
			}
		}
		if ( count( $posts ) !== 1 ) {
			return $this->denied();
		}
		$record = get_post_meta( $posts[0]->ID, self::META_RECORD, true );
		if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['token_hash'] ?? '' ), (string) get_post_meta( $posts[0]->ID, self::META_TOKEN_HASH, true ) ) ||
			(int) ( $record['user_id'] ?? 0 ) !== get_current_user_id() ||
			(int) $posts[0]->post_author !== get_current_user_id() ||
			( null !== $conversation_id && (int) ( $record['conversation_id'] ?? 0 ) !== $conversation_id ) ) {
			return $this->denied();
		}
		$record['_post_id'] = (int) $posts[0]->ID;
		$record['_original'] = get_post_meta( $posts[0]->ID, self::META_RECORD, true );
		return $this->reconcile( $record );
	}

	/** Rebuild an opaque status/confirmation reference for this owner's conversation. */
	public function for_conversation( $conversation_id ) {
		if ( ! is_int( $conversation_id ) || $conversation_id <= 0 || get_current_user_id() <= 0 ) {
			return $this->denied();
		}
		$results = array();
		foreach ( $this->owner_posts() as $post ) {
			$record = get_post_meta( $post->ID, self::META_RECORD, true );
			if ( ! is_array( $record ) || (int) ( $record['user_id'] ?? 0 ) !== get_current_user_id() ||
				(int) ( $record['conversation_id'] ?? 0 ) !== $conversation_id ||
				! is_string( $record['token_hash'] ?? null ) ) {
				continue;
			}
			$record['_post_id'] = (int) $post->ID;
			$record['_original'] = get_post_meta( $post->ID, self::META_RECORD, true );
			$record = $this->reconcile( $record );
			$reference = $this->restore_token( $post->ID, $record['token_hash'] );
			$result = $this->member_result( $record, $reference );
			$result['token'] = $reference;
			$results[] = $result;
		}
		return $results;
	}

	private function owner_posts() {
		return get_posts( array(
			'post_type' => self::POST_TYPE, 'post_status' => 'private',
			'author' => get_current_user_id(), 'posts_per_page' => -1,
			'orderby' => 'ID', 'order' => 'ASC',
		) );
	}

	private function restore_token( $post_id, $hash ) {
		return hash_hmac( 'sha256', $post_id . ':' . $hash, wp_salt( 'auth' ) );
	}

	public function claim( array $record, $action ) {
		if ( 'awaiting_confirmation' !== $record['state'] || $this->now() >= (int) $record['expires_at'] || ! in_array( $action, array( 'confirm', 'cancel' ), true ) ) {
			return false;
		}
		return add_option( $this->claim_key( $record['token_hash'] ), array( 'action' => $action, 'at' => $this->now() ), '', false );
	}

	public function save( array &$record ) {
		$id = $record['_post_id'];
		$before = $record['_original'];
		$stored = $record;
		unset( $stored['_post_id'], $stored['_original'] );
		$ok = update_post_meta( $id, self::META_RECORD, $stored, $before );
		if ( $ok ) { $record['_original'] = $stored; }
		return $ok;
	}

	public function member_result( array $record, $token = null ) {
		$result = array( 'status' => $record['state'] );
		if ( 'awaiting_confirmation' === $record['state'] ) {
			$result['token'] = $token;
			$result['expires_at_utc'] = $record['expires_at'];
			$result['preview'] = array(
				'topic' => $record['topic'], 'category' => ORAS_AI_Support_Topic::labels()[ $record['topic'] ],
				'subject' => $record['subject'], 'summary' => $record['summary'],
				'original_question' => $record['original_question'],
				'original_question_included' => true, 'destination' => $record['destination'],
			);
		}
		if ( 'created' === $record['state'] ) {
			$result['ticket_id'] = (int) $record['ticket_id'];
		}
		if ( '' !== $record['reason'] ) {
			$result['reason'] = $record['reason'];
		}
		if ( 'uncertain' === $record['state'] ) {
			$result['message'] = 'ORAS AI cannot determine whether the ticket was created. Automatic retry is unsafe; please contact ORAS Support.';
		}
		return $result;
	}

	private function reconcile( array $record ) {
		$claim = get_option( $this->claim_key( $record['token_hash'] ), false );
		if ( 'awaiting_confirmation' === $record['state'] && is_array( $claim ) ) {
			if ( 'cancel' === ( $claim['action'] ?? '' ) || 'expire' === ( $claim['action'] ?? '' ) ) {
				$record['state'] = 'cancel' === $claim['action'] ? 'cancelled' : 'expired';
			} else {
				$record['state'] = 'creating';
				$record['attempt_at'] = (int) ( $claim['at'] ?? $this->now() );
			}
		}
		if ( 'awaiting_confirmation' === $record['state'] && $this->now() >= (int) $record['expires_at'] ) {
			if ( add_option( $this->claim_key( $record['token_hash'] ), array( 'action' => 'expire', 'at' => $this->now() ), '', false ) ) {
				$record['state'] = 'expired';
				$this->save( $record );
				ORAS_AI_Audit_Log::log_support_escalation( 'escalation_expired', $record['topic'] );
			} else {
				return $this->reconcile( $this->reload_record( $record ) );
			}
		}
		if ( in_array( $record['state'], array( 'confirmed', 'creating' ), true ) && $this->now() - (int) $record['attempt_at'] >= self::STALE_CREATING_SECONDS ) {
			$record['state'] = 'uncertain';
			$record['reason'] = 'interrupted_attempt';
			if ( $this->save( $record ) ) {
				ORAS_AI_Audit_Log::log_support_escalation( 'support_ticket_uncertain', $record['topic'] );
			} else {
				return $this->reload_record( $record );
			}
		}
		return $record;
	}

	private function reload_record( array $record ) {
		$stored = get_post_meta( $record['_post_id'], self::META_RECORD, true );
		if ( ! is_array( $stored ) ) { return $record; }
		$stored['_post_id'] = $record['_post_id'];
		$stored['_original'] = $stored;
		unset( $stored['_original']['_post_id'], $stored['_original']['_original'] );
		return $stored;
	}

	public function prune_expired() {
		$posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $posts as $id ) {
			$record = get_post_meta( $id, self::META_RECORD, true );
			if ( is_array( $record ) && $this->now() >= (int) ( $record['created_at'] ?? 0 ) + self::RETENTION_SECONDS ) {
			wp_delete_post( $id, true );
			delete_option( $this->claim_key( (string) ( $record['token_hash'] ?? '' ) ) );
		}
		}
	}

	private function claim_key( $hash ) { return 'oras_ai_escalation_claim_' . $hash; }
	private function denied() { return new WP_Error( 'oras_ai_escalation_denied', __( 'Escalation unavailable.', 'oras-ai-assistant' ), array( 'status' => 403 ) ); }
	private function storage_error() { return new WP_Error( 'oras_ai_escalation_storage_failed', __( 'Escalation could not be saved.', 'oras-ai-assistant' ), array( 'status' => 500 ) ); }
}
