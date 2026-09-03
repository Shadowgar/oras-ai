<?php
declare(strict_types=1);

function oras_ai_test_execution_config(array $overrides = array()): array {
	$defaults = ORAS_AI_Cost_Config::defaults();
	$defaults['pricing'] = array(
		'gpt-5.6-luna' => array(
			'input_microdollars_per_million_tokens' => 1000000,
			'output_microdollars_per_million_tokens' => 3000000,
			'unit' => 'per_million_tokens',
		),
	);

	return array_replace_recursive($defaults, $overrides);
}

function oras_ai_test_authorized_request(int $userId, string $question = 'When is the observatory open?'): ORAS_AI_Authorized_Request {
	return new ORAS_AI_Authorized_Request($userId, $question, array('public', 'members'), false);
}

function oras_ai_test_cost_fixture(int &$now, array $overrides = array()): array {
	$ledger = new ORAS_AI_Usage_Ledger(
		static function () use (&$now): int {
			return $now;
		}
	);
	$config = oras_ai_test_execution_config($overrides);

	return array($ledger, new ORAS_AI_Execution_Controls($ledger, $config), $config);
}

oras_ai_test('cost configuration exposes the frozen defaults and conservative centralized execution bounds', function (): void {
	$defaults = ORAS_AI_Cost_Config::defaults();

	oras_ai_assert_same(25, $defaults['daily_quota'], 'Daily quota default changed.');
	oras_ai_assert_same(150, $defaults['monthly_quota'], 'Monthly quota default changed.');
	oras_ai_assert_same(5, $defaults['burst_per_minute'], 'Burst default changed.');
	oras_ai_assert_same(10000000, $defaults['warning_microdollars'], 'Ten-dollar warning default changed.');
	oras_ai_assert_same(20000000, $defaults['hard_stop_microdollars'], 'Twenty-dollar hard stop default changed.');
	oras_ai_assert_same(4000, $defaults['max_input_characters'], 'Central input bound changed.');
	oras_ai_assert_same(800, $defaults['max_output_tokens'], 'Central output bound changed.');
	oras_ai_assert_same(30, $defaults['execution_timeout_seconds'], 'Central timeout changed.');
	oras_ai_assert_same(array(), $defaults['pricing'], 'No provider pricing may be guessed by default.');
});

oras_ai_test('AT-COST-001 daily quota allows 25 successful admissions and denies 26 without spend', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 00:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);

	for ($attempt = 1; $attempt <= 25; $attempt++) {
		$result = $controls->admit(oras_ai_test_authorized_request(41), 'gpt-5.6-luna');
		oras_ai_assert_true($result->allowed(), 'Admission ' . $attempt . ' should be allowed.');
		$now += 61;
	}

	$denied = $controls->admit(oras_ai_test_authorized_request(41), 'gpt-5.6-luna');
	oras_ai_assert_false($denied->allowed(), 'Admission 26 must be denied.');
	oras_ai_assert_same('daily_quota', $denied->reason(), 'Daily denial reason changed.');
	$summary = $ledger->summary(41);
	oras_ai_assert_same(25, $summary['member_day_allowed'], 'Denied request incremented daily successful-question usage.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Quota denial must happen before provider work.');
});

oras_ai_test('AT-COST-001 monthly quota allows 150 successful admissions and denies 151', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 00:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture(
		$now,
		array('daily_quota' => 1000, 'burst_per_minute' => 1000)
	);

	for ($attempt = 1; $attempt <= 150; $attempt++) {
		$result = $controls->admit(oras_ai_test_authorized_request(51), 'gpt-5.6-luna');
		oras_ai_assert_true($result->allowed(), 'Monthly admission ' . $attempt . ' should be allowed.');
	}

	$denied = $controls->admit(oras_ai_test_authorized_request(51), 'gpt-5.6-luna');
	oras_ai_assert_same('monthly_quota', $denied->reason(), 'Monthly denial reason changed.');
	oras_ai_assert_same(150, $ledger->summary(51)['member_month_allowed'], 'Denied request incremented monthly successful-question usage.');
});

oras_ai_test('AT-COST-001 accounting is independent and cannot use a browser user ID', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 17;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked);
	$authorized = $gateway->authorize(
		oras_ai_test_gateway_payload(array('user_id' => 999, 'question' => 'Observatory hours?'))
	);
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now, array('daily_quota' => 1));

	$first = $controls->admit($authorized, 'gpt-5.6-luna');
	$second = $controls->admit(oras_ai_test_authorized_request(18), 'gpt-5.6-luna');

	oras_ai_assert_true($first->allowed(), 'Server-authenticated member should be admitted.');
	oras_ai_assert_true($second->allowed(), 'Another member needs an independent quota bucket.');
	oras_ai_assert_same(1, $ledger->summary(17)['member_day_allowed'], 'Authenticated user bucket changed.');
	oras_ai_assert_same(0, $ledger->summary(999)['member_day_allowed'], 'Browser user ID affected accounting.');
});

