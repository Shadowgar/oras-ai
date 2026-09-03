<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Evidence_Packet {

	private $items;

	public function __construct( array $items = array() ) {
		$this->items = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return $item instanceof ORAS_AI_Evidence;
				}
			)
		);
	}

	public function items() {
		return $this->items;
	}

	public function to_array() {
		return array_map(
			static function ( ORAS_AI_Evidence $item ) {
				return $item->to_array();
			},
			$this->items
		);
	}

	public function is_empty() {
		return empty( $this->items );
	}

	public function count() {
		return count( $this->items );
	}

	public function text_characters() {
		return array_sum(
			array_map(
				static function ( ORAS_AI_Evidence $item ) {
					return strlen( (string) $item->field( 'relevant_text' ) );
				},
				$this->items
			)
		);
	}
}
