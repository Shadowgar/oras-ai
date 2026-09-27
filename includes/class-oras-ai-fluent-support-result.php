<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A bounded value returned by the M7 provider adapter. */
final class ORAS_AI_Fluent_Support_Result {
	private $status;
	private $reason;
	private $id;
	private $route;
	private $pro_status;

	public function __construct( $status, $reason = '', $id = null, $route = null, $pro_status = '' ) {
		$this->status     = (string) $status;
		$this->reason     = (string) $reason;
		$this->id         = $id;
		$this->route      = $route;
		$this->pro_status = (string) $pro_status;
	}

	public function status() { return $this->status; }
	public function reason() { return $this->reason; }
	public function id() { return $this->id; }
	public function route() { return $this->route; }
	public function pro_status() { return $this->pro_status; }
}
