<?php
declare(strict_types=1);

/** Real connectors and assembly; only external reads and model output are substituted. */
function oras_ai_task3i_live(array $failed = array(), array $product = array()): ORAS_AI_Live_Service {
	return new ORAS_AI_Live_Service(array(
		new ORAS_AI_PMPro_Context_Connector(static fn() => in_array('pmpro', $failed, true) ? new WP_Error('oras_ai_pmpro_unavailable') : array(oras_ai_test_pmpro_level('Family'))),
		new ORAS_AI_WooCommerce_Connector(static fn() => in_array('woo', $failed, true) ? new WP_Error('oras_ai_woocommerce_unavailable') : array(oras_ai_test_woo_record($product))),
		new ORAS_AI_Events_Calendar_Connector(
			static fn(): array => array(oras_ai_test_event_record(array('start' => '2026-10-18 20:00:00', 'end' => '2026-10-18 23:00:00'))),
			static fn(): string => '2026-10-01T12:00:00-04:00',
			static fn() => in_array('event', $failed, true) ? new WP_Error('provider_down') : array(array('state' => 'open'))
		),
	), new ORAS_AI_URL_Policy(array('oras.org')));
}

foreach (array(
	'membership succeeds / price unavailable' => array(array('woo'), array(), array('Current membership status: active', 'Annual Observer Pass price'), array('45.00 USD', 'currently purchasable'), 1),
	'price succeeds / membership unavailable' => array(array('pmpro'), array(), array('could not verify current membership', '45.00 USD'), array('Current membership status: active'), 1),
	'both succeed' => array(array(), array(), array('Current membership status: active', '45.00 USD'), array('could not verify'), 1),
	'both unavailable' => array(array('woo', 'pmpro'), array(), array('could not verify current membership', 'Annual Observer Pass price'), array('45.00 USD', 'Current membership status: active', 'currently purchasable'), 0),
) as $label => $case) {
	oras_ai_test('M9 Task 3I partial failure ' . $label, function () use ($case): void {
		oras_ai_test_reset();
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Costs $1. Purchased and paid. https://evil.example/pay'), array(), oras_ai_task3i_live($case[0], $case[1]));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status and Annual Observer Pass price?'));
		foreach ($case[2] as $text) { oras_ai_assert_contains($text, $result->answer(), 'Requested subquestion disappeared: ' . $result->answer()); }
		foreach ($case[3] as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Unqualified or failed sibling fact asserted.'); }
		oras_ai_assert_same($case[4], count($provider->calls), 'Total unavailability must not generate an answer-model call.');
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

foreach (array(
	'price known / availability unknown' => array(array('stock_status' => 'unknown'), array('45.00 USD', 'could not verify whether the Annual Observer Pass is currently purchasable'), array('. Annual Observer Pass is currently purchasable.')),
	'availability known / price unknown' => array(array('current_price' => 'unavailable'), array('could not verify the Annual Observer Pass price', 'Annual Observer Pass is currently purchasable', 'WooCommerce checkout'), array('45.00 USD')),
) as $label => $case) {
	oras_ai_test('M9 Task 3I independent fields ' . $label, function () use ($case): void {
		oras_ai_test_reset();
		list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Costs $1. Purchased and paid.'), array(), oras_ai_task3i_live(array(), $case[0]));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is the Annual Observer Pass price and can I buy it?'));
		foreach ($case[1] as $text) { oras_ai_assert_contains($text, $result->answer(), 'Independent requested field omitted.'); }
		foreach ($case[2] as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Failed field asserted as known.'); }
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

foreach (array('woo', 'pmpro', 'event') as $failed) {
	oras_ai_test('M9 Task 3I combined price membership event failure ' . $failed, function () use ($failed): void {
		oras_ai_test_reset();
		list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Paid and purchased for $1.'), array(), oras_ai_task3i_live(array($failed)));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status, the Annual Observer Pass price, and can I register for AstroBlast?'));
		oras_ai_assert_contains('pmpro' === $failed ? 'could not verify current membership' : 'Current membership status: active', $result->answer(), 'Membership portion omitted.');
		oras_ai_assert_contains('woo' === $failed ? 'Annual Observer Pass price' : '45.00 USD', $result->answer(), 'Requested price portion omitted.');
		oras_ai_assert_contains('event' === $failed ? 'could not verify registration availability' : 'Registration is currently open', $result->answer(), 'Event portion omitted.');
		oras_ai_assert_contains('AstroBlast 2026 starts', $result->answer(), 'Healthy schedule omitted.');
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

foreach (array('Annual' => 'What does an Annual Observer Pass cost?', 'Daily' => 'How much is a Daily Observer Pass?', 'Annual and Daily' => 'What do Observer Passes cost?') as $label => $question) {
	oras_ai_test('M9 Task 3I no product facts retains price intent ' . $label, function () use ($label, $question): void {
		oras_ai_test_reset();
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), oras_ai_task3i_live(array('woo')));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $question));
		foreach (explode(' and ', $label) as $option) { oras_ai_assert_contains($option . ' Observer Pass price', $result->answer(), 'Pass identity or price intent lost.'); }
		oras_ai_assert_not_contains('availability', $result->answer(), 'Price uncertainty was replaced by unrelated availability uncertainty.');
		oras_ai_assert_same(0, count($provider->calls), 'Unavailable product dispatched answer generation.');
	});
}

oras_ai_test('M9 Task 3I price-only success does not introduce unrequested availability uncertainty', function (): void {
	oras_ai_test_reset();
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), oras_ai_task3i_live());
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What does an Annual Observer Pass cost?'));
	oras_ai_assert_contains('45.00 USD', $result->answer(), 'Verified price omitted.');
	oras_ai_assert_not_contains('could not verify', $result->answer(), 'Unrequested fields were treated as failures.');
});

oras_ai_test('M9 Task 3I connector denial does not become scoped failure or expose healthy sibling', function (): void {
	oras_ai_test_reset();
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), oras_ai_task3i_live());
	$result = $orchestrator->answer(new ORAS_AI_Authorized_Request(91, 'What is my membership status and Annual Observer Pass price?', array('members'), false));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Denied connector became an answer.');
	oras_ai_assert_same(ORAS_AI_Answer_Orchestrator::NO_EVIDENCE_MESSAGE, $result->answer(), 'Denied data leaked through scoped assembly.');
	oras_ai_assert_same(0, count($provider->calls), 'Denied request reached answer generation.');
});

oras_ai_test('M9 Task 3I ambiguous event identity retains clarification and zero model calls', function (): void {
	oras_ai_test_reset();
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), oras_ai_task3i_live());
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'When are the next AstroBlast and Public Night?'));
	oras_ai_assert_contains('Please name the event', $result->answer(), 'Ambiguous event became a guessed subject.');
	oras_ai_assert_same(0, count($provider->calls), 'Ambiguous event reached model generation.');
});
