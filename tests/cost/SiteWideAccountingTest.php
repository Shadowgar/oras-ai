<?php
declare(strict_types=1);

function oras_ai_test_configure_paid_prices(): void {
	$config = oras_ai_test_execution_config();
	$config['pricing']['gpt-5.6-terra'] = $config['pricing']['gpt-5.6-luna'];
	update_option(ORAS_AI_Cost_Config::OPTION, $config);
}
function oras_ai_test_site_cost_setup(): ORAS_AI_Usage_Ledger {
	oras_ai_test_reset();
	update_option(ORAS_AI_Cost_Config::OPTION, oras_ai_test_execution_config());
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'secret-accounting-fixture');
	return new ORAS_AI_Usage_Ledger();
}
function oras_ai_test_paid_family(string $family) {
	if ('domain_classifier' === $family) {
		return (new ORAS_AI_OpenAI_Domain_Classifier())->classify('Could you explain this private question?');
	}
	if ('scanner_classification' === $family) {
		return ORAS_AI_OpenAI::classify_source('Private source title', 'https://oras.org/facts/', 'page', 'Private scanner source body');
	}
	$provider = new ORAS_AI_OpenAI_Answer_Provider();
	if ('support_summary' === $family) {
		return $provider->summarize_support_question('Private support question', 160, 10);
	}
	$context = (new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()))->assemble(
		new ORAS_AI_Guarded_Request(oras_ai_test_authorized_request(7, 'Why are stars bright?'), ORAS_AI_Domain_Result::from_outcome('astronomy')),
		new ORAS_AI_Evidence_Packet(), ORAS_AI_Retrieval_Request::INTENT_GENERAL, ORAS_AI_Grounded_Context::GENERAL_ASTRONOMY
	);
	return $provider->answer($context, 800, 30);
}
function oras_ai_test_paid_body(string $family): array {
	$text = 'A bounded astronomy explanation.';
	if ('domain_classifier' === $family) { $text = '{"domain":"astronomy"}'; }
	if ('support_summary' === $family) { $text = '{"summary":"The member needs help with membership renewal."}'; }
	if ('scanner_classification' === $family) { $text = wp_json_encode(oras_ai_test_classification()); }
	return array('status' => 'completed', 'output_text' => $text, 'usage' => array('input_tokens' => 100, 'output_tokens' => 20));
}
function oras_ai_test_seed_spend(ORAS_AI_Usage_Ledger $ledger, int $cost): void {
	$admission = $ledger->reserve(9, 'gpt-5.6-luna', 1, oras_ai_test_execution_config());
	$ledger->reconcile($admission->reservation_id(), 'gpt-5.6-luna', $cost, 0);
}

