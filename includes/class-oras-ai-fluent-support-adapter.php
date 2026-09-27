<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Narrow, server-only M7 bridge; proposal and confirmation are later tasks. */
final class ORAS_AI_Fluent_Support_Adapter {
	const CORE_VERSION = '2.4.0';
	const PRO_VERSION  = '2.4.0';
	const MAX_TITLE_BYTES = 192;
	const MAX_CONTENT_BYTES = 65535;
	const MAX_TAGS = 32;
	const PROVENANCE = 'Submitted through ORAS AI Assistant';

	private $gateway;

	public function __construct( $gateway = null ) {
		$this->gateway = $gateway ?: new ORAS_AI_Fluent_Support_Core_Gateway();
	}

	public function status() {
		try {
			$versions = $this->gateway->versions();
			$core     = isset( $versions['core'] ) ? $versions['core'] : null;
			$pro      = isset( $versions['pro'] ) ? $versions['pro'] : null;
			$pro_state = null === $pro ? 'missing' : ( self::PRO_VERSION === $pro ? 'available' : 'incompatible' );
			if ( null === $core ) {
				return new ORAS_AI_Fluent_Support_Result( 'missing', 'core_missing', null, null, $pro_state );
			}
			if ( self::CORE_VERSION !== $core || empty( $versions['capable'] ) || 'incompatible' === $pro_state ) {
				return new ORAS_AI_Fluent_Support_Result( 'incompatible', 'provider_incompatible', null, null, $pro_state );
			}
			return new ORAS_AI_Fluent_Support_Result( 'available', '', null, null, $pro_state );
		} catch ( Throwable $error ) {
			return new ORAS_AI_Fluent_Support_Result( 'incompatible', 'provider_error' );
		}
	}

