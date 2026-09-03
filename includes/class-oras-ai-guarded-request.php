<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Guarded_Request {

	private $authorized_request;
	private $domain_result;

	public function __construct( ORAS_AI_Authorized_Request $authorized_request, ORAS_AI_Domain_Result $domain_result ) {
		$this->authorized_request = $authorized_request;
		$this->domain_result      = $domain_result;
	}

	public function authorized_request() {
		return $this->authorized_request;
	}

	public function domain_result() {
		return $this->domain_result;
	}

	public function is_allowed() {
		return $this->domain_result->is_allowed();
	}
}
