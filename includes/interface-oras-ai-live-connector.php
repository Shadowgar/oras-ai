<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Live_Connector_Interface {

	public function supports( ORAS_AI_Live_Request $request );

	public function fetch( ORAS_AI_Live_Request $request );
}
