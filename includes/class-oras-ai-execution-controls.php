<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admission boundary Task 5 must pass before paid answer-provider execution.
 */
final class ORAS_AI_Execution_Controls {

	private $ledger;
	private $configuration;

	public function __construct( ?ORAS_AI_Usage_Ledger $ledger = null, ?array $configuration = null ) {
		$this->ledger        = $ledger ?: new ORAS_AI_Usage_Ledger();
		$this->configuration = null === $configuration ? ORAS_AI_Cost_Config::get() : $configuration;
	}

	public function admit( ORAS_AI_Authorized_Request $request, $model ) {
		if ( ! in_array( $model, ORAS_AI_Config::allowed_openai_models(), true ) ) {
			$this->ledger->record_rejection( $request->user_id(), 'missing_model_price' );
			return new ORAS_AI_Execution_Admission( false, 'missing_model_price' );
		}

		$max_input = max( 1, (int) $this->configuration['max_input_characters'] );
		if ( strlen( $request->question() ) > $max_input ) {
			$this->ledger->record_rejection( $request->user_id(), 'input_too_large' );
			return new ORAS_AI_Execution_Admission( false, 'input_too_large' );
		}

		return $this->ledger->reserve(
			$request->user_id(),
			$model,
			max( 1, strlen( $request->question() ) ),
			$this->configuration
		);
	}
}
