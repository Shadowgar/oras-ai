<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Deterministic, owner-bound confirmation; all provider writes follow a unique claim. */
final class ORAS_AI_Escalation_Confirmation_Service {
	private $pending;
	private $routing;
	private $conversations;
	private $adapter;

	public function __construct( ORAS_AI_Pending_Escalations $pending, ORAS_AI_Support_Routing $routing, ORAS_AI_Conversations $conversations, $adapter = null ) {
		$this->pending = $pending;
		$this->routing = $routing;
		$this->conversations = $conversations;
		$this->adapter = $adapter ?: new ORAS_AI_Fluent_Support_Adapter();
	}

	public function propose( ORAS_AI_Escalation_Proposal $proposal ) {
		if ( is_wp_error( $this->conversations->get_conversation( $proposal->conversation_id() ) ) || $proposal->owner_user_id() !== get_current_user_id() ) {
			return $this->denied();
		}
		return $this->pending->create( $proposal );
	}

	public function status( $token, $conversation_id ) {
		$record = $this->owned_record( $token, $conversation_id );
		return is_wp_error( $record ) ? $record : $this->pending->member_result( $record, $token );
	}

	public function cancel( $token, $conversation_id ) {
		$record = $this->owned_record( $token, $conversation_id );
		if ( is_wp_error( $record ) ) { return $record; }
		if ( 'awaiting_confirmation' !== $record['state'] ) { return $this->pending->member_result( $record ); }
		if ( ! $this->pending->claim( $record, 'cancel' ) ) { return $this->status( $token, $conversation_id ); }
		$record['state'] = 'cancelled';
		$this->pending->save( $record );
		ORAS_AI_Audit_Log::log_support_escalation( 'escalation_cancelled', $record['topic'] );
		return $this->pending->member_result( $record );
	}

	public function confirm( $token, $conversation_id ) {
		$record = $this->owned_record( $token, $conversation_id );
		if ( is_wp_error( $record ) ) { return $record; }
		if ( 'awaiting_confirmation' !== $record['state'] ) {
			ORAS_AI_Audit_Log::log_support_escalation( 'duplicate_confirmation_replayed', $record['topic'] );
			return $this->pending->member_result( $record );
		}
		if ( ! $this->pending->claim( $record, 'confirm' ) ) { return $this->status( $token, $conversation_id ); }
		$record['state'] = 'confirmed';
		$record['attempt_at'] = $this->pending->now();
		if ( ! $this->pending->save( $record ) ) { return $this->storage_uncertain( $record ); }
		ORAS_AI_Audit_Log::log_support_escalation( 'escalation_confirmation_accepted', $record['topic'] );
		$record['state'] = 'creating';
		if ( ! $this->pending->save( $record ) ) { return $this->storage_uncertain( $record ); }

		// Re-resolve current server configuration, then compare it with the shown route.
		$current = $this->routing->route_for( $record['topic'] );
		if ( 'route_valid' !== $current['status'] ) { return $this->finish( $record, 'failed', 'route_unavailable' ); }
		$route = $current['route'];
		if ( $current['topic'] !== $record['topic'] || $current['destination'] !== $record['destination'] ||
			$route->mailbox_id() !== $record['mailbox_id'] || $route->tag_ids() !== $record['tag_ids'] ) {
			return $this->finish( $record, 'failed', 'route_changed_refresh_required' );
		}
		if ( 'available' !== $this->adapter->status()->status() ) { return $this->finish( $record, 'failed', 'provider_unavailable' ); }
		$customer = $this->adapter->resolve_confirmed_customer( get_current_user_id(), true );
		if ( ! in_array( $customer->status(), array( 'customer_resolved', 'customer_created' ), true ) || ! $customer->id() ) {
			return $this->finish( $record, 'failed', 'customer_unavailable' );
		}
		$content = "Original question: " . $record['original_question'] . "\n\nSummary: " . $record['summary'];
		// The Task 1 adapter adds the truthful provenance and normalizes provider outcomes.
		try {
			$result = $this->adapter->create_confirmed_ticket( $customer->id(), $route, $record['subject'], $content );
		} catch ( Throwable $error ) {
			return $this->finish( $record, 'uncertain', 'provider_create_uncertain' );
		}
		if ( 'ticket_created' === $result->status() && $result->id() ) {
			$record['ticket_id'] = $result->id();
			return $this->finish( $record, 'created', 'tag_attachment_uncertain' === $result->reason() ? 'tag_attachment_uncertain' : '' );
		}
		if ( 'ticket_uncertain' === $result->status() ) { return $this->finish( $record, 'uncertain', 'provider_create_uncertain' ); }
		return $this->finish( $record, 'failed', 'ticket_creation_failed' );
	}

	private function finish( array $record, $state, $reason ) {
		$record['state'] = $state;
		$record['reason'] = $reason;
		if ( ! $this->pending->save( $record ) ) { return $this->storage_uncertain( $record ); }
		ORAS_AI_Audit_Log::log_support_escalation( 'support_ticket_' . $state, $record['topic'] );
		return $this->pending->member_result( $record );
	}

	private function storage_uncertain( array $record ) {
		$record['state'] = 'uncertain';
		$record['ticket_id'] = null;
		$record['reason'] = 'state_persistence_uncertain';
		ORAS_AI_Audit_Log::log_support_escalation( 'support_ticket_uncertain', $record['topic'] );
		return $this->pending->member_result( $record );
	}

	private function owned_record( $token, $conversation_id ) {
		$record = $this->pending->load( $token, $conversation_id );
		if ( is_wp_error( $record ) ) { return $record; }
		$conversation = $this->conversations->get_conversation( (int) $record['conversation_id'] );
		if ( is_wp_error( $conversation ) || (int) $conversation['user_id'] !== get_current_user_id() ) { return $this->denied(); }
		return $record;
	}

	private function denied() { return new WP_Error( 'oras_ai_escalation_denied', __( 'Escalation unavailable.', 'oras-ai-assistant' ), array( 'status' => 403 ) ); }
}