foreach (array('answer' => array(800, 30), 'support_summary' => array(160, 10), 'domain_classifier' => array(128, 20), 'scanner_classification' => array(12000, 60)) as $family => $bounds) {
	oras_ai_test('M9 ' . $family . ' reserves before HTTP and reconciles provider usage', function () use ($family, $bounds): void {
		$ledger = oras_ai_test_site_cost_setup();
		$GLOBALS['oras_ai_test_before_http'] = static function ($url, $args) use ($ledger, $family, $bounds): void {
			$records = array_values(get_option(ORAS_AI_Usage_Ledger::OPTION, array())['reservations'] ?? array());
			oras_ai_assert_same(1, count($records), 'Paid HTTP had no unique durable reservation.');
			oras_ai_assert_same($family, $records[0]['source'] ?? '', 'Cost source missing.');
			oras_ai_assert_same('dispatched', $records[0]['status'], 'Reservation was not claimed before HTTP.');
			oras_ai_assert_true($records[0]['estimated_input_tokens'] >= strlen($args['body']), 'Serialized schema/input was under-reserved.');
			oras_ai_assert_same($bounds[0], json_decode($args['body'], true)['max_output_tokens'] ?? 0, 'Missing bounded output.');
			oras_ai_assert_same($bounds[1], $args['timeout'], 'Timeout changed.');
		};
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body($family));
		$result = oras_ai_test_paid_family($family);
		if (in_array($family, array('answer', 'support_summary'), true)) { oras_ai_assert_true($result->successful(), 'Qualified answer/summary failed.'); }
		elseif ('domain_classifier' === $family) { oras_ai_assert_true($result instanceof ORAS_AI_Domain_Result, 'Qualified domain failed.'); }
		else { oras_ai_assert_same(oras_ai_test_classification(), $result, 'Qualified scanner failed.'); }
		oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Qualified call did not dispatch exactly once.');
		oras_ai_assert_same(160, $ledger->summary()['site_month_actual_microdollars'], 'Reported tokens did not reconcile.');
		oras_ai_assert_same(0, $ledger->summary()['site_month_reserved_microdollars'], 'Unused reservation not released.');
		oras_ai_assert_same(0, $ledger->summary(7)['member_day_allowed'], 'Direct paid adapters are cost-only.');
	});
	oras_ai_test('M9 ' . $family . ' denies absent pricing invalid model and at or over hard stop', function () use ($family): void {
		foreach (array('missing_price', 'invalid_model', 20000000, 20000001) as $case) {
			$ledger = oras_ai_test_site_cost_setup();
			if ('missing_price' === $case) { delete_option(ORAS_AI_Cost_Config::OPTION); }
			elseif ('invalid_model' === $case) { update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, 'not-approved'); }
			else { oras_ai_test_seed_spend($ledger, $case); }
			oras_ai_test_paid_family($family);
			oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Denied ' . $family . ' dispatched: ' . $case);
		}
	});
	oras_ai_test('M9 ' . $family . ' retains unknown spend without inventing tokens', function () use ($family): void {
		foreach (array(new WP_Error('timeout', 'private transport detail'), oras_ai_test_http_response(200, array('output_text' => 'malformed')), oras_ai_test_http_response(503, array())) as $response) {
			$ledger = oras_ai_test_site_cost_setup();
			$GLOBALS['oras_ai_test_remote_responses'][] = $response;
			oras_ai_test_paid_family($family);
			$record = $ledger->reservation('oras-ai-0000000001');
			oras_ai_assert_same('usage_unknown', $record['status'] ?? '', 'Possibly paid failure was released or presented as measured.');
			oras_ai_assert_same(null, $record['actual_input_tokens'], 'Unknown input tokens were fabricated.');
			oras_ai_assert_same(null, $record['actual_output_tokens'], 'Unknown output tokens were fabricated.');
			oras_ai_assert_true($ledger->summary()['site_month_actual_microdollars'] > 0, 'Conservative spend was lost.');
			$before = $ledger->summary();
			$ledger->release($record['id']);
			$ledger->settle_reserved_maximum($record['id']);
			oras_ai_assert_same($before, $ledger->summary(), 'Settlement/release changed already dispatched exposure.');
		}
	});
	oras_ai_test('M9 ' . $family . ' retains known usage even on malformed incomplete and HTTP-error output', function () use ($family): void {
		foreach (array(200, 429, 500) as $status) {
			$ledger = oras_ai_test_site_cost_setup();
			$body = array('status' => 'incomplete', 'output_text' => '{broken', 'usage' => array('input_tokens' => 70, 'output_tokens' => 30));
			$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response($status, $body);
			oras_ai_test_paid_family($family);
			oras_ai_assert_same(160, $ledger->summary()['site_month_actual_microdollars'], 'Known usage discarded when output unusable.');
			oras_ai_assert_same(70, $ledger->reservation('oras-ai-0000000001')['actual_input_tokens'], 'Known input tokens lost.');
		}
	});
	oras_ai_test('M9 ' . $family . ' cannot bypass outstanding headroom or failed durable storage', function () use ($family): void {
		$ledger = oras_ai_test_site_cost_setup();
		oras_ai_test_seed_spend($ledger, 19995000);
		$open = $ledger->reserve(8, 'gpt-5.6-luna', 2500, oras_ai_test_execution_config());
		oras_ai_assert_true($open->allowed(), 'Headroom fixture failed.');
		oras_ai_test_paid_family($family);
		oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Outstanding reservation was ignored.');
		$ledger = oras_ai_test_site_cost_setup();
		$GLOBALS['oras_ai_test_fail_option_write'] = ORAS_AI_Usage_Ledger::OPTION;
		oras_ai_test_paid_family($family);
		oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Failed reservation persistence still dispatched.');
	});
	oras_ai_test('M9 ' . $family . ' usage records contain no private request or response data', function () use ($family): void {
		$ledger = oras_ai_test_site_cost_setup();
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body($family));
		oras_ai_test_paid_family($family);
		$record = $ledger->reservation('oras-ai-0000000001');
		oras_ai_assert_true(is_array($record), 'No cost record exists.');
		$serialized = wp_json_encode(get_option(ORAS_AI_Usage_Ledger::OPTION));
		foreach (array('Private', 'secret-accounting-fixture', 'output_text', 'provider_response', 'prompt', 'bright', 'bounded astronomy explanation', 'SOURCE TITLE') as $private) {
			oras_ai_assert_not_contains($private, $serialized, 'Ledger stored private content.');
		}
	});
}

