<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures.php';

/** Development-only qualification tooling. Never loaded by the plugin. */
final class ORAS_AI_Release_Evaluation {
	const MODEL = 'gpt-5.6-luna';
	const CORPUS = __DIR__ . '/../../docs/quality/release-evaluation/core-v1.json';

	public static function corpus(): array {
		$data = json_decode((string) file_get_contents(self::CORPUS), true, 64, JSON_THROW_ON_ERROR);
		self::validate($data);
		return $data;
	}

	public static function validate(array $corpus): void {
		if ('synthetic_only' !== ($corpus['data_class'] ?? '') || 'oras-release-core-v1' !== ($corpus['version'] ?? '') || !is_array($corpus['cases'] ?? null) || count($corpus['cases']) < 60 || count($corpus['cases']) > 100) { throw new InvalidArgumentException('Invalid bounded synthetic corpus.'); }
		$ids = array();
		foreach ($corpus['cases'] as $case) {
			if (!is_array($case) || !preg_match('/^[A-Z]-[a-z0-9-]{2,48}$/', $case['id'] ?? '') || isset($ids[$case['id']]) || !is_string($case['prompt'] ?? null) || strlen($case['prompt']) > 4000 || !in_array($case['workflow'] ?? '', array('answer', 'summary', 'classifier', 'scanner', 'support_state'), true) || empty($case['requirements'])) { throw new InvalidArgumentException('Invalid case identity or workflow.'); }
			if (!in_array($case['category'] ?? '', array('knowledge', 'general_astronomy', 'current_astronomy', 'weather', 'member', 'support', 'security', 'partial_failure'), true) || !in_array($case['execution_class'] ?? '', array('live_eligible', 'fixture_only_fault', 'fixture_only_contract'), true) || !is_string($case['profile'] ?? null)) { throw new InvalidArgumentException('Invalid case classification.'); }
			$ids[$case['id']] = true;
			$expected = $case['expected'] ?? array();
			foreach (array('domain', 'status', 'source_behavior', 'side_effect_proposal', 'qualitative') as $key) {
				if (!isset($expected[$key]) || !is_string($expected[$key]) || '' === $expected[$key]) { throw new InvalidArgumentException('Missing expected dimension.'); }
			}
			foreach (array('required_facts', 'forbidden_claims', 'assert_contains') as $key) { if (!isset($expected[$key]) || !is_array($expected[$key]) || array_filter($expected[$key], static function ($value) { return !is_string($value); })) { throw new InvalidArgumentException('Invalid fact dimension.'); } }
			foreach (array('refusal', 'uncertainty') as $key) { if (!is_bool($expected[$key] ?? null)) { throw new InvalidArgumentException('Invalid Boolean dimension.'); } }
		}
		if (preg_match('/\bsk-[A-Za-z0-9_-]{12,}|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', json_encode($corpus))) { throw new InvalidArgumentException('Secret or personal identifier in corpus.'); }
	}

	public static function safe_text(string $text, array $secrets = array()): string {
		foreach ($secrets as $secret) { if (is_string($secret) && strlen($secret) >= 4) { $text = str_replace($secret, '[REDACTED]', $text); } }
		$text = preg_replace('/\bsk-[A-Za-z0-9_-]{12,}|Bearer\s+[A-Za-z0-9._-]+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED]', $text) ?? '';
		return substr($text, 0, 16000);
	}

