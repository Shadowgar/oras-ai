<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Authorized_Request {

	private $user_id;
	private $question;
	private $allowed_visibilities;
	private $is_administrator;

	public function __construct( $user_id, $question, array $allowed_visibilities, $is_administrator ) {
		$this->user_id              = absint( $user_id );
		$this->question             = (string) $question;
		$this->allowed_visibilities = array_values( $allowed_visibilities );
		$this->is_administrator     = (bool) $is_administrator;
	}

	public function user_id() {
		return $this->user_id;
	}

	public function question() {
		return $this->question;
	}

	public function allowed_visibilities() {
		return $this->allowed_visibilities;
	}

	public function is_administrator() {
		return $this->is_administrator;
	}
}
