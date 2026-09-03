<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Retriever_Interface {

	/**
	 * Retrieve a bounded packet of authorized evidence.
	 *
	 * @param ORAS_AI_Retrieval_Request $request Trusted retrieval inputs.
	 * @return ORAS_AI_Evidence_Packet
	 */
	public function retrieve( ORAS_AI_Retrieval_Request $request );
}