oras_ai_test('M9 support summary does not consume another member question or burst slot', function (): void {
	list($service, $provider, $ledger) = oras_ai_test_summary_fixture(null, array('daily_quota' => 1, 'monthly_quota' => 1, 'burst_per_minute' => 1));
	$controls = new ORAS_AI_Execution_Controls($ledger, oras_ai_test_execution_config(array('daily_quota' => 1, 'monthly_quota' => 1, 'burst_per_minute' => 1)));
	$request = oras_ai_test_authorized_request(7, 'How does ORAS membership renewal work?');
	$first = $controls->admit($request, 'gpt-5.6-luna');
	$ledger->reconcile($first->reservation_id(), 'gpt-5.6-luna', 100, 20);
	oras_ai_assert_same('generated', $service->generate($request, $request->question())['status'], 'Auxiliary summary incorrectly reused question limits.');
	oras_ai_assert_same(1, $ledger->summary(7)['member_day_allowed'], 'Summary incremented questions.');
});

oras_ai_test('M9 domain and scanner contribute to warning and bounded admin breakdown', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	oras_ai_test_seed_spend($ledger, 9999680);
	foreach (array('domain_classifier', 'scanner_classification') as $family) {
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body($family));
		oras_ai_test_paid_family($family);
	}
	oras_ai_assert_true($ledger->budget_state(ORAS_AI_Cost_Config::get())['warning'], 'Auxiliary spend did not activate $10 warning.');
	ob_start(); (new ORAS_AI_Cost_Admin($ledger))->render_page(); $html = ob_get_clean();
	oras_ai_assert_contains('domain_classifier', $html, 'Operator cannot see classifier spend source.');
	oras_ai_assert_contains('scanner_classification', $html, 'Operator cannot see scanner spend source.');
});

oras_ai_test('M9 known over-reservation usage is preserved and blocks subsequent spend', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$body = oras_ai_test_paid_body('domain_classifier');
	$body['usage'] = array('input_tokens' => 20000001, 'output_tokens' => 5);
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
	$result = oras_ai_test_paid_family('domain_classifier');
	oras_ai_assert_true(is_wp_error($result), 'Unexpected provider overrun must fail safely.');
	oras_ai_assert_same(20000016, $ledger->summary()['site_month_actual_microdollars'], 'Known excess usage was capped away.');
	oras_ai_test_paid_family('scanner_classification');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Overrun failed to stop future calls.');
});

oras_ai_test('M9 local oversized domain and scanner inputs never reserve paid spend', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	(new ORAS_AI_OpenAI_Domain_Classifier())->classify(str_repeat('x', 4001));
	ORAS_AI_OpenAI::classify_source(str_repeat('x', 1001), 'https://oras.org/', 'page', 'facts');
	ORAS_AI_OpenAI::classify_source('Title', str_repeat('x', 2049), 'page', 'facts');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Oversized input reached provider.');
	oras_ai_assert_same(0, $ledger->summary()['site_month_actual_microdollars'], 'Local invalid inputs consumed spend.');
	oras_ai_assert_same(0, $ledger->summary()['site_month_reserved_microdollars'], 'Local invalid inputs stranded reservation.');
});

