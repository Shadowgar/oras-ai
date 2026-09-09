<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Current_Data_Value_Interface {
	public function provider_id();

	public function authority_class();

	public function to_array();
}