oras_ai_test('AT-COST-002 rolling burst allows five denies six and recovers independently', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);

	for ($attempt = 1; $attempt <= 5; $attempt++) {
		oras_ai_assert_true($controls->admit(oras_ai_test_authorized_request(61), 'gpt-5.6-luna')->allowed(), 'Burst request ' . $attempt . ' should pass.');
	}
	$denied = $controls->admit(oras_ai_test_authorized_request(61), 'gpt-5.6-luna');
	oras_ai_assert_same('burst_limit', $denied->reason(), 'Sixth burst request must be rate limited.');
	oras_ai_assert_true($controls->admit(oras_ai_test_authorized_request(62), 'gpt-5.6-luna')->allowed(), 'Another member needs an independent burst bucket.');

	$now += 61;
	oras_ai_assert_true($controls->admit(oras_ai_test_authorized_request(61), 'gpt-5.6-luna')->allowed(), 'Expired rolling window should restore eligibility.');
	oras_ai_assert_same(1, $ledger->summary(61)['rejections']['burst_limit'], 'Burst rejection observability changed.');
});

oras_ai_test('AT-COST-003 oversized input is denied and output and timeout budgets are exposed centrally', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture(
		$now,
		array('max_input_characters' => 20, 'max_output_tokens' => 321, 'execution_timeout_seconds' => 17)
	);

	$oversized = $controls->admit(oras_ai_test_authorized_request(71, str_repeat('x', 21)), 'gpt-5.6-luna');
	oras_ai_assert_same('input_too_large', $oversized->reason(), 'Oversized input denial changed.');
	oras_ai_assert_same(0, $ledger->summary(71)['member_day_allowed'], 'Oversized input consumed successful usage.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Oversized input must stop before provider work.');

	$allowed = $controls->admit(oras_ai_test_authorized_request(71, 'short'), 'gpt-5.6-luna');
	oras_ai_assert_same(321, $allowed->max_output_tokens(), 'Admission did not carry the centralized output budget.');
	oras_ai_assert_same(17, $allowed->timeout_seconds(), 'Admission did not carry the centralized timeout.');

	$registry = oras_ai_test_capability_registry();
	oras_ai_assert_wp_error(
		$registry->authorize_invocation('knowledge_search', array('query' => 'Saturn'), 2),
		'oras_ai_capability_denied',
		'Task 3 capability depth bound must remain enforced.'
	);
});

oras_ai_test('AT-COST-004 missing model pricing fails closed before paid provider work', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$ledger = new ORAS_AI_Usage_Ledger(static function () use (&$now): int { return $now; });
	$controls = new ORAS_AI_Execution_Controls($ledger, ORAS_AI_Cost_Config::defaults());

	$result = $controls->admit(oras_ai_test_authorized_request(81), 'gpt-5.6-luna');

	oras_ai_assert_false($result->allowed(), 'Unpriced paid execution must be denied.');
	oras_ai_assert_same('missing_model_price', $result->reason(), 'Missing-price denial changed.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Missing price must stop before provider work.');
});

oras_ai_test('pricing keeps separate input output rates and reservation metadata reproduces its calculation', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture(
		$now,
		array(
			'max_output_tokens' => 800,
			'pricing' => array(
				'gpt-5.6-luna' => array(
					'input_microdollars_per_million_tokens' => 2000000,
					'output_microdollars_per_million_tokens' => 6000000,
					'unit' => 'per_million_tokens',
				),
			),
		)
	);

	$admission = $controls->admit(oras_ai_test_authorized_request(91, 'test'), 'gpt-5.6-luna');
	$record = $ledger->reservation($admission->reservation_id());

	oras_ai_assert_same(4808, $admission->reserved_cost_microdollars(), 'Conservative maximum cost calculation changed.');
	oras_ai_assert_same(4, $record['estimated_input_tokens'], 'Conservative input estimate changed.');
	oras_ai_assert_same(2000000, $record['pricing']['input_microdollars_per_million_tokens'], 'Input rate snapshot missing.');
	oras_ai_assert_same(6000000, $record['pricing']['output_microdollars_per_million_tokens'], 'Output rate snapshot missing.');
	oras_ai_assert_same('per_million_tokens', $record['pricing']['unit'], 'Pricing unit snapshot missing.');
});

