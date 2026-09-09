<?php
declare(strict_types=1);

oras_ai_test('M6 astronomy observability stores only bounded provider health aggregates', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Astronomy_Observability();
	$observer->record_outcome('astronomy_api', ORAS_AI_Current_Data_Result::UNAVAILABLE, 'provider_unavailable');
	$observer->record_outcome('openngc_local', ORAS_AI_Current_Data_Result::UNKNOWN, 'target_not_resolved');
	$observer->record_outcome('astronomy_api', ORAS_AI_Current_Data_Result::UNKNOWN, 'raw exception user@example.test');
	$state = $observer->snapshot();

	oras_ai_assert_same(1, $state['astronomy_api']['failure_count'], 'Operational AstronomyAPI failure count changed.');
	oras_ai_assert_same('provider_unavailable', $state['astronomy_api']['last_failure_reason'], 'Safe astronomy failure reason missing.');
	oras_ai_assert_same(0, $state['openngc_local']['failure_count'], 'Unknown target was incorrectly counted as provider health failure.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Astronomy_Observability::OPTION], 'Astronomy health storage must not autoload.');
	$stored = wp_json_encode(get_option(ORAS_AI_Astronomy_Observability::OPTION));
	foreach (array('user@example.test', 'question', 'target_text', 'stack', 'trace') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $stored, 'Astronomy observability stored unsafe request or exception material.');
	}

	$observer->record_outcome('astronomy_api', ORAS_AI_Current_Data_Result::SUCCESS, '');
	$recovered = $observer->snapshot()['astronomy_api'];
	oras_ai_assert_same('healthy', $recovered['operational_state'], 'Successful astronomy call did not mark current state healthy.');
	oras_ai_assert_same(1, $recovered['failure_count'], 'Success erased cumulative provider failure count.');
});

oras_ai_test('M6 provider admin shows bounded astronomy health without secrets or Task 3 weather state', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Astronomy_Observability();
	$observer->record_outcome('astronomy_api', ORAS_AI_Current_Data_Result::UNAVAILABLE, 'provider_unavailable');
	ORAS_AI_Config::update_stored_astronomyapi_credentials('fixture-id', 'never-render-this-secret');
	ob_start();
	(new ORAS_AI_M6_Provider_Admin($observer))->render_page();
	$html = (string) ob_get_clean();

	oras_ai_assert_contains('Astronomy provider health', $html, 'Task 2 provider health is not visible.');
	oras_ai_assert_contains('provider_unavailable', $html, 'Bounded astronomy failure reason is not visible.');
	oras_ai_assert_not_contains('never-render-this-secret', $html, 'Provider health exposed credentials.');
	oras_ai_assert_not_contains('Weather provider health', $html, 'Task 3 weather health was pulled forward.');
});
