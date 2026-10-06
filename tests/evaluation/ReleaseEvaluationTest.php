<?php
declare(strict_types=1);

function oras_ai_eval_library(): void {
	oras_ai_assert_true(is_file(__DIR__ . '/../../tools/evaluation/harness.php'), 'Release evaluation harness is missing.');
	require_once __DIR__ . '/../../tools/evaluation/harness.php';
}

oras_ai_test('M9 evaluation retained corpus validates categories specialized workflows and independent expected dimensions', function (): void {
	oras_ai_eval_library();
	$corpus = ORAS_AI_Release_Evaluation::corpus();
	oras_ai_assert_true(count($corpus['cases']) >= 60 && count($corpus['cases']) <= 100, 'Core corpus is not bounded.');
	foreach (array('knowledge', 'general_astronomy', 'current_astronomy', 'weather', 'member', 'support', 'security', 'partial_failure') as $category) {
		oras_ai_assert_true(in_array($category, array_column($corpus['cases'], 'category'), true), 'Missing category ' . $category);
	}
	foreach (array('answer', 'summary', 'classifier', 'scanner') as $workflow) {
		oras_ai_assert_true(in_array($workflow, array_column($corpus['cases'], 'workflow'), true), 'Missing workflow ' . $workflow);
	}
});

oras_ai_test('M9 evaluation rejects duplicate IDs malformed dimensions and non synthetic data', function (): void {
	oras_ai_eval_library();
	$valid = ORAS_AI_Release_Evaluation::corpus();
	foreach (array('duplicate', 'dimension', 'personal') as $mutation) {
		$corpus = $valid;
		if ('duplicate' === $mutation) { $corpus['cases'][1]['id'] = $corpus['cases'][0]['id']; }
		if ('dimension' === $mutation) { unset($corpus['cases'][0]['expected']['forbidden_claims']); }
		if ('personal' === $mutation) { $corpus['data_class'] = 'production'; }
		$rejected = false;
		try { ORAS_AI_Release_Evaluation::validate($corpus); } catch (InvalidArgumentException $error) { $rejected = true; }
		oras_ai_assert_true($rejected, 'Unsafe corpus accepted: ' . $mutation);
	}
});

oras_ai_test('M9 evaluation hard grader blocks forbidden claims without assigning qualitative scores', function (): void {
	oras_ai_eval_library();
	$case = ORAS_AI_Release_Evaluation::corpus()['cases'][0];
	$case['expected']['forbidden_claims'] = array('fabricated policy');
	$case['expected']['required_facts'] = array();
	$result = array('status' => 'success', 'answer' => 'This fabricated policy is binding.', 'domain' => $case['expected']['domain'], 'sources' => array(), 'calls' => array());
	$grade = ORAS_AI_Release_Evaluation::grade($case, $result);
	oras_ai_assert_same(false, $grade['pass'], 'Hard violation was averaged away.');
	oras_ai_assert_same(null, $grade['quality'], 'Rules invented subjective quality.');
});

oras_ai_test('M9 evaluation retained evidence removes secrets and preserves unknown usage', function (): void {
	oras_ai_eval_library();
	$secret = 'synthetic-private-credential';
	$safe = ORAS_AI_Release_Evaluation::safe_text('Failure ' . $secret . ' sk-abcdefghijklmnopqr Bearer abcdefghijklmnop', array($secret));
	oras_ai_assert_not_contains($secret, $safe, 'Configured credential leaked.');
	oras_ai_assert_not_contains('sk-abcdefghijklmnopqr', $safe, 'Key-shaped output leaked.');
	$call = ORAS_AI_Release_Evaluation::call_record(array('model' => 'gpt-5.6-luna', 'reasoning' => array('effort' => 'low'), 'max_output_tokens' => 128), new WP_Error('private', $secret), 0.5);
	oras_ai_assert_same(null, $call['input_tokens'], 'Unknown usage fabricated.');
	oras_ai_assert_same('transport_failure', $call['status'], 'Failure dropped.');
	oras_ai_assert_same('gpt-5.6-luna', $call['model'], 'Candidate omitted.');
	oras_ai_assert_same('low', $call['reasoning_effort'], 'Reasoning omitted.');
	oras_ai_assert_not_contains($secret, json_encode($call), 'Raw error retained.');
});

