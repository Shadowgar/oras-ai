<?php
declare(strict_types=1);

oras_ai_test('audit log stores the exact safe event schema actor and timestamp', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 42;
	ORAS_AI_Audit_Log::log_openai_model_changed('gpt-5.6-luna', 'gpt-5.6-terra');

	$events = ORAS_AI_Audit_Log::recent_events();
	oras_ai_assert_same(1, count($events), 'One model audit event should be stored.');
	oras_ai_assert_same(
		array(
			'timestamp' => '2026-08-27 12:34:56',
			'actor_user_id' => 42,
			'config_item' => 'config.openai_model',
			'action' => 'changed',
			'outcome' => 'success',
			'old_state' => 'gpt-5.6-luna',
			'new_state' => 'gpt-5.6-terra',
		),
		$events[0],
		'Audit event schema changed.'
	);
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload']['oras_ai_config_audit_events'], 'Audit option must not autoload.');
});

oras_ai_test('audit log bounds retained history to 100 newest events', function (): void {
	oras_ai_test_reset();
	for ($index = 0; $index < 105; $index++) {
		ORAS_AI_Audit_Log::log_openai_model_changed('old-' . $index, 'new-' . $index);
	}

	$events = ORAS_AI_Audit_Log::recent_events(200);
	oras_ai_assert_same(100, count($events), 'Audit history must remain bounded to 100 events.');
	oras_ai_assert_same('new-104', $events[0]['new_state'], 'Newest event should remain first.');
	oras_ai_assert_same('new-5', $events[99]['new_state'], 'Oldest retained event should reflect pruning.');
});

oras_ai_test('API key audit event accepts semantic action only', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Audit_Log::log_openai_api_key_changed('set');
	$event = ORAS_AI_Audit_Log::recent_events()[0];

	oras_ai_assert_same('config.openai_api_key', $event['config_item'], 'API-key audit identifier changed.');
	oras_ai_assert_same('set', $event['action'], 'API-key audit action changed.');
	oras_ai_assert_same(null, $event['old_state'], 'API-key audit must omit old state.');
	oras_ai_assert_same(null, $event['new_state'], 'API-key audit must omit new state.');
});
