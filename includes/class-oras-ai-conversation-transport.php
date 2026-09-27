<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated transport for member conversation operations.
 */
final class ORAS_AI_Conversation_Transport {

	const AJAX_ACTION = 'oras_ai_conversation';

	private $request_gateway;
	private $answer_orchestrator;
	private $conversations;
	private $escalation_service;
	private $confirmation_service;

	public function __construct( ORAS_AI_Request_Gateway $request_gateway, ORAS_AI_Answer_Orchestrator $answer_orchestrator, ORAS_AI_Conversations $conversations, $escalation_service = null, $confirmation_service = null ) {
		$this->request_gateway     = $request_gateway;
		$this->answer_orchestrator = $answer_orchestrator;
		$this->conversations       = $conversations;
		$this->escalation_service  = $escalation_service instanceof ORAS_AI_Escalation_Proposal_Service ? $escalation_service : null;
		$this->confirmation_service = $confirmation_service instanceof ORAS_AI_Escalation_Confirmation_Service ? $confirmation_service : null;

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax_request' ) );
	}

	/**
	 * Dispatch one authenticated operation without rendering a response.
	 *
	 * @param array $request Request fields from the authenticated transport.
	 * @return array|WP_Error
	 */
	public function dispatch( array $request ) {
		$operation = $request['operation'] ?? '';
		if ( ! is_string( $operation ) || ! in_array( $operation, array( 'current', 'new_chat', 'load', 'send', 'confirm_escalation', 'cancel_escalation', 'escalation_status' ), true ) ) {
			$authorization = $this->request_gateway->authorize_member( $request );
			if ( is_wp_error( $authorization ) ) {
				return $authorization;
			}
			return $this->invalid_operation();
		}

		if ( 'send' === $operation ) {
			$authorized = $this->request_gateway->authorize( $request );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
			return $this->send( $authorized, $request );
		}

		$authorization = $this->request_gateway->authorize_member( $request );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		if ( in_array( $operation, array( 'confirm_escalation', 'cancel_escalation', 'escalation_status' ), true ) ) {
			if ( null === $this->confirmation_service ) { return $this->invalid_operation(); }
			$conversation_id = isset( $request['conversation_id'] ) ? $this->validated_conversation_id( $request['conversation_id'] ) : null;
			if ( is_wp_error( $conversation_id ) ) { return $conversation_id; }
			$token = $request['token'] ?? null;
			if ( 'confirm_escalation' === $operation ) { return $this->confirmation_service->confirm( $token, $conversation_id ); }
			if ( 'cancel_escalation' === $operation ) { return $this->confirmation_service->cancel( $token, $conversation_id ); }
			return $this->confirmation_service->status( $token, $conversation_id );
		}

		if ( 'current' === $operation ) {
			$conversation = $this->conversations->current_conversation();
			if ( is_wp_error( $conversation ) ) {
				return $conversation;
			}
			if ( null === $conversation ) {
				$conversation_id = $this->conversations->create_conversation();
				if ( is_wp_error( $conversation_id ) ) {
					return $conversation_id;
				}
				$conversation = $this->conversations->get_conversation( $conversation_id );
			}
			return $this->conversation_response( $conversation );
		}

		if ( 'new_chat' === $operation ) {
			$conversation_id = $this->conversations->create_conversation( $request );
			if ( is_wp_error( $conversation_id ) ) {
				return $conversation_id;
			}
			return $this->conversation_response( $this->conversations->get_conversation( $conversation_id ) );
		}

		$conversation_id = $this->validated_conversation_id( $request['conversation_id'] ?? null );
		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}
		$conversation = $this->conversations->get_conversation( $conversation_id );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		return $this->conversation_response( $conversation );
	}

	public function handle_ajax_request() {
		$result = $this->dispatch( $_POST );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		wp_send_json_success( $result, 200 );
	}

	private function send( ORAS_AI_Authorized_Request $authorized, array $request ) {
		$conversation_id = $this->validated_conversation_id( $request['conversation_id'] ?? null );
		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}

		$member_id = $this->conversations->append_message( $conversation_id, 'member', $authorized->question() );
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}

		$result = $this->answer_orchestrator->answer( $authorized );
		if ( ! $result instanceof ORAS_AI_Answer_Result ) {
			return $this->storage_failure();
		}

		$sources = $this->conversations->normalize_source_references( $result->sources() );
		$assistant_id = $this->conversations->append_message( $conversation_id, 'assistant', $result->answer(), $sources );
		if ( is_wp_error( $assistant_id ) ) {
			return $assistant_id;
		}

		$assistant_message = $this->message_by_id( $conversation_id, $assistant_id );
		if ( ! isset( $assistant_message['sources'] ) ) {
			$assistant_message['sources'] = array();
		}
		$response = array(
			'conversation_id'  => $conversation_id,
			'member_message'   => $this->message_by_id( $conversation_id, $member_id ),
			'assistant_message' => $assistant_message,
			'result'           => array(
				'status'     => $result->status(),
				'answer'     => $result->answer(),
				'sources'    => $sources,
				'error_code' => $result->error_code(),
			),
		);
		if ( null !== $this->escalation_service ) {
			$escalation = $this->escalation_service->propose( $authorized, $conversation_id, $result );
			if ( 'none' !== $escalation->status() ) {
				if ( 'proposed' === $escalation->status() && null !== $this->confirmation_service ) {
					$pending = $this->confirmation_service->propose( $escalation->proposal() );
					$response['result']['escalation'] = is_wp_error( $pending ) ? array( 'status' => 'unavailable' ) : $pending;
				} else {
					$response['result']['escalation'] = $escalation->to_member_array();
				}
			}
		}
		return $response;
	}

	private function conversation_response( $conversation ) {
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		$messages = $this->conversations->get_messages( $conversation['id'] );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		$response = array(
			'conversation_id' => (int) $conversation['id'],
			'conversation'    => array(
				'id'             => (int) $conversation['id'],
				'status'         => (string) $conversation['status'],
				'created_at_utc' => (int) $conversation['created_at_utc'],
				'updated_at_utc' => (int) $conversation['updated_at_utc'],
			),
			'messages'        => $messages,
		);
		if ( null !== $this->confirmation_service ) {
			$escalations = $this->confirmation_service->for_conversation( (int) $conversation['id'] );
			if ( is_wp_error( $escalations ) ) { return $escalations; }
			$response['escalations'] = $escalations;
		}
		return $response;
	}

	private function message_by_id( $conversation_id, $message_id ) {
		$messages = $this->conversations->get_messages( $conversation_id );
		if ( is_wp_error( $messages ) ) {
			return array();
		}
		foreach ( $messages as $message ) {
			if ( (int) $message['id'] === (int) $message_id ) {
				return $message;
			}
		}
		return array();
	}

	private function validated_conversation_id( $value ) {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}
		return new WP_Error(
			'oras_ai_invalid_conversation',
			__( 'Invalid conversation.', 'oras-ai-assistant' ),
			array( 'status' => 400 )
		);
	}

	private function invalid_operation() {
		return new WP_Error(
			'oras_ai_invalid_operation',
			__( 'Invalid conversation operation.', 'oras-ai-assistant' ),
			array( 'status' => 400 )
		);
	}

	private function storage_failure() {
		return new WP_Error(
			'oras_ai_conversation_storage_failed',
			__( 'Conversation could not be saved.', 'oras-ai-assistant' ),
			array( 'status' => 500 )
		);
	}
}