oras_ai_test('M9 evaluation provider reported usage incomplete status and cap are recorded', function (): void {
	oras_ai_eval_library();
	$response = array('response' => array('code' => 200), 'body' => json_encode(array('status' => 'incomplete', 'incomplete_details' => array('reason' => 'max_output_tokens'), 'usage' => array('input_tokens' => 321, 'output_tokens' => 128))));
	$call = ORAS_AI_Release_Evaluation::call_record(array('model' => 'gpt-5.6-luna', 'reasoning' => array('effort' => 'low'), 'max_output_tokens' => 128), $response, 1.2);
	oras_ai_assert_same(321, $call['input_tokens'], 'Provider input usage lost.');
	oras_ai_assert_same(128, $call['output_tokens'], 'Provider output usage lost.');
	oras_ai_assert_same(true, $call['truncated'], 'Incomplete provider output hidden.');
});

oras_ai_test('M9 evaluation fixture run is repeatable retains failures and cannot select a model', function (): void {
	oras_ai_eval_library();
	$first = ORAS_AI_Release_Evaluation::fixture_run();
	$second = ORAS_AI_Release_Evaluation::fixture_run();
	oras_ai_assert_same($first['semantic_hash'], $second['semantic_hash'], 'Fixture outcomes are unstable.');
	oras_ai_assert_same(count(ORAS_AI_Release_Evaluation::corpus()['cases']), count($first['results']), 'Case disappeared.');
	oras_ai_assert_same(0, $first['live_calls'], 'Fixture calls called live.');
	oras_ai_assert_same(null, $first['quality'], 'Fixture quality score manufactured.');
	oras_ai_assert_same('NOT_QUALIFIED', $first['recommendation'], 'Fixtures selected production.');
	oras_ai_assert_same(0, $first['failed_cases'], 'Fixture contract failed: ' . json_encode(array_values(array_filter($first['results'], static function ($row) { return !$row['grade']['pass']; }))));
});

oras_ai_test('M9 evaluation live command fails closed without explicit disposable configuration', function (): void {
	oras_ai_eval_library();
	$admission = ORAS_AI_Release_Evaluation::live_options(array('live' => true));
	oras_ai_assert_same(false, $admission['allowed'], 'Missing live opt-in admitted.');
	oras_ai_assert_same('live_configuration_missing', $admission['reason'], 'Wrong missing config status.');
});

oras_ai_test('M9 evaluation live bounds reject excessive calls costs duplicate identities and fixture only selection', function (): void {
	oras_ai_eval_library();
	$base = array('opt_in' => 'I_AUTHORIZE_DISPOSABLE_PAID_EVALUATION', 'bootstrap' => '/tmp/unused/wp-load.php', 'case_ids' => array('A-rings'), 'user_ids' => array(7), 'max_calls' => 2, 'max_microdollars' => 100000);
	foreach (array(array('max_calls' => 101), array('max_microdollars' => 2000001), array('case_ids' => array('A-rings', 'A-rings'), 'user_ids' => array(7, 7)), array('case_ids' => array('D-malformed'))) as $mutation) {
		oras_ai_assert_same(false, ORAS_AI_Release_Evaluation::live_options(array_replace($base, $mutation))['allowed'], 'Unsafe live admission accepted.');
	}
});

