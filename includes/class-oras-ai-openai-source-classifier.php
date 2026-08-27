<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_OpenAI_Source_Classifier implements ORAS_AI_Source_Classifier_Interface {

	public function classify_source( $title, $url, $post_type, $content ) {
		return ORAS_AI_OpenAI::classify_source( $title, $url, $post_type, $content );
	}
}