oras_ai_test('open reservations count toward hard stop and crossing reservation is denied', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture(
		$now,
		array(
			'hard_stop_microdollars' => 10000,
			'warning_microdollars' => 5000,
			'max_output_tokens' => 600,
			'pricing' => array(
				'gpt-5.6-luna' => array(
					'input_microdollars_per_million_tokens' => 1000000,
					'output_microdollars_per_million_tokens' => 10000000,
					'unit' => 'per_million_tokens',
				),
			),
		)
	);

	$first = $controls->admit(oras_ai_test_authorized_request(101, 'tiny'), 'gpt-5.6-luna');
	$second = $controls->admit(oras_ai_test_authorized_request(102, 'tiny'), 'gpt-5.6-luna');

	oras_ai_assert_true($first->allowed(), 'First reservation should fit.');
	oras_ai_assert_same(6004, $ledger->summary()['site_month_reserved_microdollars'], 'Open reservation aggregate changed.');
	oras_ai_assert_same('site_hard_stop', $second->reason(), 'Crossing reservation must be denied by site hard stop.');
	oras_ai_assert_same(1, $ledger->summary()['rejections']['site_hard_stop'], 'Hard-stop rejection should be observable.');
});

oras_ai_test('ledger lock contention fails closed without a reservation or provider work', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	update_option(ORAS_AI_Usage_Ledger::LOCK_OPTION, array('token' => 'other-request', 'acquired_at' => $now), false);
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);

	$result = $controls->admit(oras_ai_test_authorized_request(105), 'gpt-5.6-luna');

	oras_ai_assert_same('ledger_unavailable', $result->reason(), 'Concurrent accounting contention must fail closed.');
	oras_ai_assert_same(0, $ledger->summary()['site_month_reserved_microdollars'], 'Lock contention created an unaccounted reservation.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Accounting contention must stop before provider work.');
});

oras_ai_test('reconciliation replaces reservation with provider-reported actual usage and cost', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);
	$admission = $controls->admit(oras_ai_test_authorized_request(111, 'short'), 'gpt-5.6-luna');

	$result = $ledger->reconcile($admission->reservation_id(), 'gpt-5.6-luna', 100, 50);
	$summary = $ledger->summary(111);

	oras_ai_assert_same(250, $result['actual_cost_microdollars'], 'Actual cost did not use provider-reported input/output usage.');
	oras_ai_assert_same(0, $summary['site_month_reserved_microdollars'], 'Reconciled reservation remained outstanding.');
	oras_ai_assert_same(250, $summary['site_month_actual_microdollars'], 'Actual monthly cost aggregate changed.');
	oras_ai_assert_same(100, $result['actual_input_tokens'], 'Provider input usage was not preserved.');
	oras_ai_assert_same(50, $result['actual_output_tokens'], 'Provider output usage was not preserved.');
});

oras_ai_test('released and duplicate reconciled reservations are idempotent and cannot double charge', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);
	$released = $controls->admit(oras_ai_test_authorized_request(121), 'gpt-5.6-luna');
	oras_ai_assert_true($ledger->release($released->reservation_id()), 'Open reservation should release.');
	oras_ai_assert_true($ledger->release($released->reservation_id()), 'Duplicate release should be idempotent.');
	oras_ai_assert_same(0, $ledger->summary()['site_month_reserved_microdollars'], 'Released reservation still counted.');
	oras_ai_assert_same(0, $ledger->summary(121)['member_day_allowed'], 'Released execution should not consume successful-question quota.');

	$completed = $controls->admit(oras_ai_test_authorized_request(121), 'gpt-5.6-luna');
	$first = $ledger->reconcile($completed->reservation_id(), 'gpt-5.6-luna', 20, 10);
	$duplicate = $ledger->reconcile($completed->reservation_id(), 'gpt-5.6-luna', 900, 900);
	oras_ai_assert_same($first, $duplicate, 'Duplicate reconciliation must return the original accounting result.');
	oras_ai_assert_same(50, $ledger->summary()['site_month_actual_microdollars'], 'Duplicate reconciliation double charged.');
});

oras_ai_test('reconciliation validates normalized provider usage and reservation model', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);
	$admission = $controls->admit(oras_ai_test_authorized_request(125), 'gpt-5.6-luna');

	foreach (
		array(
			array('gpt-5.6-terra', 1, 1),
			array('gpt-5.6-luna', '1', 1),
			array('gpt-5.6-luna', -1, 1),
			array('gpt-5.6-luna', 1, -1),
		) as $usage
	) {
		$result = $ledger->reconcile($admission->reservation_id(), $usage[0], $usage[1], $usage[2]);
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_cost_reservation', 'Malformed provider usage must fail safely.');
	}
	oras_ai_assert_same('open', $ledger->reservation($admission->reservation_id())['status'], 'Invalid reconciliation changed reservation state.');
});

