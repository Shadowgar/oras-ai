<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-safe backend answer result. It contains no raw provider payload or HTML.
 */
final class ORAS_AI_Answer_Result {

	const SUCCESS     = 'success';
	const REFUSAL     = 'refusal';
	const NO_EVIDENCE = 'no_evidence';
	const FAILURE     = 'failure';

	private $status;
	private $answer;
	private $sources;
	private $model;
	private $usage;
	private $error_code;
	private $reservation_id;

	private function __construct( array $fields ) {
		$this->status         = $fields['status'];
		$this->answer         = trim( wp_strip_all_tags( (string) $fields['answer'], true ) );
		$this->sources        = array_values( is_array( $fields['sources'] ) ? $fields['sources'] : array() );
		$this->model          = sanitize_text_field( (string) $fields['model'] );
		$this->usage          = array(
			'input_tokens'  => max( 0, (int) ( $fields['usage']['input_tokens'] ?? 0 ) ),
			'output_tokens' => max( 0, (int) ( $fields['usage']['output_tokens'] ?? 0 ) ),
		);
		$this->error_code     = sanitize_key( $fields['error_code'] );
		$this->reservation_id = sanitize_text_field( (string) $fields['reservation_id'] );
	}

	public static function success( $answer, array $sources, $model, array $usage, $reservation_id ) {
		return self::make( self::SUCCESS, $answer, $sources, $model, $usage, '', $reservation_id );
	}

	public static function refusal( $answer, $error_code = '' ) {
		return self::make( self::REFUSAL, $answer, array(), '', array(), $error_code, '' );
	}

	public static function no_evidence( $answer, $error_code = 'no_authoritative_evidence' ) {
		return self::make( self::NO_EVIDENCE, $answer, array(), '', array(), $error_code, '' );
	}

	public static function failure( $error_code, $reservation_id = '' ) {
		return self::make(
			self::FAILURE,
			__( 'ORAS AI could not complete the request.', 'oras-ai-assistant' ),
			array(),
			'',
			array(),
			$error_code,
			$reservation_id
		);
	}

	private static function make( $status, $answer, array $sources, $model, array $usage, $error_code, $reservation_id ) {
		return new self(
			array(
				'status'         => $status,
				'answer'         => $answer,
				'sources'        => $sources,
				'model'          => $model,
				'usage'          => $usage,
				'error_code'     => $error_code,
				'reservation_id' => $reservation_id,
			)
		);
	}

	public function status() {
		return $this->status;
	}

	public function answer() {
		return $this->answer;
	}

	public function sources() {
		return $this->sources;
	}

	public function model() {
		return $this->model;
	}

	public function usage() {
		return $this->usage;
	}

	public function error_code() {
		return $this->error_code;
	}

	public function reservation_id() {
		return $this->reservation_id;
	}

	public function to_array() {
		return array(
			'status'     => $this->status,
			'answer'     => $this->answer,
			'sources'    => $this->sources,
			'model'      => $this->model,
			'usage'      => $this->usage,
			'error_code' => $this->error_code,
		);
	}
}
