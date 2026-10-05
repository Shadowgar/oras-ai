<?php
declare(strict_types=1);

function oras_ai_test_retention_record(string $status, int $created, int $resolved = 0): array {
	return array('id' => 'old', 'user_id' => 987654, 'source' => 'answer', 'member_question' => true,
		'created_at' => $created, 'dispatched_at' => $created + 1, 'model' => 'gpt-5.6-luna', 'status' => $status,
		'estimated_input_tokens' => 100, 'maximum_output_tokens' => 800, 'reserved_cost_microdollars' => 2500,
		'pricing' => array('input_microdollars_per_million_tokens' => 1000000, 'output_microdollars_per_million_tokens' => 3000000, 'unit' => 'per_million_tokens'),
		'actual_input_tokens' => null, 'actual_output_tokens' => null, 'actual_cost_microdollars' => 160,
		'conservative_cost_microdollars' => 2500, 'resolved_at' => $resolved);
}
function oras_ai_test_retention_seed(array $records): void {
	update_option(ORAS_AI_Usage_Ledger::OPTION, array('next_id' => 400, 'reservations' => $records, 'burst' => array(), 'rejections' => array()));
}

foreach (array('2026-10-02 12:00:00' => '2025-10-02 12:00:00', '2024-02-29 12:00:00' => '2023-03-01 12:00:00', '2025-03-31 12:00:00' => '2024-03-31 12:00:00') as $today => $boundary) {
	oras_ai_test('M9 retention strict calendar boundary ' . $today, function () use ($today, $boundary): void {
		oras_ai_test_reset();
		$now = strtotime($today . ' UTC'); $cutoff = strtotime($boundary . ' UTC');
		oras_ai_test_retention_seed(array('old' => oras_ai_test_retention_record('reconciled', $cutoff - 1, $cutoff), 'exact' => oras_ai_test_retention_record('reconciled', $cutoff, $cutoff), 'young' => oras_ai_test_retention_record('released', $cutoff + 1)));
		$ledger = new ORAS_AI_Usage_Ledger(static fn() => $now);
		oras_ai_assert_true($ledger->prune(), 'Prune failed.');
		oras_ai_assert_same(null, $ledger->reservation('old'), 'Strictly older record survived.');
		oras_ai_assert_true(is_array($ledger->reservation('exact')), 'Exact cutoff was deleted.');
		oras_ai_assert_true(is_array($ledger->reservation('young')), 'Younger record was deleted.');
	});
}

oras_ai_test('M9 retention expired exposure is redacted without reopening budget or losing late settlement', function (): void {
	oras_ai_test_reset(); $now = strtotime('2026-10-02 12:00:00 UTC');
	$records = array();
	foreach (array('open', 'dispatched', 'usage_unknown', 'reconciled') as $status) { $records[$status] = oras_ai_test_retention_record($status, 1, $now); }
	oras_ai_test_retention_seed($records);
	$ledger = new ORAS_AI_Usage_Ledger(static fn() => $now);
	oras_ai_assert_true($ledger->prune(), 'Cleanup failed.');
	foreach (array_keys($records) as $id) {
		$record = $ledger->reservation($id);
		oras_ai_assert_true(is_array($record), 'Accounting exposure deleted: ' . $id);
		foreach (array('user_id', 'created_at', 'dispatched_at', 'member_question') as $personal) { oras_ai_assert_false(isset($record[$personal]), 'Personal field retained: ' . $personal); }
	}
	$summary = $ledger->summary(987654);
	oras_ai_assert_same(5000, $summary['site_month_reserved_microdollars'], 'Outstanding exposure lost.');
	oras_ai_assert_same(2660, $summary['site_month_actual_microdollars'], 'Current measured/conservative spend lost.');
	oras_ai_assert_same(0, $summary['member_month_allowed'], 'Expired identity still linked to quota.');
	oras_ai_assert_false(is_wp_error($ledger->reconcile('dispatched', 'gpt-5.6-luna', 100, 20)), 'Late reconciliation failed.');
	oras_ai_assert_same(strtotime('2026-10-01 UTC'), $ledger->reservation('dispatched')['resolved_at'], 'Personal exact settlement time recreated.');
	oras_ai_assert_same(2820, $ledger->summary()['site_month_actual_microdollars'], 'Late measured cost lost.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Retention contacted provider.');
});

