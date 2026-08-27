<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Access_Guard {

	public static function member_ai_execution_allowed() {
		return ORAS_AI_Config::member_ai_enabled();
	}
}