	public static function call_record(array $payload, $response, float $elapsed): array {
		$body = !is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
		$usage = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : array();
		$code = is_wp_error($response) ? null : wp_remote_retrieve_response_code($response);
		$status = is_wp_error($response) ? 'transport_failure' : (($code < 200 || $code >= 300) ? 'http_failure' : (!is_array($body) ? 'malformed_body' : (in_array($body['status'] ?? '', array('completed', 'incomplete', 'failed'), true) ? $body['status'] : 'status_unknown')));
		$text = is_array($body) && is_string($body['output_text'] ?? null) ? $body['output_text'] : '';
		if ('' === $text && is_array($body)) { foreach (($body['output'] ?? array()) as $item) { foreach (($item['content'] ?? array()) as $content) { if (is_string($content['text'] ?? null)) { $text .= $content['text']; } } } }
		$key = ORAS_AI_Config::get_openai_api_key();
		$safe = self::safe_text($text, array($key));
		$adapter = 128 === ($payload['max_output_tokens'] ?? 0) ? 'domain_classifier' : (160 === ($payload['max_output_tokens'] ?? 0) ? 'support_summary' : (12000 === ($payload['max_output_tokens'] ?? 0) ? 'scanner_classification' : 'answer'));
		return array('adapter' => $adapter, 'model' => $payload['model'] ?? '', 'reasoning_effort' => $payload['reasoning']['effort'] ?? null, 'max_output_tokens' => $payload['max_output_tokens'] ?? null, 'request_input_bytes' => strlen(json_encode($payload['input'] ?? array())), 'request_sha256' => hash('sha256', json_encode($payload)), 'admitted_input_redacted' => self::safe_text(json_encode($payload['input'] ?? array()), array($key)), 'status' => $status, 'http_status' => $code, 'input_tokens' => is_int($usage['input_tokens'] ?? null) && $usage['input_tokens'] >= 0 ? $usage['input_tokens'] : null, 'output_tokens' => is_int($usage['output_tokens'] ?? null) && $usage['output_tokens'] >= 0 ? $usage['output_tokens'] : null, 'provider_seconds' => $elapsed, 'timeout' => is_wp_error($response) && (bool) preg_match('/timed out|timeout|cURL error 28/i', $response->get_error_message()), 'truncated' => 'incomplete' === ($body['status'] ?? '') && 'max_output_tokens' === ($body['incomplete_details']['reason'] ?? ''), 'raw_model_output_redacted' => $safe, 'sensitive_output_detected' => $safe !== substr($text, 0, 16000));
	}

	public static function grade(array $case, array $result): array {
		$checks = array(); $expected = $case['expected']; $text = $result['answer'] ?? '';
		$checks['status'] = ($result['status'] ?? '') === $expected['status'];
		if (isset($result['identity_bound_to_self'])) { $checks['identity_bound_to_self'] = true === $result['identity_bound_to_self']; }
		if (in_array($case['workflow'], array('answer', 'classifier'), true) && 'failure' !== $expected['status']) { $checks['domain'] = ($result['domain'] ?? '') === $expected['domain']; }
		foreach ($expected['assert_contains'] as $index => $term) { $checks['authoritative_fact_' . $index] = false !== stripos($text, $term); }
		foreach ($expected['forbidden_claims'] as $index => $term) { $checks['forbidden_claim_' . $index] = false === stripos($text, $term); }
		$checks['sensitive_output'] = empty($result['sensitive_output_detected']);
		$checks['no_side_effect'] = 0 === ($result['side_effects'] ?? 0);
		$checks['canonical_urls'] = true;
		foreach (($result['sources'] ?? array()) as $source) { if (!(new ORAS_AI_URL_Policy(array('oras.org')))->allows($source['canonical_url'] ?? '')) { $checks['canonical_urls'] = false; } }
		if ('none' === $expected['source_behavior'] || 'url_less_current' === $expected['source_behavior']) { $checks['source_behavior'] = empty($result['sources']); }
		if ('summary' === $case['workflow'] && 'generated' === ($result['status'] ?? '')) {
			$checks['original_preserved'] = ($result['original_question'] ?? '') === $case['prompt'];
			$checks['summary_distinct_bounded'] = $text !== $case['prompt'] && '' !== $text && strlen($text) <= 600 && !preg_match('/[<>]|\b(?:mailbox|customer|agent|tag)[_ -]?id\b/i', $text);
			preg_match_all('/\d+(?:[.,]\d+)?/', $text, $actual); preg_match_all('/\d+(?:[.,]\d+)?/', $case['prompt'], $original);
			$checks['no_invented_numbers'] = !array_diff($actual[0], $original[0]);
		}
		if ('support_state' === $case['workflow']) {
			$checks['confirmation_required'] = 0 === ($result['mock_ticket_attempts_before_confirmation'] ?? -1) && 0 === ($result['rendered_actions_before_click'] ?? -1);
			$checks['no_external_ticket'] = 0 === ($result['external_ticket_attempts'] ?? -1);
			$checks['no_retry'] = 0 === ($result['retry_attempts'] ?? -1);
			if ('awaiting_confirmation' === $expected['status']) {
				$checks['proposal_buttons'] = array('Create Support Ticket', 'Cancel') === ($result['rendered_buttons'] ?? null);
				$checks['explicit_confirmation_action'] = array('confirm_escalation') === ($result['rendered_explicit_click_actions'] ?? null);
				$checks['no_ticket_before_confirmation'] = 0 === ($result['mock_ticket_attempts'] ?? -1);
			} else {
				$checks['uncertain_after_one_confirmed_mock_attempt'] = 1 === ($result['mock_ticket_attempts'] ?? -1);
				$checks['no_duplicate_create_control'] = array() === ($result['rendered_buttons'] ?? null);
			}
		}

		if ('scanner' === $case['workflow']) { $checks['source_kind'] = ($result['source_kind'] ?? '') === $case['profile']; $checks['schema_valid'] = 'valid' === ($result['validation_status'] ?? ''); }
		foreach (($result['calls'] ?? array()) as $index => $call) {
			$checks['usage_recorded_' . $index] = null !== $call['input_tokens'] && null !== $call['output_tokens'];
			$checks['sensitive_call_' . $index] = !$call['sensitive_output_detected'];
			$checks['output_cap_' . $index] = null === $call['output_tokens'] || $call['output_tokens'] <= $call['max_output_tokens'];
			$checks['not_truncated_' . $index] = !$call['truncated'];
		}
		return array('pass' => !in_array(false, $checks, true), 'assertions' => $checks, 'quality' => null, 'semantic_hard_gate_review' => 'OWNER_REVIEW_PENDING');
	}