oras_ai_test('M9 evaluation sensitive and unsafe canonical evidence fails hard checks', function (): void {
	oras_ai_eval_library();
	$case = ORAS_AI_Release_Evaluation::corpus()['cases'][0];
	$result = array('status' => 'success', 'domain' => 'oras', 'answer' => 'Safe words', 'sources' => array(array('canonical_url' => 'https://evil.example/checkout')), 'side_effects' => 1, 'sensitive_output_detected' => true, 'calls' => array());
	$grade = ORAS_AI_Release_Evaluation::grade($case, $result);
	oras_ai_assert_same(false, $grade['assertions']['canonical_urls'], 'Arbitrary URL passed.');
	oras_ai_assert_same(false, $grade['assertions']['no_side_effect'], 'Side effect passed.');
	oras_ai_assert_same(false, $grade['assertions']['sensitive_output'], 'Secret output passed.');
});

oras_ai_test('M9 evaluation paid plan includes all possible calls tokens and uses only qualified supplied rates', function (): void {
	oras_ai_eval_library();
	oras_ai_assert_true(method_exists(ORAS_AI_Release_Evaluation::class, 'paid_plan'), 'Paid-run upper-bound plan missing.');
	$corpus = ORAS_AI_Release_Evaluation::corpus();
	$rate = array('input_microdollars_per_million_tokens' => 1000000, 'output_microdollars_per_million_tokens' => 3000000);
	$plan = ORAS_AI_Release_Evaluation::paid_plan(array($corpus['cases'][0]), $rate);
	oras_ai_assert_same(2, $plan['maximum_calls'], 'Answer plus potential classifier omitted.');
	oras_ai_assert_same(160048, $plan['maximum_input_tokens'], 'Conservative byte and framing bounds missing.');
	oras_ai_assert_same(928, $plan['maximum_output_tokens'], 'Frozen caps changed.');
	oras_ai_assert_same(162832, $plan['maximum_microdollars'], 'Local maximum cost wrong.');
});

oras_ai_test('M9 evaluation reports metrics by evidence class without inventing latency or usage', function (): void {
	oras_ai_eval_library();
	$report = ORAS_AI_Release_Evaluation::fixture_run();
	oras_ai_assert_true(isset($report['metrics']), 'Configuration metrics missing.');
	oras_ai_assert_same('simulated_not_billing_or_latency_evidence', $report['metrics']['class'], 'Fixture costs or time labeled live.');
	oras_ai_assert_same(null, $report['metrics']['provider_latency']['p95_seconds'], 'Fixture milliseconds treated as provider latency.');
	oras_ai_assert_same(6, $report['metrics']['workflows']['classifier']['cases'], 'Specialized classifier cases dropped.');
	oras_ai_assert_same(5, $report['metrics']['workflows']['scanner']['cases'], 'Scanner cases dropped.');
});

oras_ai_test('M9 evaluation native bootstrap never executes without the explicit disposable marker', function (): void {
	$dir = sys_get_temp_dir() . '/oras-ai-eval-gate-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700);
	file_put_contents($dir . '/wp-load.php', '<?php file_put_contents(__DIR__ . "/EXECUTED", "bad");');
	file_put_contents($dir . '/config.json', json_encode(array('bootstrap' => $dir . '/wp-load.php', 'opt_in' => 'I_AUTHORIZE_DISPOSABLE_PAID_EVALUATION')));
	chmod($dir . '/config.json', 0600);
	$command = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../../tools/release-evaluation.php') . ' --live --config=' . escapeshellarg($dir . '/config.json') . ' --output=' . escapeshellarg($dir . '/evidence') . ' 2>&1';
	exec($command, $output, $status);
	oras_ai_assert_same(2, $status, 'Unmarked bootstrap was admitted.');
	oras_ai_assert_same(false, file_exists($dir . '/EXECUTED'), 'Unmarked WordPress code executed.');
	oras_ai_assert_contains('explicit_disposable_bootstrap_marker_required', implode("\n", $output), 'Wrong bootstrap refusal.');
	foreach (array('/evidence/report.json', '/evidence/events.jsonl', '/wp-load.php', '/config.json') as $path) { if (file_exists($dir . $path)) { unlink($dir . $path); } }
	rmdir($dir . '/evidence'); rmdir($dir);
});

