<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Domain_Classifier_Interface {

	/**
	 * Classify one ambiguous member question without answering it.
	 *
	 * @param string $question Member question.
	 * @return ORAS_AI_Domain_Result|WP_Error
	 */
	public function classify( $question );
}