oras_ai_test('M9 stale ledger lock cannot be taken over with a racy delete', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	update_option(ORAS_AI_Usage_Ledger::LOCK_OPTION, array('token' => 'other-writer', 'acquired_at' => time() - 90));
	oras_ai_test_paid_family('domain_classifier');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Stale lock takeover permitted a concurrent writer.');
	oras_ai_assert_same('other-writer', get_option(ORAS_AI_Usage_Ledger::LOCK_OPTION)['token'], 'Unowned lock was deleted.');
});

oras_ai_test('M9 every production OpenAI endpoint and POST dispatch belongs to the qualified seam', function (): void {
	$endpoints = array(); $dispatches = array();
	$root = dirname(__DIR__, 2);
	$files = array($root . '/oras-ai-assistant.php');
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) { if ('php' === $file->getExtension()) { $files[] = $file->getPathname(); } }
	$network_functions = array('wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request', 'curl_exec', 'curl_multi_exec', 'file_get_contents', 'fopen', 'fsockopen', 'stream_socket_client');
	foreach ($files as $file) {
		foreach (token_get_all(file_get_contents($file)) as $token) {
			if (!is_array($token)) { continue; }
			if (T_CONSTANT_ENCAPSED_STRING === $token[0] && str_contains($token[1], 'api.openai.com')) { $endpoints[] = basename($file); }
			if (in_array($token[0], array(T_STRING, T_CONSTANT_ENCAPSED_STRING), true)) {
				$name = strtolower(trim($token[1], "'\""));
				if (in_array($name, $network_functions, true)) { $dispatches[] = basename($file) . ':' . $name; }
			}
		}
	}
	sort($endpoints); sort($dispatches);
	oras_ai_assert_same(array('class-oras-ai-paid-openai-transport.php'), $endpoints, 'New OpenAI endpoint bypasses qualified seam.');
	oras_ai_assert_same(array('class-oras-ai-astronomy-api-provider.php:wp_remote_get', 'class-oras-ai-nws-weather-provider.php:wp_remote_get', 'class-oras-ai-paid-openai-transport.php:wp_remote_post'), $dispatches, 'New network dispatch requires explicit qualification.');
});

function oras_ai_test_real_orchestrator(ORAS_AI_Usage_Ledger $ledger): ORAS_AI_Answer_Orchestrator {
	return new ORAS_AI_Answer_Orchestrator(
		new ORAS_AI_Execution_Controls($ledger), $ledger, new ORAS_AI_Domain_Guard(),
		oras_ai_test_answer_retriever(new ORAS_AI_Evidence_Packet()),
		new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()), new ORAS_AI_OpenAI_Answer_Provider()
	);
}

oras_ai_test('M9 ambiguous member question accounts classifier and answer separately without extra question', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	foreach (array('domain_classifier', 'answer') as $family) {
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body($family));
	}
	$result = oras_ai_test_real_orchestrator($ledger)->answer(oras_ai_test_authorized_request(7, 'Can you explain what I saw?'));
	oras_ai_assert_same('success', $result->status(), 'Allowed classifier should reach answer.');
	oras_ai_assert_same(2, count($GLOBALS['oras_ai_test_remote_calls']), 'Both paid calls must dispatch once.');
	oras_ai_assert_same(320, $ledger->summary()['site_month_actual_microdollars'], 'Classifier or answer spend lost.');
	oras_ai_assert_same(1, $ledger->summary(7)['member_day_allowed'], 'One question counted more than once.');
	$state = get_option(ORAS_AI_Usage_Ledger::OPTION);
	oras_ai_assert_same(1, count($state['burst'][7]), 'Classifier consumed another burst slot.');
	oras_ai_assert_same(array('answer', 'domain_classifier'), array_column($state['reservations'], 'source'), 'Distinct paid records were merged.');
});