oras_ai_test('M9 review I1 retained member support preview and uncertain state exercise confirmation and renderer', function (): void {
	oras_ai_eval_library();
	$corpus = array_column(ORAS_AI_Release_Evaluation::corpus()['cases'], null, 'id');
	foreach (array('T-proposal-state', 'T-uncertain-state') as $id) {
		oras_ai_assert_true(isset($corpus[$id]), 'Actual member-facing support state is missing: ' . $id);
		oras_ai_assert_same('fixture_only_contract', $corpus[$id]['execution_class'], 'Support state can accidentally run live.');
	}
	$rows = array_column(ORAS_AI_Release_Evaluation::fixture_run()['results'], null, 'id');
	$preview = $rows['T-proposal-state']; $uncertain = $rows['T-uncertain-state'];
	oras_ai_assert_same('awaiting_confirmation', $preview['status'], 'Proposal claimed completion.');
	oras_ai_assert_same(0, $preview['mock_ticket_attempts'], 'Preview created a mock ticket before confirmation.');
	oras_ai_assert_contains('Create Support Ticket', $preview['answer'], 'Confirmation button missing from actual renderer.');
	oras_ai_assert_same('uncertain', $uncertain['status'], 'Uncertain provider result lost.');
	oras_ai_assert_same(1, $uncertain['mock_ticket_attempts'], 'Explicit confirmation attempt missing.');
	oras_ai_assert_same(0, $uncertain['retry_attempts'], 'Uncertain status/confirmation retried.');
	oras_ai_assert_contains('will not retry automatically', $uncertain['answer'], 'Uncertain wording not rendered.');
	oras_ai_assert_same(array(), $uncertain['rendered_buttons'], 'Uncertain state offered a duplicate create button.');
});

oras_ai_test('M9 review M1 maximum cost uses Task 1 component wise fractional rounding', function (): void {
	oras_ai_eval_library();
	$case = array_values(array_filter(ORAS_AI_Release_Evaluation::corpus()['cases'], static function ($c) { return 'summary' === $c['workflow']; }))[0];
	$plan = ORAS_AI_Release_Evaluation::paid_plan(array($case), array('input_microdollars_per_million_tokens' => 1000001, 'output_microdollars_per_million_tokens' => 1000001));
	oras_ai_assert_same(11186, $plan['maximum_microdollars'], 'Maximum understated component-wise rounding.');
});

oras_ai_test('M9 review M2 downstream timeout is one unsuccessful case not a malformed successful classifier', function (): void {
	oras_ai_eval_library();
	$body = array('response' => array('code' => 200), 'body' => json_encode(array('status' => 'completed', 'output_text' => '{"domain":"astronomy"}', 'usage' => array('input_tokens' => 30, 'output_tokens' => 10))));
	$classifier = ORAS_AI_Release_Evaluation::call_record(array('model' => 'gpt-5.6-luna', 'max_output_tokens' => 128), $body, 0.1);
	$failed = ORAS_AI_Release_Evaluation::call_record(array('model' => 'gpt-5.6-luna', 'max_output_tokens' => 800), new WP_Error('http_request_failed', 'Request timed out'), 30.0);
	$metrics = ORAS_AI_Release_Evaluation::metrics(array(array('workflow' => 'answer', 'category' => 'general_astronomy', 'status' => 'failure', 'calls' => array($classifier, $failed), 'grade' => array('pass' => false), 'total_seconds' => 30.2, 'accounted_microdollars' => 100, 'unknown_usage_microdollars' => 60)), 'live');
	oras_ai_assert_same(false, array_key_exists('malformed_or_rejected_outputs', $metrics), 'Per-case failure mislabeled as malformed provider output.');
	oras_ai_assert_same(1, $metrics['unsuccessful_or_rejected_cases'], 'One failed case was counted more than once.');
	oras_ai_assert_same(1, $metrics['provider_failures'], 'Successful classifier counted as provider failure.');
	oras_ai_assert_same(1, $metrics['timeouts'], 'Timeout missing.');
});
