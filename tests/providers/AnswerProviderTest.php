<?php
declare(strict_types=1);

oras_ai_test('SUP-004 OpenAI summary request contains only bounded question and no tools', function (): void {
	oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response( 200, array(
		'output_text' => '{"summary":"The member needs clarification about renewal."}',
		'usage' => array( 'input_tokens' => 31, 'output_tokens' => 12 ),
	) );
	$provider = new ORAS_AI_OpenAI_Answer_Provider(
		static function (): string { return 'stored-answer-key'; },
		static function (): string { return 'gpt-5.6-luna'; }
	);
	$result = $provider->summarize_support_question( 'What are the ORAS renewal rules?', 160, 10 );
	oras_ai_assert_true( $result->successful(), 'Summary provider failed.' );
	$call = $GLOBALS['oras_ai_test_remote_calls'][0];
	$payload = json_decode( $call['args']['body'], true );
	oras_ai_assert_same( 'gpt-5.6-luna', $payload['model'], 'Configured model was bypassed.' );
	oras_ai_assert_same( 160, $payload['max_output_tokens'], 'Output bound was bypassed.' );
	oras_ai_assert_same( 10, $call['args']['timeout'], 'Timeout bound was bypassed.' );
	oras_ai_assert_false( isset( $payload['tools'] ), 'Summary request exposed tools.' );
	oras_ai_assert_same( 'json_schema', $payload['text']['format']['type'] ?? '', 'Summary output lacked structured schema.' );
	oras_ai_assert_same( false, $payload['text']['format']['schema']['additionalProperties'] ?? null, 'Summary schema allowed untrusted fields.' );
	oras_ai_assert_same( array( 'summary' ), $payload['text']['format']['schema']['required'] ?? array(), 'Summary schema required other fields.' );
	oras_ai_assert_same( array( 'system', 'user' ), array_column( $payload['input'], 'role' ), 'Summary included other context.' );
	oras_ai_assert_same( 'What are the ORAS renewal rules?', $payload['input'][1]['content'], 'Question input changed.' );
	oras_ai_assert_not_contains( 'mailbox_id', wp_json_encode( $payload ), 'Provider IDs leaked into summary request.' );
});

oras_ai_test('SUP-004 OpenAI summary rejects HTML and malformed provider output', function (): void {
	foreach ( array(
		oras_ai_test_http_response( 200, array( 'output_text' => '{"summary":"<b>HTML summary</b>"}', 'usage' => array( 'input_tokens' => 31, 'output_tokens' => 12 ) ) ),
		oras_ai_test_http_response( 200, array( 'output_text' => '{"summary":""}', 'usage' => array( 'input_tokens' => 31, 'output_tokens' => 12 ) ) ),
		oras_ai_test_http_response( 200, array( 'output_text' => '{"summary":"Valid.","mailbox_id":999}', 'usage' => array( 'input_tokens' => 31, 'output_tokens' => 12 ) ) ),
		oras_ai_test_http_response( 200, array( 'output_text' => 'plain text outside schema', 'usage' => array( 'input_tokens' => 31, 'output_tokens' => 12 ) ) ),
		new WP_Error( 'timeout', 'private detail' ),
	) as $response ) {
		oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
		$GLOBALS['oras_ai_test_remote_responses'][] = $response;
		$provider = new ORAS_AI_OpenAI_Answer_Provider( static function (): string { return 'stored-answer-key'; } );
		$result = $provider->summarize_support_question( 'What are the ORAS renewal rules?', 160, 10 );
		oras_ai_assert_false( $result->successful(), 'Malformed summary accepted.' );
	}
});

function oras_ai_test_provider_context(): ORAS_AI_Grounded_Context {
	$request = oras_ai_test_authorized_request(301, 'How does ORAS observatory access work?');
	$guarded = new ORAS_AI_Guarded_Request($request, ORAS_AI_Domain_Result::from_outcome(ORAS_AI_Domain_Result::ORAS));
	return (new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()))->assemble(
		$guarded,
		new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence()))
	);
}