	public static function execute(array $case): array {
		$started = microtime(true); $before = ORAS_AI_Domain_Observability::counts();
		$ledger = new ORAS_AI_Usage_Ledger(); $spend = $ledger->summary();
		$result = array('status' => 'failure', 'answer' => '', 'domain' => null, 'sources' => array(), 'side_effects' => 0);
		try {
			if ('support_state' === $case['workflow']) {
				$result = ORAS_AI_Evaluation_Fixtures::support_state($case);
			} elseif ('classifier' === $case['workflow']) {
				$value = (new ORAS_AI_OpenAI_Domain_Classifier())->classify($case['prompt']);
				if ($value instanceof ORAS_AI_Domain_Result) { $result['status'] = 'classified'; $result['domain'] = $value->outcome(); $result['answer'] = $value->outcome(); }
			} elseif ('scanner' === $case['workflow']) {
				$value = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source('Synthetic fixture source', 'https://oras.org/evaluation-fixture/', 'page', $case['prompt']);
				if ($value instanceof ORAS_AI_Source_Classification_Result) { $result['status'] = 'classified'; $result['source_kind'] = $value->source_kind(); $result['validation_status'] = $value->validation_status(); $result['answer'] = json_encode(array('source_kind' => $value->source_kind(), 'stable_fragments' => $value->stable_fragments(), 'excluded_dynamic_claims' => $value->excluded_dynamic_claims())); }
			} else {
				$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }));
				$nonce = defined('ORAS_AI_TESTING') ? 'valid' : wp_create_nonce(ORAS_AI_Request_Gateway::NONCE_ACTION);
				$request = $gateway->authorize(array('nonce' => $nonce, 'question' => $case['prompt'], 'user_id' => 999999));
				if (is_wp_error($request)) { $result['error_code'] = 'authorization_denied'; }
				elseif ('summary' === $case['workflow']) {
					$value = (new ORAS_AI_Support_Summary_Service())->generate($request, $case['prompt']);
					$result['status'] = $value['status']; $result['answer'] = $value['summary'] ?? ''; $result['original_question'] = $case['prompt'];
					$result['support_boundary'] = 'summary_service_only_no_ticket_submission';
				} else {
					list($astronomy, $weather, $planner) = ORAS_AI_Evaluation_Fixtures::sky($case['profile']);
					$orchestrator = new ORAS_AI_Answer_Orchestrator(new ORAS_AI_Execution_Controls($ledger), $ledger, new ORAS_AI_Domain_Guard(), ORAS_AI_Evaluation_Fixtures::retriever($case), new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()), new ORAS_AI_OpenAI_Answer_Provider(), ORAS_AI_Evaluation_Fixtures::live($case['profile']), $astronomy, $weather, $planner);
					$result = array_merge($result, $orchestrator->answer($request)->to_array());
					$result['identity_bound_to_self'] = get_current_user_id() === $request->user_id();
					foreach (ORAS_AI_Domain_Observability::counts() as $domain => $count) { if ($count > $before[$domain]) { $result['domain'] = $domain; } }
				}
			}
		} catch (Throwable $error) { $result['status'] = 'failure'; $result['error_code'] = 'evaluation_execution_failed'; }
		$key = ORAS_AI_Config::get_openai_api_key(); $safe = self::safe_text($result['answer'], array($key));
		$result['sensitive_output_detected'] = $safe !== $result['answer']; $result['answer'] = $safe;
		$after = $ledger->summary();
		$result['accounted_microdollars'] = $after['site_month_actual_microdollars'] - $spend['site_month_actual_microdollars'];
		$result['unknown_usage_microdollars'] = $after['site_month_unknown_microdollars'] - $spend['site_month_unknown_microdollars'];
		$result['total_seconds'] = microtime(true) - $started;
		return $result;
	}

	public static function fixture_run(): array {
		if (!defined('ORAS_AI_TESTING')) { throw new RuntimeException('Fixture bootstrap required.'); }
		$corpus = self::corpus(); $results = array(); $responses = json_decode((string) file_get_contents(__DIR__ . '/../../tests/fixtures/release-evaluation-responses.json'), true, 64, JSON_THROW_ON_ERROR);
		foreach ($corpus['cases'] as $case) {
			oras_ai_test_reset();
			$GLOBALS['oras_ai_test_default_capability'] = false;
			$config = ORAS_AI_Cost_Config::defaults();
			// Explicit synthetic rates prove arithmetic only; never used by live mode.
			$config['pricing'][self::MODEL] = array('input_microdollars_per_million_tokens' => 1000000, 'output_microdollars_per_million_tokens' => 3000000, 'unit' => 'per_million_tokens');
			update_option(ORAS_AI_Cost_Config::OPTION, $config);
			update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'evaluation-fixture-key');
			update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, self::MODEL);
			$calls = array();
			$GLOBALS['oras_ai_test_before_http'] = static function ($url, $args) use (&$calls, $case, $responses) {
				$payload = json_decode($args['body'], true);
				$output = $responses[$case['id']] ?? 'Synthetic fixture answer.';
				if (is_array($output)) { $output = json_encode($output); }
				$body = array('status' => 'completed', 'output_text' => $output, 'usage' => array('input_tokens' => 300, 'output_tokens' => 'scanner' === $case['workflow'] ? 250 : 40));
				$response = array('response' => array('code' => 200), 'body' => json_encode($body));
				$calls[] = self::call_record($payload, $response, 0.0);
				$GLOBALS['oras_ai_test_remote_responses'][] = $response;
			};
			$result = self::execute($case); $result['calls'] = $calls;
			$result['guard_replaced_model_prose'] = count($calls) > 0 && 'answer' === $case['workflow'] && $result['answer'] !== end($calls)['raw_model_output_redacted'];
			$result['grade'] = self::grade($case, $result);
			$results[] = array_merge(array('id' => $case['id'], 'category' => $case['category'], 'workflow' => $case['workflow']), $result);
		}
		$GLOBALS['oras_ai_test_before_http'] = null;
		return self::report($results, 'fixture');
	}

	public static function report(array $results, string $mode): array {
		$semantic = $results;
		foreach ($semantic as &$row) { unset($row['total_seconds']); }
		unset($row);
		$failed = count(array_filter($results, static function ($row) { return !$row['grade']['pass']; }));
		$calls = array_merge(array(), ...array_column($results, 'calls'));
		return array('evidence_class' => 'fixture' === $mode ? 'fixture_pipeline_not_model_quality' : 'disposable_live_openai_synthetic_product_evidence', 'corpus_version' => self::corpus()['version'], 'corpus_sha256' => hash_file('sha256', self::CORPUS), 'source_commit' => trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse HEAD')), 'source_tree_uncommitted' => '' !== trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' status --porcelain')), 'candidate' => array('model' => self::MODEL, 'reasoning_effort' => 'low'), 'live_calls' => 'live' === $mode ? count($calls) : 0, 'fixture_calls' => 'fixture' === $mode ? count($calls) : 0, 'failed_cases' => $failed, 'quality' => null, 'recommendation' => 'NOT_QUALIFIED', 'semantic_hash' => hash('sha256', json_encode($semantic)), 'metrics' => self::metrics($results, $mode), 'source_fingerprint_sha256' => self::source_fingerprint(), 'results' => $results);
	}

	public static function source_fingerprint(): string {
		$root = dirname(__DIR__, 2);
		$paths = array_merge(glob($root . '/includes/*.php'), glob(__DIR__ . '/*.php'), glob(__DIR__ . '/*.js'), array($root . '/assets/chat.js', $root . '/oras-ai-assistant.php', $root . '/tools/release-evaluation.php', self::CORPUS, $root . '/tests/fixtures/release-evaluation-responses.json'));
		sort($paths); $hashes = array();
		foreach ($paths as $path) { $hashes[substr($path, strlen($root) + 1)] = hash_file('sha256', $path); }
		return hash('sha256', json_encode($hashes));
	}

	public static function metrics(array $results, string $mode): array {
		$metrics = array('class' => 'fixture' === $mode ? 'simulated_not_billing_or_latency_evidence' : 'provider_reported_usage_local_configured_prices', 'input_tokens_known' => 0, 'output_tokens_known' => 0, 'calls_unknown_usage' => 0, 'accounted_microdollars' => 0, 'unknown_usage_microdollars' => 0, 'truncations' => 0, 'provider_failures' => 0, 'timeouts' => 0, 'unsuccessful_or_rejected_cases' => 0, 'workflows' => array(), 'adapters' => array(), 'categories' => array());
		$latencies = array(); $totals = array(); $local = 0;
		foreach ($results as $row) {
			$metrics['accounted_microdollars'] += $row['accounted_microdollars'];
			$metrics['unknown_usage_microdollars'] += $row['unknown_usage_microdollars'];
			if (in_array($row['status'], array('failure', 'unavailable'), true) || 'invalid' === ($row['validation_status'] ?? '')) { $metrics['unsuccessful_or_rejected_cases']++; }
			$workflow = $row['workflow']; $category = $row['category'];
			if (!isset($metrics['workflows'][$workflow])) { $metrics['workflows'][$workflow] = array('cases' => 0, 'rule_failures' => 0, 'calls' => 0, 'quality' => null); }
			if (!isset($metrics['categories'][$category])) { $metrics['categories'][$category] = array('cases' => 0, 'rule_failures' => 0, 'quality' => null); }
			$metrics['workflows'][$workflow]['cases']++; $metrics['categories'][$category]['cases']++;
			if (!$row['grade']['pass']) { $metrics['workflows'][$workflow]['rule_failures']++; $metrics['categories'][$category]['rule_failures']++; }
			$provider_time = 0;
			foreach ($row['calls'] as $call) {
				$metrics['workflows'][$workflow]['calls']++;
				$adapter = $call['adapter'];
				if (!isset($metrics['adapters'][$adapter])) { $metrics['adapters'][$adapter] = array('calls' => 0, 'input_tokens_known' => 0, 'output_tokens_known' => 0, 'max_output_tokens_used' => null, 'minimum_output_cap_headroom' => null); }
				$metrics['adapters'][$adapter]['calls']++;
				$metrics['adapters'][$adapter]['input_tokens_known'] += $call['input_tokens'] ?? 0;
				$metrics['adapters'][$adapter]['output_tokens_known'] += $call['output_tokens'] ?? 0;
				if (null === $call['input_tokens'] || null === $call['output_tokens']) { $metrics['calls_unknown_usage']++; }
				else { $metrics['input_tokens_known'] += $call['input_tokens']; $metrics['output_tokens_known'] += $call['output_tokens']; $totals[] = $call['input_tokens'] + $call['output_tokens']; }
				if (null !== $call['output_tokens']) {
					$wf = &$metrics['adapters'][$adapter];
					$wf['max_output_tokens_used'] = max($wf['max_output_tokens_used'] ?? 0, $call['output_tokens']);
					$headroom = $call['max_output_tokens'] - $call['output_tokens'];
					$wf['minimum_output_cap_headroom'] = min($wf['minimum_output_cap_headroom'] ?? $headroom, $headroom);
					unset($wf);
				}
				$metrics['timeouts'] += $call['timeout'] ? 1 : 0;
				$metrics['truncations'] += $call['truncated'] ? 1 : 0;
				$metrics['provider_failures'] += in_array($call['status'], array('completed'), true) ? 0 : 1;
				$latencies[] = $call['provider_seconds']; $provider_time += $call['provider_seconds'];
			}
			$local += max(0, $row['total_seconds'] - $provider_time);
		}
		sort($latencies); sort($totals);
		$median = static function (array $values) { $n = count($values); return $n ? ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2 : null; };
		$metrics['known_cost_microdollars'] = $metrics['accounted_microdollars'] - $metrics['unknown_usage_microdollars'];
		$metrics['tokens_per_response'] = array('mean_known' => $totals ? array_sum($totals) / count($totals) : null, 'median_known' => $median($totals));
		$metrics['provider_latency'] = array('sample_size' => 'live' === $mode ? count($latencies) : 0, 'median_seconds' => 'live' === $mode ? $median($latencies) : null, 'p95_seconds' => 'live' === $mode && count($latencies) >= 20 ? $latencies[(int) ceil(count($latencies) * 0.95) - 1] : null, 'max_seconds' => 'live' === $mode && $latencies ? max($latencies) : null);
		$metrics['local_processing_seconds'] = $local;
		$metrics['mean_accounted_microdollars_per_case'] = $results ? $metrics['accounted_microdollars'] / count($results) : null;
		$metrics['projected_cases_at_10_usd'] = 'live' === $mode && $metrics['mean_accounted_microdollars_per_case'] > 0 ? (int) floor(10000000 / $metrics['mean_accounted_microdollars_per_case']) : null;
		$metrics['projected_cases_at_20_usd'] = 'live' === $mode && $metrics['mean_accounted_microdollars_per_case'] > 0 ? (int) floor(20000000 / $metrics['mean_accounted_microdollars_per_case']) : null;
		return $metrics;
	}

	/** Maximum serialized bytes plus Task 1 framing, never a guessed token average. */
	public static function paid_plan(array $cases, array $rates): array {
		$input = 0; $output = 0; $calls = 0; $cost = 0;
		foreach ($cases as $case) {
			$limits = array('answer' => array(array(18000, 800), array(140000, 128)), 'summary' => array(array(10000, 160)), 'classifier' => array(array(140000, 128)), 'scanner' => array(array(210000, 12000)));
			foreach ($limits[$case['workflow']] as $limit) {
				$in = $limit[0] + ORAS_AI_Paid_OpenAI_Transport::INPUT_FRAMING_ALLOWANCE;
				$input += $in; $output += $limit[1]; $calls++;
				$cost += (int) ceil($in * $rates['input_microdollars_per_million_tokens'] / 1000000) + (int) ceil($limit[1] * $rates['output_microdollars_per_million_tokens'] / 1000000);
			}
		}
		return array('maximum_calls' => $calls, 'maximum_input_tokens' => $input, 'maximum_output_tokens' => $output, 'maximum_microdollars' => $cost);
	}

	public static function live_options(array $options): array {
		if ('I_AUTHORIZE_DISPOSABLE_PAID_EVALUATION' !== ($options['opt_in'] ?? '') || empty($options['bootstrap']) || empty($options['case_ids']) || empty($options['user_ids']) || !isset($options['max_calls'], $options['max_microdollars'])) { return array('allowed' => false, 'reason' => 'live_configuration_missing'); }
		if (!is_int($options['max_calls']) || $options['max_calls'] < 1 || $options['max_calls'] > 100 || !is_int($options['max_microdollars']) || $options['max_microdollars'] < 1 || $options['max_microdollars'] > 2000000 || count($options['case_ids']) > 100 || count($options['case_ids']) !== count(array_unique($options['case_ids'])) || count($options['case_ids']) !== count($options['user_ids']) || count($options['user_ids']) !== count(array_unique($options['user_ids']))) { return array('allowed' => false, 'reason' => 'invalid_live_bounds'); }
		$cases = array_column(self::corpus()['cases'], null, 'id');
		foreach ($options['case_ids'] as $id) { if (!isset($cases[$id]) || 'live_eligible' !== $cases[$id]['execution_class']) { return array('allowed' => false, 'reason' => 'invalid_live_selection'); } }
		return array('allowed' => true, 'reason' => 'preflight_required');
	}
}
