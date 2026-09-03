<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Request_Gateway {

	const NONCE_ACTION = 'oras_ai_member_request';

	private $membership_authorizer;

	public function __construct( ORAS_AI_Membership_Authorizer_Interface $membership_authorizer ) {
		$this->membership_authorizer = $membership_authorizer;

		add_action( 'wp_ajax_oras_ai_member_request', array( $this, 'handle_ajax_request' ) );
	}

	/**
	 * Establish the server-side authorization context for a future member request.
	 *
	 * @param array $request Request fields from the authenticated transport.
	 * @return ORAS_AI_Authorized_Request|WP_Error
	 */
	public function authorize( array $request ) {
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

		if ( ! isset( $request['question'] ) || ! is_string( $request['question'] ) ) {
			return $this->invalid_request();
		}

		$question = trim( wp_unslash( $request['question'] ) );
		if ( '' === $question ) {
			return $this->invalid_request();
		}

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

		$allowed_visibilities = $is_administrator
			? array( 'public', 'members', 'admin' )
			: array( 'public', 'members' );

		return new ORAS_AI_Authorized_Request(
			$user_id,
			$question,
			$allowed_visibilities,
			$is_administrator
		);
	}

	public function handle_ajax_request() {
		$result = $this->authorize( $_POST );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				$status
			);
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
}
