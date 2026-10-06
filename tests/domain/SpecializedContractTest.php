<?php
declare(strict_types=1);

foreach (array(
	'oras' => array('What does an Observer Pass cost?', 'oras'),
	'astronomy' => array('What is a globular cluster?', 'astronomy'),
	'crossover-observing' => array('Is tonight good for observing at ORAS?', 'crossover'),
	'crossover-purchase' => array('Is tonight good for observing at ORAS and can I buy an Observer Pass?', 'crossover'),
	'crossover-planets' => array('What planets can I see tonight at the ORAS observatory?', 'crossover'),
	'crossover-facilities' => array('What telescope facilities does ORAS offer?', 'crossover'),
	'off-topic' => array('What ingredients go in banana bread?', 'off_topic'),
) as $label => $case) {
	oras_ai_test('M9 specialized domain matrix ' . $label, function () use ($case): void {
		oras_ai_test_site_cost_setup();
		// Reproduce the model's dominant-domain choice across several crossover intents.
		$raw = $case[1] === 'crossover' ? 'oras' : $case[1];
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('status' => 'completed', 'output_text' => wp_json_encode(array('domain' => $raw)), 'usage' => array('input_tokens' => 50, 'output_tokens' => 15)));
		$result = (new ORAS_AI_OpenAI_Domain_Classifier())->classify($case[0]);
		oras_ai_assert_true($result instanceof ORAS_AI_Domain_Result, 'Domain result missing.');
		oras_ai_assert_same($case[1], $result->outcome(), 'Valid enum must respect clear existing crossover evidence.');
		oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'No extra model call allowed.');
	});
}
oras_ai_test('M9 specialized domain ambiguous policy stays fail closed', function (): void {
	oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => '{broken', 'usage' => array('input_tokens' => 50, 'output_tokens' => 15)));
	$result = (new ORAS_AI_Domain_Guard())->classify('Can you help me decide?');
	oras_ai_assert_same('ambiguous', $result->outcome(), 'Unresolved request must retain existing ambiguous policy.');
});

foreach (array('static_knowledge', 'live_data', 'mixed', 'mixed-claims-only', 'mixed-types-only', 'ignore', 'review', 'stable-illegal', 'live-illegal', 'mixed-missing', 'unknown') as $label) {
	oras_ai_test('M9 specialized scanner matrix ' . $label, function () use ($label): void {
		oras_ai_test_site_cost_setup();
		$is_mixed_fixture = (str_starts_with($label, 'mixed') && $label !== 'mixed-missing') || in_array($label, array('stable-illegal', 'live-illegal'), true);
		$data = $is_mixed_fixture ? oras_ai_test_mixed_classification() : oras_ai_test_classification();
		if ($label === 'mixed-claims-only') { $data['dynamic_fact_types'] = array(); }
		if ($label === 'mixed-types-only') { $data['excluded_dynamic_claims'] = array(); }
		$data['source_kind'] = array('mixed-claims-only' => 'mixed', 'mixed-types-only' => 'mixed', 'stable-illegal' => 'static_knowledge', 'live-illegal' => 'live_data', 'mixed-missing' => 'mixed', 'unknown' => 'unknown')[$label] ?? $label;
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('status' => 'completed', 'output_text' => wp_json_encode(array('classification' => $data)), 'usage' => array('input_tokens' => 100, 'output_tokens' => 100)));
		$result = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source('Synthetic', 'https://oras.org/fixture/', 'page', 'Synthetic source');
		oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'Scanner result missing.');
		$invalid = in_array($label, array('stable-illegal', 'live-illegal', 'mixed-missing', 'unknown'), true);
		oras_ai_assert_same($invalid ? 'invalid' : 'valid', $result->validation_status(), 'Scanner class invariant changed.');
		oras_ai_assert_same($invalid ? 'review' : $data['source_kind'], $result->source_kind(), 'Contradictions must remain review; valid envelopes must decode.');
		oras_ai_assert_same(1, ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION, 'Extraction version changed.');
	});
}
oras_ai_test('M9 specialized scanner strict schema prevents contradictory class fragments', function (): void {
	oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(oras_ai_test_classification()), 'usage' => array('input_tokens' => 100, 'output_tokens' => 100)));
	ORAS_AI_OpenAI::classify_source('Synthetic', 'https://oras.org/fixture/', 'page', 'Synthetic source');
	$payload = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	$branches = $payload['text']['format']['schema']['properties']['classification']['anyOf'] ?? array();
	oras_ai_assert_same(3, count($branches), 'Provider schema must constrain the mixed versus non-mixed invariant.');
	oras_ai_assert_same(array('mixed'), $branches[0]['properties']['source_kind']['enum'], 'Mixed discriminator missing.');
	oras_ai_assert_same(1, $branches[0]['properties']['stable_fragments']['minItems'] ?? 0, 'Mixed requires durable content.');
	foreach (array('stable_fragments', 'excluded_dynamic_claims', 'dynamic_fact_types') as $field) {
		oras_ai_assert_same(0, $branches[2]['properties'][$field]['maxItems'] ?? -1, 'Non-mixed fragment field must be empty.');
	}
	oras_ai_assert_same(12000, $payload['max_output_tokens'], 'Scanner cap changed.');
});
oras_ai_test('M9 specialized scanner rejects malformed envelope without deleting contradictory data', function (): void {
	oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(array('classification' => oras_ai_test_classification(), 'unexpected' => 'extra'))));
	oras_ai_assert_wp_error(ORAS_AI_OpenAI::classify_source('Synthetic', 'https://oras.org/fixture/', 'page', 'Synthetic source'), 'oras_ai_invalid_json', 'Malformed envelope must not be normalized by dropping extra data.');
});