oras_ai_test('M9 retention fault reference survives redaction and remains fail closed', function (): void {
	oras_ai_test_reset(); $now = strtotime('2026-10-02 UTC');
	oras_ai_test_retention_seed(array('old' => oras_ai_test_retention_record('reconciled', 1, 2)));
	$ledger = new ORAS_AI_Usage_Ledger(static fn() => $now); $ledger->flag_settlement_failure('old', 100, 20);
	$fault = get_option(ORAS_AI_Usage_Ledger::FAULT_OPTION);
	$ledger->prune();
	oras_ai_assert_true(is_array($ledger->reservation('old')), 'Recovery reference deleted.');
	oras_ai_assert_false(isset($ledger->reservation('old')['user_id']), 'Fault retained personal identity.');
	oras_ai_assert_same($fault, get_option(ORAS_AI_Usage_Ledger::FAULT_OPTION), 'Fault fence altered.');
	oras_ai_assert_false($ledger->reserve(7, 'gpt-5.6-luna', 1, oras_ai_test_execution_config())->allowed(), 'Cleanup reopened failed accounting.');
});

oras_ai_test('M9 retention bounded passes eventually reach old records behind young records', function (): void {
	oras_ai_test_reset(); $now = strtotime('2026-10-02 UTC'); $records = array();
	for ($i = 0; $i < 250; $i++) { $records['young-' . $i] = oras_ai_test_retention_record('released', $now); }
	for ($i = 0; $i < 250; $i++) { $records['old-' . $i] = oras_ai_test_retention_record('released', 1); }
	oras_ai_test_retention_seed($records); $ledger = new ORAS_AI_Usage_Ledger(static fn() => $now);
	$ledger->prune();
	oras_ai_assert_same(500, count(get_option(ORAS_AI_Usage_Ledger::OPTION)['reservations']), 'First pass processed beyond its bounded window.');
	for ($i = 0; $i < 6; $i++) { $ledger->prune(); }
	oras_ai_assert_same(250, count(get_option(ORAS_AI_Usage_Ledger::OPTION)['reservations']), 'Old records starved behind retained records.');
	$before = $ledger->summary(); $ledger->prune();
	oras_ai_assert_same($before, $ledger->summary(), 'Repeated cleanup changed totals.');
});

oras_ai_test('M9 retention callback works idle and exposes bounded busy outcome without altering a writer', function (): void {
	oras_ai_test_reset(); $now = strtotime('2026-10-02 UTC');
	oras_ai_test_retention_seed(array('old' => oras_ai_test_retention_record('released', 1), 'current' => oras_ai_test_retention_record('reconciled', $now, $now)));
	$ledger = new ORAS_AI_Usage_Ledger(static fn() => $now);
	oras_ai_assert_true(class_exists('ORAS_AI_Usage_Maintenance'), 'Usage maintenance callback is missing.');
	$maintenance = new ORAS_AI_Usage_Maintenance($ledger);
	$lock = array('token' => 'other-writer', 'acquired_at' => $now); update_option(ORAS_AI_Usage_Ledger::LOCK_OPTION, $lock);
	$before = get_option(ORAS_AI_Usage_Ledger::OPTION); $maintenance->cleanup();
	oras_ai_assert_same($before, get_option(ORAS_AI_Usage_Ledger::OPTION), 'Busy cleanup partially rewrote ledger.');
	oras_ai_assert_same($lock, get_option(ORAS_AI_Usage_Ledger::LOCK_OPTION), 'Cleanup stole lock.');
	oras_ai_assert_same('busy', ORAS_AI_Usage_Maintenance::status()['outcome'], 'Busy maintenance hidden.');
	delete_option(ORAS_AI_Usage_Ledger::LOCK_OPTION);
	$new = $ledger->reserve(7, 'gpt-5.6-luna', 100, oras_ai_test_execution_config());
	$ledger->reconcile($new->reservation_id(), 'gpt-5.6-luna', 100, 20);
	$maintenance->cleanup();
	oras_ai_assert_same(null, $ledger->reservation('old'), 'Idle cleanup did not remove fixture.');
	oras_ai_assert_same(320, $ledger->summary()['site_month_actual_microdollars'], 'Fresh settlement overwritten.');
	oras_ai_assert_same(1, $ledger->summary(7)['member_day_allowed'], 'Fresh member quota overwritten.');
	oras_ai_assert_same('success', ORAS_AI_Usage_Maintenance::status()['outcome'], 'Success missing.');
	oras_ai_assert_true(ORAS_AI_Usage_Maintenance::status()['last_success'] > 0, 'Success timestamp missing.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Cleanup called HTTP.');
});

