<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The only paid HTTP seam: durable admission and usage settlement precede parsing. */
final class ORAS_AI_Paid_OpenAI_Transport {
	const DOMAIN_OUTPUT_TOKENS = 128;
	const SCANNER_OUTPUT_TOKENS = 12000;
	const INPUT_FRAMING_ALLOWANCE = 1024;

	public function request( $source, array $payload, $api_key, $timeout, $reservation_id = '' ) {
		$ledger = new ORAS_AI_Usage_Ledger();
		$config = ORAS_AI_Cost_Config::get();
		$limits = array(
			'answer' => array( $config['max_output_tokens'], $config['execution_timeout_seconds'], 18000 ),
			'support_summary' => array( 160, 10, 10000 ),
			'domain_classifier' => array( self::DOMAIN_OUTPUT_TOKENS, 20, 140000 ),
			'scanner_classification' => array( self::SCANNER_OUTPUT_TOKENS, 60, 210000 ),
		);
		$model = $payload['model'] ?? '';
		$output = $payload['max_output_tokens'] ?? 0;
		$body = wp_json_encode( $payload );
		if ( ! isset( $limits[ $source ] ) || ! is_string( $api_key ) || '' === trim( $api_key )
			|| ! in_array( $model, ORAS_AI_Config::allowed_openai_models(), true ) || ! isset( $config['pricing'][ $model ] )
			|| ! is_int( $output ) || $output < 1 || $output > $limits[ $source ][0]
			|| ! is_int( $timeout ) || $timeout < 1 || $timeout > $limits[ $source ][1]
			|| ! is_string( $body ) || '' === $body || strlen( $body ) > $limits[ $source ][2]
			|| empty( $payload['input'] ) || isset( $payload['tools'] ) ) {
			return $this->failure( false );
		}

		// One token per serialized byte plus framing is deliberately conservative.
		$input = strlen( $body ) + self::INPUT_FRAMING_ALLOWANCE;
		if ( '' === $reservation_id ) {
			$call_config = $config;
			$call_config['max_output_tokens'] = $output;
			$call_config['execution_timeout_seconds'] = $timeout;
			$admission = $ledger->reserve( 0, $model, $input, $call_config, $source, false );
			if ( ! $admission->allowed() ) {
				return $this->failure( false );
			}
			$reservation_id = $admission->reservation_id();
		}
		$record = $ledger->claim_dispatch( $reservation_id, $model, $source, $input, $output, $config );
		if ( is_wp_error( $record ) ) {
			$ledger->release( $reservation_id );
			return $this->failure( false );
		}

		try {
			$response = wp_remote_post(
				'https://api.openai.com/v1/responses',
				array(
					'timeout' => $timeout,
					'redirection' => 0,
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
					'body' => $body,
				)
			);
		} catch ( Throwable $error ) {
			if ( is_wp_error( $ledger->settle_reserved_maximum( $reservation_id ) ) ) {
				$ledger->flag_settlement_failure( $reservation_id );
			}
			ORAS_AI_Audit_Log::log_openai_transport_failure( $source, array( 'transport_code' => 'transport_exception' ) );
			return $this->failure( true );
		}
		$decoded = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$usage = is_array( $decoded ) && is_array( $decoded['usage'] ?? null ) ? $decoded['usage'] : array();
		$known = is_int( $usage['input_tokens'] ?? null ) && $usage['input_tokens'] >= 0
			&& is_int( $usage['output_tokens'] ?? null ) && $usage['output_tokens'] >= 0;
		$settled = $known
			? $ledger->reconcile( $reservation_id, $model, $usage['input_tokens'], $usage['output_tokens'] )
			: $ledger->settle_reserved_maximum( $reservation_id );
		if ( is_wp_error( $settled ) ) {
			$ledger->flag_settlement_failure( $reservation_id, $known ? $usage['input_tokens'] : null, $known ? $usage['output_tokens'] : null );
		}
		if ( is_wp_error( $response ) ) {
			ORAS_AI_Audit_Log::log_openai_transport_failure( $source, array( 'transport_code' => $response->get_error_code() ) );
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 || isset( $decoded['error'] )
				|| ( isset( $decoded['status'] ) && 'completed' !== $decoded['status'] ) ) {
				$headers = $response['headers'] ?? array();
				ORAS_AI_Audit_Log::log_openai_transport_failure(
					$source,
					array(
						'http_status' => $status,
						'provider_type' => $decoded['error']['type'] ?? null,
						'provider_code' => $decoded['error']['code'] ?? null,
						'request_id' => ( is_array( $headers ) || $headers instanceof ArrayAccess ) ? ( $headers['x-request-id'] ?? null ) : null,
					)
				);
			}
		}
		if ( is_wp_error( $settled ) || is_wp_error( $response )
			|| ( $known && ( $usage['input_tokens'] > $record['estimated_input_tokens'] || $usage['output_tokens'] > $output ) )
			|| ( is_array( $decoded ) && isset( $decoded['status'] ) && 'completed' !== $decoded['status'] ) ) {
			return $this->failure( true );
		}
		return $response;
	}

	private function failure( $dispatched ) {
		return new WP_Error( 'oras_ai_paid_call_unavailable', __( 'OpenAI processing is unavailable. Please try again later.', 'oras-ai-assistant' ), array( 'dispatched' => $dispatched ) );
	}
}