	/** $user_id must come from the authorized request, never browser/model data. */
	public function resolve_confirmed_customer( $user_id, $allow_create = false ) {
		if ( ! self::positive_id( $user_id ) || (int) $user_id !== get_current_user_id() ) {
			return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'invalid_identity' );
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! isset( $user->user_email ) || ! filter_var( $user->user_email, FILTER_VALIDATE_EMAIL ) ) {
			return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'invalid_email' );
		}
		if ( 'available' !== $this->status()->status() ) {
			return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'provider_unavailable' );
		}
		$email = (string) $user->user_email;
		try {
			$linked = $this->gateway->customers_by_user_id( (int) $user_id );
			$matches = $this->gateway->customers_by_email( $email );
			if ( count( $linked ) > 1 || count( $matches ) > 1 ) {
				return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'ambiguous_customer' );
			}
			if ( $linked ) {
				$customer = $linked[0];
				if ( ! self::positive_id( $customer->id ?? null ) || ( $matches && (int) $matches[0]->id !== (int) $customer->id ) ) {
					return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'conflicting_customer' );
				}
				return new ORAS_AI_Fluent_Support_Result( 'customer_resolved', '', (int) $customer->id );
			}
			if ( $matches ) {
				$customer = $matches[0];
				if ( ! self::positive_id( $customer->id ?? null ) || (int) ( $customer->user_id ?? 0 ) !== 0 ) {
					return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'conflicting_customer' );
				}
				// WordPress email is editable; it cannot prove ownership of an
				// unlinked support history, even after ticket confirmation.
				return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'unlinked_customer' );
			}
			if ( ! $allow_create ) {
				return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'customer_missing' );
			}
			$created = $this->gateway->create_customer( array(
				'user_id'        => (int) $user_id,
				'email'          => $email,
				'first_name'     => sanitize_text_field( $user->first_name ?? '' ),
				'last_name'      => sanitize_text_field( $user->last_name ?? '' ),
				'create_wp_user' => false,
			) );
			if ( ! is_object( $created ) || ! self::positive_id( $created->id ?? null ) || (int) ( $created->user_id ?? 0 ) !== (int) $user_id ) {
				return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'customer_creation_uncertain' );
			}
			return new ORAS_AI_Fluent_Support_Result( 'customer_created', '', (int) $created->id );
		} catch ( Throwable $error ) {
			return new ORAS_AI_Fluent_Support_Result( 'customer_failure', 'provider_error' );
		}
	}

	/** IDs must be chosen by server configuration; this method checks provider existence. */
	public function validate_provider_route( $mailbox_id, array $tag_ids ) {
		if ( 'available' !== $this->status()->status() ) {
			return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'provider_unavailable' );
		}
		if ( ! self::positive_id( $mailbox_id ) || count( $tag_ids ) > self::MAX_TAGS ) {
			return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'invalid_route_ids' );
		}
		$tags = array();
		foreach ( $tag_ids as $tag_id ) {
			if ( ! self::positive_id( $tag_id ) ) {
				return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'invalid_route_ids' );
			}
			$tags[(int) $tag_id] = (int) $tag_id;
		}
		try {
			if ( ! $this->gateway->mailbox( (int) $mailbox_id ) ) {
				return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'mailbox_missing' );
			}
			foreach ( $tags as $tag_id ) {
				if ( ! $this->gateway->tag( $tag_id ) ) {
					return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'tag_missing' );
				}
			}
		} catch ( Throwable $error ) {
			return new ORAS_AI_Fluent_Support_Result( 'route_invalid', 'provider_error' );
		}
		$route = ORAS_AI_Fluent_Support_Route::validated( (int) $mailbox_id, array_values( $tags ) );
		return new ORAS_AI_Fluent_Support_Result( 'route_valid', '', null, $route );
	}

	public function create_confirmed_ticket( $customer_id, $route, $subject, $content ) {
		if ( 'available' !== $this->status()->status() || ! self::positive_id( $customer_id ) || ! $route instanceof ORAS_AI_Fluent_Support_Route ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'invalid_input' );
		}
		if ( ! is_string( $subject ) || ! is_string( $content ) ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'invalid_input' );
		}
		$title = sanitize_text_field( $subject );
		// Core decodes entities after wp_kses_post(); remove encoded markup first.
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$decoded = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $content ) {
				break;
			}
			$content = $decoded;
		}
		$plain = trim( str_replace( array( '<', '>' ), '', wp_strip_all_tags( $content ) ) );
		$body  = trim( $plain . "\n\n" . self::PROVENANCE );
		if ( '' === $title || '' === $plain || strlen( $title ) > self::MAX_TITLE_BYTES || strlen( $body ) > self::MAX_CONTENT_BYTES ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'invalid_content' );
		}
		$owner = $this->resolve_confirmed_customer( get_current_user_id() );
		if ( 'customer_resolved' !== $owner->status() || $owner->id() !== (int) $customer_id ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'invalid_customer' );
		}
		try {
			if ( $this->gateway->current_user_is_agent() ) {
				return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'agent_session_unsupported' );
			}
		} catch ( Throwable $error ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'provider_error' );
		}
		// Revalidate stale route data before the only provider create attempt.
		$checked = $this->validate_provider_route( $route->mailbox_id(), $route->tag_ids() );
		if ( 'route_valid' !== $checked->status() ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'invalid_route' );
		}
		try {
			$ticket = $this->gateway->create_ticket( array(
				'customer_id' => (int) $customer_id,
				'mailbox_id'  => $route->mailbox_id(),
				'title'       => $title,
				'content'     => $body,
			) );
		} catch ( Throwable $error ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_uncertain', 'provider_create_uncertain' );
		}
		if ( false === $ticket ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_failed', 'provider_rejected_before_insert' );
		}
		if ( ! is_object( $ticket ) || ! self::positive_id( $ticket->id ?? null ) ) {
			return new ORAS_AI_Fluent_Support_Result( 'ticket_uncertain', 'provider_create_uncertain' );
		}
		$ticket_id = (int) $ticket->id;
		if ( $route->tag_ids() ) {
			try {
				if ( ! $this->gateway->apply_tags( $ticket, $route->tag_ids() ) ) {
					return new ORAS_AI_Fluent_Support_Result( 'ticket_created', 'tag_attachment_uncertain', $ticket_id );
				}
			} catch ( Throwable $error ) {
				return new ORAS_AI_Fluent_Support_Result( 'ticket_created', 'tag_attachment_uncertain', $ticket_id );
			}
		}
		return new ORAS_AI_Fluent_Support_Result( 'ticket_created', '', $ticket_id );
	}

	/** Return only eligibility, never ticket body, identity, replies, or provider model. */
	public function resolved_ticket_reference( $ticket_id ) {
		if ( ! self::positive_id( $ticket_id ) ) {
			return array( 'status' => 'invalid_id' );
		}
		if ( 'available' !== $this->status()->status() ) {
			return array( 'status' => 'provider_unavailable' );
		}
		try {
			$ticket = $this->gateway->ticket( (int) $ticket_id );
		} catch ( Throwable $error ) {
			return array( 'status' => 'provider_unavailable' );
		}
		if ( ! is_object( $ticket ) || (int) ( $ticket->id ?? 0 ) !== (int) $ticket_id ) {
			return array( 'status' => 'unavailable' );
		}
		if ( 'closed' !== ( $ticket->status ?? null ) || empty( $ticket->resolved_at ) ) {
			return array( 'status' => 'not_resolved' );
		}
		return array( 'status' => 'resolved', 'id' => (int) $ticket_id );
	}

	private static function positive_id( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return false;
		}
		$value = (string) $value;
		return (bool) preg_match( '/^[1-9][0-9]*$/', $value ) && (string) (int) $value === $value;
	}
}
