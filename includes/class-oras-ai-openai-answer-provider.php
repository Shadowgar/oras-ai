<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initial answer-provider adapter for the OpenAI Responses API.
 */
final class ORAS_AI_OpenAI_Answer_Provider implements ORAS_AI_Answer_Provider_Interface {

	private $api_key_resolver;
	private $model_resolver;

	public function __construct( $api_key_resolver = null, $model_resolver = null ) {
		$this->api_key_resolver = is_callable( $api_key_resolver ) ? $api_key_resolver : array( 'ORAS_AI_Config', 'get_openai_api_key' );
		$this->model_resolver   = is_callable( $model_resolver ) ? $model_resolver : array( 'ORAS_AI_Config', 'get_openai_model' );
	}

	public function model() {
		return ORAS_AI_Config::normalize_openai_model( call_user_func( $this->model_resolver ) );
	}

	public function answer( ORAS_AI_Grounded_Context $context, $max_output_tokens, $timeout_seconds ) {
		$api_key = trim( (string) call_user_func( $this->api_key_resolver ) );
		if ( '' === $api_key ) {
			return ORAS_AI_Provider_Answer::failure( 'provider_unavailable', false );
		}

		$max_output_tokens = (int) $max_output_tokens;
		$timeout_seconds   = (int) $timeout_seconds;
		if (
			$max_output_tokens <= 0 || $max_output_tokens > ORAS_AI_Cost_Config::MAX_OUTPUT_TOKENS
			|| $timeout_seconds <= 0 || $timeout_seconds > ORAS_AI_Cost_Config::MAX_EXECUTION_TIMEOUT_SECONDS
		) {
			return ORAS_AI_Provider_Answer::failure( 'provider_unavailable', false );
		}

		$model   = $this->model();
		$payload = array(
			'model'             => $model,
			'reasoning'         => array( 'effort' => 'low' ),
			'max_output_tokens' => $max_output_tokens,
			'input'             => $context->provider_input(),
		);
		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => $timeout_seconds,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return ORAS_AI_Provider_Answer::failure( 'provider_response_invalid', true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return ORAS_AI_Provider_Answer::failure( 'provider_response_invalid', true );
		}

		$answer = $this->extract_output_text( $body );
		$usage  = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array();
		$input  = $usage['input_tokens'] ?? null;
		$output = $usage['output_tokens'] ?? null;

		return ORAS_AI_Provider_Answer::success( $answer, $model, $input, $output );
	}

	private function extract_output_text( array $body ) {
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			return trim( $body['output_text'] );
		}

		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if (
					isset( $content['type'], $content['text'] )
					&& 'output_text' === $content['type']
					&& is_string( $content['text'] )
				) {
					return trim( $content['text'] );
				}
			}
		}

		return '';
	}
}
