<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A route validated against provider records, for server-side use only. */
final class ORAS_AI_Fluent_Support_Route {
	private $mailbox_id;
	private $tag_ids;

	private function __construct( $mailbox_id, array $tag_ids ) {
		$this->mailbox_id = $mailbox_id;
		$this->tag_ids    = $tag_ids;
	}

	public static function validated( $mailbox_id, array $tag_ids ) {
		return new self( $mailbox_id, $tag_ids );
	}

	public function mailbox_id() { return $this->mailbox_id; }
	public function tag_ids() { return $this->tag_ids; }
}