oras_ai_test('M9 retention activation init upgrade deactivation and reactivation preserve configuration', function (): void {
	foreach (array('0', '1') as $saved) {
		oras_ai_test_reset(); update_option(ORAS_AI_Config::OPTION_MEMBER_AI_ENABLED, $saved); update_option('oras_ai_version', ORAS_AI_VERSION);
		update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'preserved-private-key'); oras_ai_test_retention_seed(array('current' => oras_ai_test_retention_record('open', time())));
		$before = get_option(ORAS_AI_Usage_Ledger::OPTION);
		ORAS_AI_Assistant::activate(); ORAS_AI_Assistant::activate();
		oras_ai_assert_true(false !== wp_next_scheduled('oras_ai_prune_usage'), 'Activation omitted usage schedule.');
		$assistant = new ORAS_AI_Assistant(); $assistant->maybe_upgrade();
		update_option('oras_ai_version', '0.2.0'); $assistant->maybe_upgrade();
		oras_ai_assert_same(ORAS_AI_VERSION, get_option('oras_ai_version'), 'Older install was not upgraded.');
		oras_ai_assert_same($saved, get_option(ORAS_AI_Config::OPTION_MEMBER_AI_ENABLED), 'Actual upgrade reset saved switch.');
		$maintenance = new ORAS_AI_Usage_Maintenance(); $maintenance->schedule_cleanup(); $maintenance->schedule_cleanup();
		$usageEvents = array_filter($GLOBALS['oras_ai_test_scheduled_events'], static fn($event) => 'oras_ai_prune_usage' === $event['hook']);
		oras_ai_assert_same(1, count($usageEvents), 'Duplicate recurring event.');
		wp_schedule_event(time(), 'daily', 'unrelated_hook'); ORAS_AI_Usage_Maintenance::deactivate();
		oras_ai_assert_same(false, wp_next_scheduled('oras_ai_prune_usage'), 'Deactivation left usage cron.');
		oras_ai_assert_true(false !== wp_next_scheduled('unrelated_hook'), 'Deactivation removed unrelated cron.');
		$assistant->maybe_upgrade(); $maintenance->schedule_cleanup();
		oras_ai_assert_true(false !== wp_next_scheduled('oras_ai_prune_usage'), 'Same-version load omitted cron.');
		ORAS_AI_Assistant::activate();
		oras_ai_assert_same($saved, get_option(ORAS_AI_Config::OPTION_MEMBER_AI_ENABLED), 'Saved switch changed.');
		oras_ai_assert_same($before, get_option(ORAS_AI_Usage_Ledger::OPTION), 'Lifecycle wiped ledger.');
		oras_ai_assert_same('preserved-private-key', get_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY), 'Lifecycle changed provider configuration.');
	}
	oras_ai_test_reset(); ORAS_AI_Assistant::activate(); oras_ai_assert_true(ORAS_AI_Config::member_ai_enabled(), 'Historical missing-option policy changed.');
});