oras_ai_test('M9 paid off-topic classifier retains its spend when answer reservation releases', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$body = oras_ai_test_paid_body('domain_classifier'); $body['output_text'] = '{"domain":"off_topic"}';
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
	$result = oras_ai_test_real_orchestrator($ledger)->answer(oras_ai_test_authorized_request(7, 'Can you explain what I saw?'));
	oras_ai_assert_same('refusal', $result->status(), 'Off-topic protection weakened.');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Off-topic classifier caused answer dispatch.');
	oras_ai_assert_same(160, $ledger->summary()['site_month_actual_microdollars'], 'Paid classifier cost was released.');
	oras_ai_assert_same(0, $ledger->summary(7)['member_day_allowed'], 'Refusal consumed successful question.');
});

oras_ai_test('M9 domain output qualifies all enums and rejects oversized or incomplete output', function (): void {
	foreach (array('oras', 'astronomy', 'crossover', 'off_topic') as $domain) {
		$ledger = oras_ai_test_site_cost_setup();
		$body = oras_ai_test_paid_body('domain_classifier'); $body['output_text'] = wp_json_encode(array('domain' => $domain));
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
		$result = oras_ai_test_paid_family('domain_classifier');
		oras_ai_assert_true($result instanceof ORAS_AI_Domain_Result, 'Normal schema did not fit domain cap.');
		oras_ai_assert_same($domain, $result->outcome(), 'Domain enum lost.');
	}
	foreach (array(str_repeat(' ', 600) . '{"domain":"astronomy"}', '{"domain":"astronomy","padding":"' . str_repeat('x', 600) . '"}') as $text) {
		$ledger = oras_ai_test_site_cost_setup();
		$body = oras_ai_test_paid_body('domain_classifier'); $body['output_text'] = $text;
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
		oras_ai_assert_true(is_wp_error(oras_ai_test_paid_family('domain_classifier')), 'Oversized classifier text was accepted.');
		oras_ai_assert_same(160, $ledger->summary()['site_month_actual_microdollars'], 'Oversize discarded usage.');
	}
});

oras_ai_test('M9 scanner denial and incomplete extraction preserve existing approved knowledge', function (): void {
	foreach (array('budget', 'incomplete', 'oversized') as $case) {
		$ledger = oras_ai_test_site_cost_setup();
		$source = oras_ai_test_add_source('page', 'Unusual source', 'Facts and changing details');
		$kb = oras_ai_test_add_linked_kb($source, true);
		$before = oras_ai_test_manual_snapshot($kb);
		if ('budget' === $case) { oras_ai_test_seed_spend($ledger, 20000000); }
		else {
			$body = oras_ai_test_paid_body('scanner_classification');
			if ('incomplete' === $case) { $body['status'] = 'incomplete'; }
			else { $body['output_text'] = wp_json_encode(oras_ai_test_classification(array('reason' => str_repeat('x', 96001)))); }
			$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
		}
		$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($source));
		oras_ai_assert_true(is_wp_error($result), 'Deferred scan must return safe failure.');
		oras_ai_assert_same('error', get_post_meta($source, '_oras_ai_scan_status', true), 'Scanner failed to record safe outcome.');
		oras_ai_assert_same($before, oras_ai_test_manual_snapshot($kb), 'Denied scan altered approved artifact.');
		oras_ai_assert_same('budget' === $case ? 0 : 1, count($GLOBALS['oras_ai_test_remote_calls']), 'Scanner dispatch count changed.');
	}
});

oras_ai_test('M9 scanner mixed extraction admits substantial qualified output without truncating fragments', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$fragments = array(); $excluded = array();
	for ($i = 0; $i < 12; $i++) {
		$fragments[] = array('stable_title' => 'Observatory guide section ' . $i, 'stable_content' => str_repeat('Members follow the observatory orientation and access policy. ', 35));
		$excluded[] = 'Current event schedule and ticket availability ' . $i;
	}
	$classification = oras_ai_test_classification(array('source_kind' => 'mixed', 'stable_fragments' => $fragments, 'excluded_dynamic_claims' => $excluded, 'dynamic_fact_types' => array('event_schedule', 'ticket_availability')));
	$body = array('status' => 'completed', 'output_text' => wp_json_encode($classification), 'usage' => array('input_tokens' => 9000, 'output_tokens' => 7500));
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
	$result = ORAS_AI_OpenAI::classify_source(str_repeat('T', 1000), 'https://oras.org/' . str_repeat('u', 1900), 'page', str_repeat('Durable knowledge. ', 1700));
	oras_ai_assert_same($classification, $result, 'Useful mixed extraction was truncated or lost.');
	oras_ai_assert_same(31500, $ledger->summary()['site_month_actual_microdollars'], 'Substantial scanner output not accounted.');
	oras_ai_assert_same(0, $ledger->summary(7)['member_day_allowed'], 'Scanner consumed member quota.');
});

