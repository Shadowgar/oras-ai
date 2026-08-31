<?php
declare(strict_types=1);

function oras_ai_test_classification(array $overrides = array()): array {
	return array_merge(
		array(
			'source_kind' => 'static_knowledge',
			'category' => 'General FAQ',
			'visibility' => 'public',
			'confidence' => 'high',
			'knowledge_title' => 'Stable facts',
			'reason' => 'The source contains stable information.',
			'historical_event' => false,
			'stable_fragments' => array(),
			'excluded_dynamic_claims' => array(),
			'dynamic_fact_types' => array(),
			'validation' => array(
				'stable_dynamic_separation' => true,
				'critical_qualifications_preserved' => true,
			),
		),
		$overrides
	);
}

function oras_ai_test_http_response(int $code, array $body): array {
	return array(
		'response' => array('code' => $code),
		'body' => wp_json_encode($body),
	);
}

oras_ai_test('OpenAI model uses the v0.2.1 default and allowlist', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_same('gpt-5.6-luna', ORAS_AI_OpenAI::get_model(), 'Default model changed.');
	update_option(ORAS_AI_OpenAI::OPTION_MODEL, 'gpt-5.6-sol');
	oras_ai_assert_same('gpt-5.6-sol', ORAS_AI_OpenAI::get_model(), 'Allowed Sol model should be preserved.');
	update_option(ORAS_AI_OpenAI::OPTION_MODEL, 'gpt-unapproved');
	oras_ai_assert_same('gpt-5.6-luna', ORAS_AI_OpenAI::get_model(), 'Unknown model should fall back to Luna.');
});

oras_ai_test('OpenAI classifier returns no-key error without making HTTP request', function (): void {
	oras_ai_test_reset();
	$result = ORAS_AI_OpenAI::classify_source('Title', 'https://oras.org/page/', 'page', 'Content');
	oras_ai_assert_wp_error($result, 'oras_ai_no_key', 'Missing API key behavior changed.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Missing-key classification must not attempt HTTP.');
});

oras_ai_test('OpenAI classifier accepts direct output_text structured JSON', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$classification = oras_ai_test_classification();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode($classification))
	);

	$result = ORAS_AI_OpenAI::classify_source('Stable facts', 'https://oras.org/facts/', 'page', 'Facts');

	oras_ai_assert_same($classification, $result, 'Direct output_text extraction changed.');
	oras_ai_assert_same('https://api.openai.com/v1/responses', $GLOBALS['oras_ai_test_remote_calls'][0]['url'], 'Responses endpoint changed.');
	$request = $GLOBALS['oras_ai_test_remote_calls'][0]['args'];
	oras_ai_assert_same('Bearer stored-test-key', $request['headers']['Authorization'], 'Stored key authorization changed.');
	$payload = json_decode($request['body'], true);
	oras_ai_assert_same('gpt-5.6-luna', $payload['model'], 'Default request model changed.');
	oras_ai_assert_same('json_schema', $payload['text']['format']['type'], 'Structured output request changed.');
});

oras_ai_test('OpenAI structured schema exposes exactly five outcomes and mixed extraction fields', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode(oras_ai_test_mixed_classification()))
	);

	ORAS_AI_OpenAI::classify_source('AstroBlast', 'https://oras.org/astroblast/', 'page', 'Mixed content');

	$payload = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	$schema = $payload['text']['format']['schema'];
	oras_ai_assert_same(
		array('static_knowledge', 'live_data', 'mixed', 'ignore', 'review'),
		$schema['properties']['source_kind']['enum'],
		'OpenAI schema source dispositions changed.'
	);
	foreach (array('historical_event', 'stable_fragments', 'excluded_dynamic_claims', 'dynamic_fact_types', 'validation') as $field) {
		oras_ai_assert_true(isset($schema['properties'][$field]), "OpenAI schema must define {$field}.");
		oras_ai_assert_true(in_array($field, $schema['required'], true), "OpenAI schema must require {$field}.");
	}
});

