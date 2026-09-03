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

	public function __construct( ORAS_AI_Request_Gateway $request_gateway, ORAS_AI_Answer_Orchestrator $answer_orchestrator, ORAS_AI_Conversations $conversations ) {
		$this->request_gateway     = $request_gateway;
		$this->answer_orchestrator = $answer_orchestrator;
		$this->conversations       = $conversations;

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
		if ( ! is_string( $operation ) || ! in_array( $operation, array( 'current', 'new_chat', 'load', 'send' ), true ) ) {
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
		return array(
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
	}

	private function conversation_response( $conversation ) {
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		$messages = $this->conversations->get_messages( $conversation['id'] );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		return array(
			'conversation_id' => (int) $conversation['id'],
			'conversation'    => array(
				'id'             => (int) $conversation['id'],
				'status'         => (string) $conversation['status'],
				'created_at_utc' => (int) $conversation['created_at_utc'],
				'updated_at_utc' => (int) $conversation['updated_at_utc'],
			),
			'messages'        => $messages,
		);
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