oras_ai_test('M9 retention failed durable write keeps ledger protected and last success unchanged', function (): void {
	oras_ai_test_reset(); oras_ai_test_retention_seed(array('old' => oras_ai_test_retention_record('released', 1)));
	update_option('oras_ai_usage_maintenance', array('last_success' => 123));
	$before = get_option(ORAS_AI_Usage_Ledger::OPTION);
	$GLOBALS['oras_ai_test_fail_option_write'] = ORAS_AI_Usage_Ledger::OPTION;
	(new ORAS_AI_Usage_Maintenance())->cleanup();
	oras_ai_assert_same('failed', ORAS_AI_Usage_Maintenance::status()['outcome'], 'Persistence failure hidden.');
	oras_ai_assert_same(123, ORAS_AI_Usage_Maintenance::status()['last_success'], 'Failure advanced success timestamp.');
	oras_ai_assert_same($before, get_option(ORAS_AI_Usage_Ledger::OPTION), 'Partial failed mutation.');
	oras_ai_assert_true(is_array(get_option(ORAS_AI_Usage_Ledger::LOCK_OPTION)), 'Failed storage dropped safety lock.');
	$GLOBALS['oras_ai_test_fail_option_write'] = '';
	oras_ai_assert_false((new ORAS_AI_Usage_Ledger())->reserve(7, 'gpt-5.6-luna', 1, oras_ai_test_execution_config())->allowed(), 'Paid admission recovered before storage repair.');
});

oras_ai_test('M9 retention rejection and burst batches progress without dropping current counters', function (): void {
	oras_ai_test_reset(); $now = strtotime('2026-10-02 UTC');
	$state = array('next_id' => 800, 'reservations' => array(), 'burst' => array(), 'rejections' => array());
	for ($i=1; $i<=250; $i++) { $state['burst'][$i] = array($now); $state['rejections'][$i] = array('2026-10' => array('site_hard_stop' => 2), '2024-01' => array('burst_limit' => 1)); }
	$state['burst'][999] = array(1); update_option(ORAS_AI_Usage_Ledger::OPTION, $state);
	$ledger = new ORAS_AI_Usage_Ledger(static fn() => $now);
	for ($i=0; $i<7; $i++) { $result = $ledger->prune_batch(); oras_ai_assert_true($result['examined'] <= 300, 'Per-pass work exceeded cap.'); }
	$after = get_option(ORAS_AI_Usage_Ledger::OPTION);
	oras_ai_assert_same(800, $after['next_id'], 'Cleanup rewound ID allocation.');
	oras_ai_assert_false(isset($after['burst'][999]), 'Expired late burst entry starved.');
	oras_ai_assert_same(250, count($after['burst']), 'Current burst counters dropped.');
	foreach ($after['rejections'] as $periods) { oras_ai_assert_same(array('2026-10' => array('site_hard_stop' => 2)), $periods, 'Old rejection survived/current count lost.'); }
});

oras_ai_test('M9 retention registered init callback schedules same-version installs without admin visit', function (): void {
	oras_ai_test_reset(); update_option('oras_ai_version', ORAS_AI_VERSION);
	$invoked = false;
	foreach ($GLOBALS['oras_ai_test_hooks']['init'] ?? array() as $hook) {
		$callback = $hook['callback'];
		if (is_array($callback) && 'ORAS_AI_Usage_Maintenance' === $callback[0] && 'schedule_cleanup' === $callback[1]) { call_user_func($callback); $invoked = true; }
	}
	oras_ai_assert_true($invoked, 'No maintenance registration on ordinary bootstrap.');
	oras_ai_assert_true(false !== wp_next_scheduled('oras_ai_prune_usage'), 'Existing version lacks cleanup.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Bootstrap contacted provider.');
});