oras_ai_test('OpenAI policy prompt keeps legitimate ORAS privacy and security pages eligible', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode(oras_ai_test_classification(array('category' => 'Policies & Rules'))))
	);

	ORAS_AI_OpenAI::classify_source(
		'ORAS Privacy Policy',
		'https://oras.org/privacy-policy/',
		'page',
		'ORAS explains how member contact data is handled.'
	);

	$payload = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	$system = $payload['input'][0]['content'];
	oras_ai_assert_contains('Public ORAS privacy and website-security policy pages are eligible searchable knowledge under Policies & Rules', $system, 'ORAS policy eligibility instruction is missing.');
	oras_ai_assert_not_contains('legal/privacy/cookie content', $system, 'Legacy blanket privacy/legal ignore instruction must be removed.');
	oras_ai_assert_contains('third-party legal, privacy, or cookie boilerplate', $system, 'Third-party boilerplate safeguard is missing.');
});

oras_ai_test('OpenAI policy prompt represents historical ORAS events as durable event knowledge', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode(oras_ai_test_classification(array('category' => 'Events'))))
	);

	ORAS_AI_OpenAI::classify_source(
		'AstroBlast 2018 Archive',
		'https://oras.org/astroblast-2018/',
		'page',
		'An archival description of speakers and activities.'
	);

	$payload = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	$system = $payload['input'][0]['content'];
	oras_ai_assert_contains('Historical ORAS event pages with archival value use static_knowledge in Events', $system, 'Historical-event ingestion instruction is missing.');
	oras_ai_assert_contains('must not preserve current dates, prices, deadlines, schedules, or availability as durable facts', $system, 'Historical current-fact safeguard is missing.');
});

oras_ai_test('OpenAI classifier accepts nested output content text', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$classification = oras_ai_test_classification(array('source_kind' => 'review'));
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array(
			'output' => array(
				array(
					'content' => array(
						array('type' => 'refusal', 'text' => 'ignored'),
						array('type' => 'output_text', 'text' => '  ' . wp_json_encode($classification) . '  '),
					),
				),
			),
		)
	);

	$result = ORAS_AI_OpenAI::classify_source('Mixed', 'https://oras.org/mixed/', 'page', 'Mixed content');
	oras_ai_assert_same($classification, $result, 'Nested output text extraction changed.');
});

oras_ai_test('OpenAI classifier rejects malformed JSON output', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => '{broken'));
	$result = ORAS_AI_OpenAI::classify_source('Title', 'https://oras.org/page/', 'page', 'Content');
	oras_ai_assert_wp_error($result, 'oras_ai_invalid_json', 'Malformed JSON handling changed.');
});

oras_ai_test('OpenAI classifier rejects an empty successful response', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output' => array()));
	$result = ORAS_AI_OpenAI::classify_source('Title', 'https://oras.org/page/', 'page', 'Content');
	oras_ai_assert_wp_error($result, 'oras_ai_empty_response', 'Empty response handling changed.');
});

oras_ai_test('OpenAI classifier returns WordPress HTTP errors unchanged', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$httpError = new WP_Error('http_request_failed', 'Connection unavailable.');
	$GLOBALS['oras_ai_test_remote_responses'][] = $httpError;
	$result = ORAS_AI_OpenAI::classify_source('Title', 'https://oras.org/page/', 'page', 'Content');
	oras_ai_assert_same($httpError, $result, 'WordPress HTTP error should pass through unchanged.');
});

oras_ai_test('OpenAI classifier maps non-success responses to the current HTTP error', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		429,
		array('error' => array('message' => 'Rate limit reached.'))
	);
	$result = ORAS_AI_OpenAI::classify_source('Title', 'https://oras.org/page/', 'page', 'Content');
	oras_ai_assert_wp_error($result, 'oras_ai_openai_http', 'Non-success HTTP handling changed.');
	oras_ai_assert_same('Rate limit reached.', $result->get_error_message(), 'API error message extraction changed.');
	oras_ai_assert_same(array('status' => 429), $result->get_error_data(), 'HTTP status error data changed.');
});

oras_ai_test('OpenAI API key constant takes precedence over stored option', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-option-key');
	if (!defined('ORAS_AI_OPENAI_API_KEY')) {
		define('ORAS_AI_OPENAI_API_KEY', '  constant-key  ');
	}
	oras_ai_assert_same('constant-key', ORAS_AI_Config::get_openai_api_key(), 'Config constant precedence changed.');
	oras_ai_assert_same('constant-key', ORAS_AI_OpenAI::get_api_key(), 'API-key constant precedence changed.');
});
