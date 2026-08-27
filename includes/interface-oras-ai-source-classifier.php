<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Source_Classifier_Interface {

	public function classify_source( $title, $url, $post_type, $content );
}
