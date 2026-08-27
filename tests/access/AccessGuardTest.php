<?php
declare(strict_types=1);

oras_ai_test('access guard allows member AI execution when globally enabled', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(true);
	oras_ai_assert_true(
		ORAS_AI_Access_Guard::member_ai_execution_allowed(),
		'Enabled member AI setting should pass the global guard.'
	);
});

oras_ai_test('access guard denies member AI execution when globally disabled', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(false);
	oras_ai_assert_false(
		ORAS_AI_Access_Guard::member_ai_execution_allowed(),
		'Disabled member AI setting should fail the global guard.'
	);
});

oras_ai_test('disabled member AI does not block administrative scanner processing', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(false);
	$sourceId = oras_ai_test_add_source('oras_speaker', 'Admin scan source', 'Speaker biography');

	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($sourceId));

	oras_ai_assert_same('static_knowledge', $result['kind'], 'Scanner classification should ignore the member kill switch.');
	oras_ai_assert_same('complete', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Scanner should complete while member AI is disabled.');
});

oras_ai_test('disabled member AI does not block administrative OpenAI classification', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(false);
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'stored-test-key');
	$classification = oras_ai_test_classification();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode($classification))
	);

	$result = ORAS_AI_OpenAI::classify_source('Admin classification', 'https://oras.org/admin-source/', 'page', 'Facts');

	oras_ai_assert_same($classification, $result, 'OpenAI source classification should ignore the member kill switch.');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Admin classification should still call the mocked OpenAI API.');
});
