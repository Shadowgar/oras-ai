<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Request_Gateway {

	const NONCE_ACTION = 'oras_ai_member_request';

	private $membership_authorizer;
	private $answer_orchestrator;
	private $sensitive_input_guard;

	public function __construct( ORAS_AI_Membership_Authorizer_Interface $membership_authorizer, ?ORAS_AI_Answer_Orchestrator $answer_orchestrator = null, ?ORAS_AI_Sensitive_Input_Guard $sensitive_input_guard = null ) {
		$this->membership_authorizer = $membership_authorizer;
		$this->answer_orchestrator   = $answer_orchestrator;
		$this->sensitive_input_guard = $sensitive_input_guard ?: new ORAS_AI_Sensitive_Input_Guard();

		add_action( 'wp_ajax_oras_ai_member_request', array( $this, 'handle_ajax_request' ) );
	}

	/**
	 * Establish the server-side authorization context for a future member request.
	 *
	 * @param array $request Request fields from the authenticated transport.
	 * @return ORAS_AI_Authorized_Request|WP_Error
	 */
	public function authorize( array $request ) {
		$user_id = $this->authenticated_user( $request );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! isset( $request['question'] ) || ! is_string( $request['question'] ) ) {
			return $this->invalid_request();
		}

		$question = trim( wp_unslash( $request['question'] ) );
		if ( '' === $question ) {
			return $this->invalid_request();
		}

		$safe = $this->sensitive_input_guard->validate( $question );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$authorization = $this->authorize_authenticated_user( $user_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		return new ORAS_AI_Authorized_Request(
			$user_id,
			$question,
			$authorization['allowed_visibilities'],
			$authorization['is_administrator']
		);
	}

	/**
	 * Authorize an authenticated operation that has no member question yet.
	 *
	 * @param array $request Request fields from the authenticated transport.
	 * @return array|WP_Error
	 */
	public function authorize_member( array $request ) {
		$user_id = $this->authenticated_user( $request );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return $this->authorize_authenticated_user( $user_id );
	}

	/**
	 * Apply domain classification only after request authorization succeeds.
	 *
	 * @param array  $request Request fields from the authenticated transport.
	 * @param object $guard Server-configured request-domain guard.
	 * @return ORAS_AI_Guarded_Request|WP_Error
	 */
	public function authorize_and_guard( array $request, $guard ) {
		$authorized = $this->authorize( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$domain_result = $guard->classify( $authorized->question() );
		return new ORAS_AI_Guarded_Request( $authorized, $domain_result );
	}

	public function handle_ajax_request() {
		$authorized = $this->authorize( $_POST );

		if ( is_wp_error( $authorized ) ) {
			$data   = $authorized->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
			wp_send_json_error(
				array( 'message' => $authorized->get_error_message() ),
				$status
			);
		}

		if ( $this->answer_orchestrator ) {
			$result = $this->answer_orchestrator->answer( $authorized );
			wp_send_json_success( $result->to_array(), 200 );
		}

		wp_send_json_success( array( 'authorized' => true ), 200 );
	}

	private function denied() {
		return new WP_Error(
			'oras_ai_request_denied',
			__( 'Request denied.', 'oras-ai-assistant' ),
			array( 'status' => 403 )
		);
	}

	private function invalid_request() {
		return new WP_Error(
			'oras_ai_invalid_request',
			__( 'Invalid request.', 'oras-ai-assistant' ),
			array( 'status' => 400 )
		);
	}

	private function authenticated_user( array $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->denied();
		}

		$nonce = $request['nonce'] ?? '';
		if (
			( ! is_string( $nonce ) && ! is_int( $nonce ) )
			|| '' === trim( (string) $nonce )
			|| ! wp_verify_nonce( (string) $nonce, self::NONCE_ACTION )
		) {
			return $this->denied();
		}

		return (int) $user_id;
	}

	private function authorize_authenticated_user( $user_id ) {
		$is_administrator = current_user_can( 'manage_options' );
		if (
			! $is_administrator
			&& ! $this->membership_authorizer->has_active_membership( $user_id )
		) {
			return $this->denied();
		}

		if ( ! ORAS_AI_Access_Guard::member_ai_execution_allowed() ) {
			return $this->denied();
		}

		return array(
			'user_id'              => (int) $user_id,
			'is_administrator'     => $is_administrator,
			'allowed_visibilities' => $is_administrator
				? array( 'public', 'members', 'admin' )
				: array( 'public', 'members' ),
		);
	}
}
