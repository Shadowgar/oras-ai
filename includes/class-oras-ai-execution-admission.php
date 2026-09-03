<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Execution_Admission {

	private $allowed;
	private $reason;
	private $reservation_id;
	private $reserved_cost_microdollars;
	private $max_output_tokens;
	private $timeout_seconds;

	public function __construct( $allowed, $reason = '', $reservation_id = '', $reserved_cost_microdollars = 0, $max_output_tokens = 0, $timeout_seconds = 0 ) {
		$this->allowed                     = (bool) $allowed;
		$this->reason                      = sanitize_key( $reason );
		$this->reservation_id              = sanitize_text_field( (string) $reservation_id );
		$this->reserved_cost_microdollars  = max( 0, (int) $reserved_cost_microdollars );
		$this->max_output_tokens           = max( 0, (int) $max_output_tokens );
		$this->timeout_seconds             = max( 0, (int) $timeout_seconds );
	}

	public function allowed() {
		return $this->allowed;
	}

	public function reason() {
		return $this->reason;
	}

	public function reservation_id() {
		return $this->reservation_id;
	}

	public function reserved_cost_microdollars() {
		return $this->reserved_cost_microdollars;
	}

	public function max_output_tokens() {
		return $this->max_output_tokens;
	}

	public function timeout_seconds() {
		return $this->timeout_seconds;
	}
}
