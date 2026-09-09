<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Weather_Provider_Interface {
	public function provider_id();

	public function fetch( ORAS_AI_Current_Data_Request $request );
}
