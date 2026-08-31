<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Source_Classifier_Interface {

	/**
	 * @return ORAS_AI_Source_Classification_Result|WP_Error
	 */
	public function classify_source( $title, $url, $post_type, $content );
}