oras_ai_test('M9 outstanding reservations carry into next UTC month until settled', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-30 23:59:59 UTC');
	$ledger = new ORAS_AI_Usage_Ledger(static function () use (&$now) { return $now; });
	$config = oras_ai_test_execution_config();
	$first = $ledger->reserve(7, 'gpt-5.6-luna', 19997599, $config);
	oras_ai_assert_true($first->allowed(), 'Near-limit reservation fixture failed.');
	$now += 2;
	oras_ai_assert_same('site_hard_stop', $ledger->reserve(8, 'gpt-5.6-luna', 1, $config)->reason(), 'Month rollover ignored currently outstanding exposure.');
});

oras_ai_test('M9 support service reuses one reservation and records known malformed-output usage', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$body = oras_ai_test_paid_body('support_summary'); $body['output_text'] = '{"summary":"<b>unsafe</b>"}';
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
	$result = (new ORAS_AI_Support_Summary_Service())->generate(oras_ai_test_authorized_request(7, 'How does ORAS renewal work?'), 'How does ORAS renewal work?');
	oras_ai_assert_same('unavailable', $result['status'], 'Unsafe summary accepted.');
	oras_ai_assert_same(1, count(get_option(ORAS_AI_Usage_Ledger::OPTION)['reservations']), 'Summary reserved twice.');
	oras_ai_assert_same(160, $ledger->summary()['site_month_actual_microdollars'], 'Invalid summary erased known usage.');
	oras_ai_assert_same(0, $ledger->summary(7)['member_day_allowed'], 'Summary charged a question.');
});

oras_ai_test('M9 paid reservation is claimed once and cannot be released during HTTP', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_before_http'] = static function () use ($ledger): void {
		oras_ai_assert_false($ledger->release('oras-ai-0000000001'), 'In-flight reservation was released.');
	};
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body('answer'));
	$provider = new ORAS_AI_OpenAI_Answer_Provider();
	$context = oras_ai_test_provider_context();
	$first = $provider->answer($context, 800, 30);
	oras_ai_assert_true($first->successful(), 'Qualified paid answer failed.');
	$second = $provider->answer($context, 800, 30, 'oras-ai-0000000001');
	oras_ai_assert_false($second->successful(), 'Settled reservation was reused for free dispatch.');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Reservation replay dispatched twice.');
});

oras_ai_test('M9 member input bounds and administrator gateway hard stop prevent HTTP', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$orchestrator = oras_ai_test_real_orchestrator($ledger);
	oras_ai_assert_same('input_too_large', $orchestrator->answer(oras_ai_test_authorized_request(7, str_repeat('x', 4001)))->error_code(), '4000-byte member boundary changed.');
	oras_ai_test_seed_spend($ledger, 20000000);
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return false; }), $orchestrator);
	$request = $gateway->authorize(oras_ai_test_gateway_payload(array('question' => 'Why are stars bright?')));
	oras_ai_assert_true($request instanceof ORAS_AI_Authorized_Request, 'Administrator allowance fixture failed.');
	oras_ai_assert_same('site_hard_stop', $orchestrator->answer($request)->error_code(), 'Administrator bypassed site hard stop.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Member input/administrator denial dispatched paid HTTP.');
});

