<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fixed provider-neutral M6 planet allowlist. */
final class ORAS_AI_Planet_Targets {
	private const BODIES = array( 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune' );
	public static function allowed() { return self::BODIES; }
}
