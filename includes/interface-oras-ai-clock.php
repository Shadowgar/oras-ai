<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Clock_Interface {
	public function now(): DateTimeImmutable;
}
