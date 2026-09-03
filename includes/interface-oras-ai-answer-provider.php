<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Answer_Provider_Interface {

	/**
	 * Return the server-configured model identifier used for admission accounting.
	 *
	 * @return string
	 */
	public function model();

	/**
	 * Generate one answer from policy-approved, bounded context.
	 *
	 * @param ORAS_AI_Grounded_Context $context Bounded request context.
	 * @param int                      $max_output_tokens Maximum provider output.
	 * @param int                      $timeout_seconds Bounded transport timeout.
	 * @return ORAS_AI_Provider_Answer
	 */
	public function answer( ORAS_AI_Grounded_Context $context, $max_output_tokens, $timeout_seconds );
}
