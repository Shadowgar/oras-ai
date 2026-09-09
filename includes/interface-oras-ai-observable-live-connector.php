<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Observable_Live_Connector_Interface extends ORAS_AI_Live_Connector_Interface {

	public function connector_id();

	public function is_available();

	public function required_fact_keys( ORAS_AI_Live_Request $request );
}
