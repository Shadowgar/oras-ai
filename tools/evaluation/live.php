<?php
declare(strict_types=1);

/** Explicit native disposable runner; uses saved settings and durable Task 1 ledger. */
final class ORAS_AI_Evaluation_Live {
	public static function run(array $options, callable $checkpoint): array {
		$admission = ORAS_AI_Release_Evaluation::live_options($options);
		if (!$admission['allowed']) { throw new RuntimeException($admission['reason']); }
		if (defined('ORAS_AI_TESTING') || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { throw new RuntimeException('disposable_environment_required'); }
		$host = parse_url(home_url(), PHP_URL_HOST);
		if (!is_string($host) || !($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'))) { throw new RuntimeException('disposable_host_required'); }
		$key = ORAS_AI_Config::get_openai_api_key();
		if ('' === $key) { throw new RuntimeException('live_key_missing'); }
		$config = ORAS_AI_Cost_Config::get();
		if (true !== ($options['pricing_qualified'] ?? false) || !isset($config['pricing'][ORAS_AI_Release_Evaluation::MODEL])) { throw new RuntimeException('qualified_saved_pricing_missing'); }
		if (get_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, ORAS_AI_Config::DEFAULT_OPENAI_MODEL) !== ORAS_AI_Release_Evaluation::MODEL || $config['max_output_tokens'] !== 800 || $config['warning_microdollars'] !== 10000000 || $config['hard_stop_microdollars'] !== 20000000 || !ORAS_AI_Config::member_ai_enabled()) { throw new RuntimeException('saved_runtime_contract_not_candidate'); }
		$all = array_column(ORAS_AI_Release_Evaluation::corpus()['cases'], null, 'id');
		$cases = array_map(static function ($id) use ($all) { return $all[$id]; }, $options['case_ids']);
		foreach ($options['user_ids'] as $id) {
			$user = get_userdata($id);
			if (!$user || !str_starts_with($user->user_login, 'oras-ai-eval-') || '1' !== get_user_meta($id, 'oras_ai_evaluation_synthetic', true)) { throw new RuntimeException('explicit_synthetic_identity_required'); }
		}
		$plan = ORAS_AI_Release_Evaluation::paid_plan($cases, $config['pricing'][ORAS_AI_Release_Evaluation::MODEL]);
		$ledger = new ORAS_AI_Usage_Ledger(); $before = $ledger->summary();
		$exposure = $before['site_month_actual_microdollars'] + $before['site_month_reserved_microdollars'];
		if (!$before['accounting_available'] || $plan['maximum_calls'] > $options['max_calls'] || $plan['maximum_microdollars'] > $options['max_microdollars'] || $exposure + $plan['maximum_microdollars'] >= $config['hard_stop_microdollars']) { throw new RuntimeException('paid_plan_not_admitted'); }
		$checkpoint(array('type' => 'preflight', 'candidate' => ORAS_AI_Release_Evaluation::MODEL, 'reasoning' => 'low', 'plan' => $plan, 'warning_headroom_microdollars' => max(0, $config['warning_microdollars'] - $exposure), 'hard_stop_headroom_microdollars' => max(0, $config['hard_stop_microdollars'] - $exposure)));
		$calls = array(); $attempts = 0; $started = null; $active_payload = null; $side_effects = 0; $listing = true;
		$guard = static function ($pre, $args, $url) use (&$listing, &$attempts, &$started, &$active_payload, &$side_effects, $options) {
			if ('https://api.openai.com/v1/models' === $url && $listing && 'GET' === ($args['method'] ?? 'GET')) { return false; }
			if ($listing || 'https://api.openai.com/v1/responses' !== $url || 'POST' !== ($args['method'] ?? '') || false !== $pre || $attempts >= $options['max_calls']) { $side_effects++; return new WP_Error('evaluation_network_blocked', 'Evaluation request blocked.'); }
			$payload = json_decode($args['body'] ?? '', true);
			if (!is_array($payload) || ORAS_AI_Release_Evaluation::MODEL !== ($payload['model'] ?? '') || 'low' !== ($payload['reasoning']['effort'] ?? '') || isset($payload['tools'])) { $side_effects++; return new WP_Error('evaluation_request_blocked', 'Evaluation request blocked.'); }
			$attempts++; $started = microtime(true); $active_payload = $payload;
			return false;
		};
		$observer = static function ($response, $context, $class, $args, $url) use (&$calls, &$started, &$active_payload) {
			if ('response' === $context && 'https://api.openai.com/v1/responses' === $url && null !== $active_payload) {
				$calls[] = ORAS_AI_Release_Evaluation::call_record($active_payload, $response, microtime(true) - $started);
				$started = null; $active_payload = null;
			}
		};
		$mail_guard = static function () use (&$side_effects) { $side_effects++; return false; };
		$no_redirect = static function ($args) { $args['redirection'] = 0; return $args; };
		add_filter('pre_http_request', $guard, PHP_INT_MAX, 3);
		add_filter('http_request_args', $no_redirect, PHP_INT_MAX, 2);
		add_action('http_api_debug', $observer, PHP_INT_MAX, 5);
		add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);
		$results = array(); $original_user = get_current_user_id();
		try {
			$response = wp_remote_get('https://api.openai.com/v1/models', array('headers' => array('Authorization' => 'Bearer ' . $key), 'timeout' => 15, 'redirection' => 0));
			$listing = false;
			$body = !is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
			$available = !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response) && is_array($body['data'] ?? null) ? array_values(array_intersect(ORAS_AI_Config::allowed_openai_models(), array_column($body['data'], 'id'))) : array();
			$checkpoint(array('type' => 'account_model_availability', 'available_allowlisted_ids' => $available, 'candidate_available' => in_array(ORAS_AI_Release_Evaluation::MODEL, $available, true)));
			if (!in_array(ORAS_AI_Release_Evaluation::MODEL, $available, true)) { throw new RuntimeException('candidate_api_unavailable_or_discovery_failed'); }
			foreach ($cases as $index => $case) {
				wp_set_current_user($options['user_ids'][$index]);
				$call_offset = count($calls); $side_offset = $side_effects;
				$now = $ledger->summary();
				$one = ORAS_AI_Release_Evaluation::paid_plan(array($case), $config['pricing'][ORAS_AI_Release_Evaluation::MODEL]);
				$spent = $now['site_month_actual_microdollars'] - $before['site_month_actual_microdollars'];
				if ($spent + $one['maximum_microdollars'] > $options['max_microdollars'] || $attempts + $one['maximum_calls'] > $options['max_calls']) {
					$result = array('status' => 'failure', 'answer' => '', 'sources' => array(), 'calls' => array(), 'error_code' => 'evaluation_run_bound', 'total_seconds' => 0, 'accounted_microdollars' => 0, 'unknown_usage_microdollars' => 0);
				} else { $result = ORAS_AI_Release_Evaluation::execute($case); }
				if (null !== $active_payload) { $calls[] = ORAS_AI_Release_Evaluation::call_record($active_payload, new WP_Error('unknown', 'Unavailable.'), microtime(true) - $started); $active_payload = null; }
				$result['calls'] = array_slice($calls, $call_offset); $result['side_effects'] = $side_effects - $side_offset;
				$result['guard_replaced_model_prose'] = count($result['calls']) > 0 && 'answer' === $case['workflow'] && $result['answer'] !== end($result['calls'])['raw_model_output_redacted'];
				$result['grade'] = ORAS_AI_Release_Evaluation::grade($case, $result);
				$row = array_merge(array('id' => $case['id'], 'category' => $case['category'], 'workflow' => $case['workflow']), $result);
				$results[] = $row; $checkpoint(array('type' => 'case', 'result' => $row));
			}
			$report = ORAS_AI_Release_Evaluation::report($results, 'live');
			$report['api_available_allowlisted_ids'] = $available; $report['paid_plan'] = $plan;
			$after = $ledger->summary();
			$report['warning_headroom_microdollars'] = max(0, $config['warning_microdollars'] - $after['site_month_actual_microdollars'] - $after['site_month_reserved_microdollars']);
			$report['hard_stop_headroom_microdollars'] = max(0, $config['hard_stop_microdollars'] - $after['site_month_actual_microdollars'] - $after['site_month_reserved_microdollars']);
			return $report;
		} finally {
			// Do not restore or delete ledger, quota, reservation or fault state after paid use.
			wp_set_current_user($original_user);
			remove_filter('pre_http_request', $guard, PHP_INT_MAX);
			remove_filter('http_request_args', $no_redirect, PHP_INT_MAX);
			remove_action('http_api_debug', $observer, PHP_INT_MAX);
			remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
		}
	}
}
