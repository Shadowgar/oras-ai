<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized answer-provider output. Raw provider payloads never cross this boundary.
 */
final class ORAS_AI_Provider_Answer {

	private $successful;
	private $answer;
	private $model;
	private $input_tokens;
	private $output_tokens;
	private $error_code;
	private $usage_may_have_occurred;

	private function __construct( array $fields ) {
		$this->successful              = (bool) $fields['successful'];
		$this->answer                  = trim( wp_strip_all_tags( (string) $fields['answer'], true ) );
		$this->model                   = sanitize_text_field( (string) $fields['model'] );
		$this->input_tokens            = max( 0, (int) $fields['input_tokens'] );
		$this->output_tokens           = max( 0, (int) $fields['output_tokens'] );
		$this->error_code              = sanitize_key( $fields['error_code'] );
		$this->usage_may_have_occurred = (bool) $fields['usage_may_have_occurred'];
	}

	public static function success( $answer, $model, $input_tokens, $output_tokens ) {
		if (
			! is_string( $answer ) || '' === trim( wp_strip_all_tags( $answer, true ) )
			|| ! is_string( $model ) || ! in_array( $model, ORAS_AI_Config::allowed_openai_models(), true )
			|| ! is_int( $input_tokens ) || $input_tokens < 0
			|| ! is_int( $output_tokens ) || $output_tokens < 0
		) {
			return self::failure( 'provider_response_invalid', true );
		}

		return new self(
			array(
				'successful'              => true,
				'answer'                  => $answer,
				'model'                   => $model,
				'input_tokens'            => $input_tokens,
				'output_tokens'           => $output_tokens,
				'error_code'              => '',
				'usage_may_have_occurred' => true,
			)
		);
	}

	public static function failure( $error_code, $usage_may_have_occurred ) {
		$allowed = array(
			'provider_unavailable',
			'provider_response_invalid',
		);
		$error_code = sanitize_key( $error_code );
		if ( ! in_array( $error_code, $allowed, true ) ) {
			$error_code = 'provider_response_invalid';
		}

		return new self(
			array(
				'successful'              => false,
				'answer'                  => '',
				'model'                   => '',
				'input_tokens'            => 0,
				'output_tokens'           => 0,
				'error_code'              => $error_code,
				'usage_may_have_occurred' => (bool) $usage_may_have_occurred,
			)
		);
	}

	public function successful() {
		return $this->successful;
	}

	public function answer() {
		return $this->answer;
	}

	public function model() {
		return $this->model;
	}

	public function input_tokens() {
		return $this->input_tokens;
	}

	public function output_tokens() {
		return $this->output_tokens;
	}

	public function error_code() {
		return $this->error_code;
	}

	public function usage_may_have_occurred() {
		return $this->usage_may_have_occurred;
	}

	public function to_array() {
		return array(
			'successful'              => $this->successful,
			'answer'                  => $this->answer,
			'model'                   => $this->model,
			'input_tokens'            => $this->input_tokens,
			'output_tokens'           => $this->output_tokens,
			'error_code'              => $this->error_code,
			'usage_may_have_occurred' => $this->usage_may_have_occurred,
		);
	}
}