oras_ai_test('OpenAI answer adapter sends separated bounded context and normalizes valid usage', function (): void {
	oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'stored-answer-key');
	update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, 'gpt-5.6-terra');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array(
			'output_text' => 'Grounded plain answer.',
			'usage' => array('input_tokens' => 123, 'output_tokens' => 45),
		)
	);
	$adapter = new ORAS_AI_OpenAI_Answer_Provider(
		static function (): string { return 'stored-answer-key'; },
		static function (): string { return 'gpt-5.6-terra'; }
	);

	$result = $adapter->answer(oras_ai_test_provider_context(), 321, 17);

	oras_ai_assert_true($adapter instanceof ORAS_AI_Answer_Provider_Interface, 'OpenAI answer adapter must implement provider boundary.');
	oras_ai_assert_true($result->successful(), 'Valid OpenAI answer should normalize successfully.');
	oras_ai_assert_same('gpt-5.6-terra', $result->model(), 'Configured answer model changed.');
	oras_ai_assert_same(123, $result->input_tokens(), 'Provider input usage normalization changed.');
	oras_ai_assert_same(45, $result->output_tokens(), 'Provider output usage normalization changed.');
	$call = $GLOBALS['oras_ai_test_remote_calls'][0];
	oras_ai_assert_same('https://api.openai.com/v1/responses', $call['url'], 'Answer provider endpoint changed.');
	oras_ai_assert_same(17, $call['args']['timeout'], 'Execution timeout was not applied to HTTP request.');
	oras_ai_assert_same('Bearer stored-answer-key', $call['args']['headers']['Authorization'], 'Server-side key handling changed.');
	$payload = json_decode($call['args']['body'], true);
	oras_ai_assert_same('gpt-5.6-terra', $payload['model'], 'Configured model was not used.');
	oras_ai_assert_same(321, $payload['max_output_tokens'], 'Output token cap missing from provider request.');
	oras_ai_assert_same(array('system', 'user', 'user'), array_column($payload['input'], 'role'), 'System member and evidence content must stay structurally separate.');
	oras_ai_assert_false(isset($payload['tools']), 'Task 5 answer request must expose no tools.');
});

oras_ai_test('OpenAI answer adapter missing key fails before dispatch', function (): void {
	oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
	$adapter = new ORAS_AI_OpenAI_Answer_Provider(static function (): string { return ''; });
	$result = $adapter->answer(oras_ai_test_provider_context(), 100, 10);

	oras_ai_assert_false($result->successful(), 'Missing-key answer must fail.');
	oras_ai_assert_false($result->usage_may_have_occurred(), 'No-dispatch failure must be releasable.');
	oras_ai_assert_same('provider_unavailable', $result->error_code(), 'Missing-key error must remain safe.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Missing key must stop before HTTP.');
});

oras_ai_test('OpenAI answer adapter treats transport HTTP malformed empty and usage failures safely', function (): void {
	$cases = array(
		new WP_Error('http_request_failed', 'Internal transport detail'),
		oras_ai_test_http_response(500, array('error' => array('message' => 'Raw provider detail'))),
		oras_ai_test_http_response(200, array('output_text' => '<b>Answer without usage</b>')),
		oras_ai_test_http_response(200, array('output_text' => '', 'usage' => array('input_tokens' => 1, 'output_tokens' => 1))),
		array('response' => array('code' => 200), 'body' => '{broken'),
	);

	foreach ($cases as $response) {
		oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
		update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'stored-answer-key');
		$GLOBALS['oras_ai_test_remote_responses'][] = $response;
		$result = (new ORAS_AI_OpenAI_Answer_Provider())->answer(oras_ai_test_provider_context(), 100, 10);
		oras_ai_assert_false($result->successful(), 'Malformed provider case must fail safely.');
		oras_ai_assert_true($result->usage_may_have_occurred(), 'Post-dispatch failure must retain conservative accounting.');
		oras_ai_assert_same('provider_response_invalid', $result->error_code(), 'Raw provider error details must be normalized.');
		oras_ai_assert_not_contains('Raw provider detail', wp_json_encode($result->to_array()), 'Raw provider detail leaked.');
		oras_ai_assert_not_contains('Internal transport detail', wp_json_encode($result->to_array()), 'Transport detail leaked.');
	}
});