oras_ai_test('M9 settled-month source totals and unknown subtotal agree with monthly spend', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-30 23:59:59 UTC');
	$ledger = new ORAS_AI_Usage_Ledger(static function () use (&$now) { return $now; });
	$config = oras_ai_test_execution_config();
	$first = $ledger->reserve(0, 'gpt-5.6-luna', 100, $config, 'domain_classifier', false);
	$second = $ledger->reserve(0, 'gpt-5.6-luna', 100, $config, 'scanner_classification', false);
	$now += 2;
	$ledger->reconcile($first->reservation_id(), 'gpt-5.6-luna', 100, 20);
	$ledger->settle_reserved_maximum($second->reservation_id());
	$summary = $ledger->summary();
	oras_ai_assert_same(2660, $summary['site_month_actual_microdollars'], 'Settled-month cost fixture changed.');
	oras_ai_assert_same(2500, $summary['site_month_unknown_microdollars'], 'Unknown spend omitted at rollover.');
	oras_ai_assert_same(160, $summary['site_month_sources']['domain_classifier']['accounted_microdollars'] ?? 0, 'Settled classifier disappeared from source breakdown.');
	oras_ai_assert_same(2500, $summary['site_month_sources']['scanner_classification']['accounted_microdollars'] ?? 0, 'Conservative scanner disappeared from source breakdown.');
});

oras_ai_test('M9 failed settlement persistence keeps paid dispatch blocked after write recovery', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_before_http'] = static function (): void { $GLOBALS['oras_ai_test_fail_option_write'] = ORAS_AI_Usage_Ledger::OPTION; };
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body('domain_classifier'));
	oras_ai_assert_true(is_wp_error(oras_ai_test_paid_family('domain_classifier')), 'Failed settlement accepted as success.');
	$GLOBALS['oras_ai_test_fail_option_write'] = '';
	$GLOBALS['oras_ai_test_before_http'] = null;
	oras_ai_test_paid_family('scanner_classification');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Unresolved accounting fault allowed new paid calls.');
	oras_ai_assert_same('dispatched', $ledger->reservation('oras-ai-0000000001')['status'], 'Failed settlement erased durable exposure.');
});

oras_ai_test('M9 known overrun with settlement contention fences later dispatch until accounting recovery', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_before_http'] = static function (): void {
		add_option(ORAS_AI_Usage_Ledger::LOCK_OPTION, array('token' => 'concurrent-writer', 'acquired_at' => time()), '', false);
	};
	$body = oras_ai_test_paid_body('domain_classifier');
	$body['usage'] = array('input_tokens' => 20000001, 'output_tokens' => 5);
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, $body);
	oras_ai_assert_true(is_wp_error(oras_ai_test_paid_family('domain_classifier')), 'Contended overrun became successful.');
	// The other legitimate writer eventually releases its own lock.
	delete_option(ORAS_AI_Usage_Ledger::LOCK_OPTION);
	$GLOBALS['oras_ai_test_before_http'] = null;
	oras_ai_test_paid_family('scanner_classification');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Known unrecorded overrun permitted further spend.');
	oras_ai_assert_false($ledger->summary()['accounting_available'] ?? true, 'Incomplete totals were presented as complete.');
	oras_ai_assert_true($ledger->budget_state(ORAS_AI_Cost_Config::get())['hard_stop'], 'Accounting fault did not fail closed.');
	$fault = get_option('oras_ai_usage_ledger_fault', array());
	oras_ai_assert_same(20000001, $fault['input_tokens'] ?? null, 'Reported usage unavailable for operator recovery.');
	oras_ai_assert_same(5, $fault['output_tokens'] ?? null, 'Reported output unavailable for operator recovery.');
	ob_start(); (new ORAS_AI_Cost_Admin($ledger))->render_page(); $html = ob_get_clean();
	oras_ai_assert_contains('Accounting unavailable', $html, 'Operator was shown a misleading ordinary budget state.');
});

oras_ai_test('M9 concurrent lock insertion cannot be overwritten by WordPress add-option upsert', function (): void {
	$ledger = oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_competing_lock'] = true;
	oras_ai_test_paid_family('domain_classifier');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Racing writer lock was stolen before paid dispatch.');
	oras_ai_assert_same('race-winner', get_option(ORAS_AI_Usage_Ledger::LOCK_OPTION)['token'] ?? '', 'Competing lock token was overwritten.');
});