oras_ai_test('warning is nonblocking while hard stop blocks new paid admission', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture(
		$now,
		array(
			'warning_microdollars' => 1000,
			'hard_stop_microdollars' => 2000,
			'max_output_tokens' => 100,
			'pricing' => array(
				'gpt-5.6-luna' => array(
					'input_microdollars_per_million_tokens' => 1000000,
					'output_microdollars_per_million_tokens' => 10000000,
					'unit' => 'per_million_tokens',
				),
			),
		)
	);

	$first = $controls->admit(oras_ai_test_authorized_request(131, 'a'), 'gpt-5.6-luna');
	$ledger->reconcile($first->reservation_id(), 'gpt-5.6-luna', 1, 100);
	$warning = $ledger->budget_state(oras_ai_test_execution_config(array('warning_microdollars' => 1000, 'hard_stop_microdollars' => 2000)));
	oras_ai_assert_true($warning['warning'], 'At-warning actual spend should expose warning state.');
	oras_ai_assert_false($warning['hard_stop'], 'Warning alone must not be hard stop.');

	$second = $controls->admit(oras_ai_test_authorized_request(132, 'a'), 'gpt-5.6-luna');
	oras_ai_assert_same('site_hard_stop', $second->reason(), 'A maximum reservation reaching the hard stop must be denied.');
});

oras_ai_test('frozen ten-dollar warning and twenty-dollar hard stop use reconciled monthly cost', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	list($ledger, $controls, $config) = oras_ai_test_cost_fixture(
		$now,
		array(
			'max_output_tokens' => 1,
			'pricing' => array(
				'gpt-5.6-luna' => array(
					'input_microdollars_per_million_tokens' => 10000000000,
					'output_microdollars_per_million_tokens' => 1,
					'unit' => 'per_million_tokens',
				),
			),
		)
	);

	$below = $controls->admit(oras_ai_test_authorized_request(135, 'a'), 'gpt-5.6-luna');
	$ledger->reconcile($below->reservation_id(), 'gpt-5.6-luna', 999, 0);
	oras_ai_assert_false($ledger->budget_state($config)['warning'], 'Spend below ten dollars must remain normal.');

	$warning = $controls->admit(oras_ai_test_authorized_request(136, 'a'), 'gpt-5.6-luna');
	$ledger->reconcile($warning->reservation_id(), 'gpt-5.6-luna', 1, 0);
	oras_ai_assert_true($ledger->budget_state($config)['warning'], 'Spend at ten dollars must expose warning state.');
	oras_ai_assert_false($ledger->budget_state($config)['hard_stop'], 'Ten-dollar warning must remain nonblocking.');

	$toHardStop = $controls->admit(oras_ai_test_authorized_request(137, 'a'), 'gpt-5.6-luna');
	$ledger->reconcile($toHardStop->reservation_id(), 'gpt-5.6-luna', 1000, 0);
	oras_ai_assert_true($ledger->budget_state($config)['hard_stop'], 'Spend at twenty dollars must expose hard-stop state.');
	oras_ai_assert_same(
		'site_hard_stop',
		$controls->admit(oras_ai_test_authorized_request(138, 'a'), 'gpt-5.6-luna')->reason(),
		'Twenty-dollar hard stop must deny new paid execution.'
	);
});

oras_ai_test('usage ledger retains only metadata for 12 months and prunes older records', function (): void {
	oras_ai_test_reset();
	$secret = 'sk-private-fixture';
	$question = 'Private member question that must not be stored';
	$now = strtotime('2025-08-01 12:00:00 UTC');
	list($ledger, $controls) = oras_ai_test_cost_fixture($now);
	$admission = $controls->admit(oras_ai_test_authorized_request(141, $question), 'gpt-5.6-luna');
	$ledger->reconcile($admission->reservation_id(), 'gpt-5.6-luna', 10, 5);
	$serialized = wp_json_encode(get_option(ORAS_AI_Usage_Ledger::OPTION));

	foreach (array($question, $secret, 'retrieved_evidence', 'provider_response', 'prompt') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $serialized, 'Usage ledger stored forbidden content.');
	}
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Usage_Ledger::OPTION], 'Usage ledger must not autoload.');

	$now = strtotime('2026-09-03 12:00:00 UTC');
	$ledger->prune();
	oras_ai_assert_same(0, $ledger->summary(141)['member_month_allowed'], 'Metadata older than 12 months was retained.');
});
