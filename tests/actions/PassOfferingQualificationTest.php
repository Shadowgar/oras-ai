<?php
declare(strict_types=1);

oras_ai_test('M8 Task 3 generic pass answer keeps Annual and Daily price availability and links distinct', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$annual = oras_ai_test_woo_record(array('order_history' => 'private order', 'owned_pass_record' => 'private ownership'));
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_daily_woo_record(), $annual), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Both passes cost $1 plus $10 taxes and checkout is complete.'),
		array(),
		$live
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Generic current pass answer failed.');
	oras_ai_assert_contains('Annual Observer Pass current price is 45.00 USD', $result->answer(), 'Annual current sale price was lost.');
	oras_ai_assert_contains('Daily Observer Pass current price is 12.50 USD', $result->answer(), 'Daily normal price was lost.');
	oras_ai_assert_not_contains('checkout is complete', strtolower($result->answer()), 'Model invented checkout success.');
	oras_ai_assert_not_contains('$10 taxes', $result->answer(), 'Model invented final checkout taxes.');
	oras_ai_assert_same(array('https://oras.org/product/annual-observer-pass/', 'https://oras.org/product/daily-observer-pass/'), array_column($result->sources(), 'canonical_url'), 'Annual and Daily links were merged or reassigned.');
	oras_ai_assert_same(array('Annual Observer Pass', 'Daily Observer Pass'), array_column($result->sources(), 'source_title'), 'Pass handoff labels were ambiguous or reassigned.');
	$input = wp_json_encode($provider->calls[0]['context']->provider_input());
	foreach (array('private order', 'private ownership', 'private billing data', 'tok_private', 'member@example.test', 'private cart') as $private) {
		oras_ai_assert_not_contains($private, $input, 'Raw Woo or customer information reached model context.');
	}
	oras_ai_assert_same(array('observer-pass'), $lookups, 'Model or question chose a Woo product identifier.');
	oras_ai_assert_same(1, count($provider->calls), 'Generic qualification added a model call.');
});

oras_ai_test('M8 Task 3 out-of-stock pass stays unavailable even when Woo purchasability is true', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_daily_woo_record(array(
		'stock_status' => 'outofstock', 'in_stock' => false, 'purchasable' => true,
	))), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Buy Daily now.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'Is a Daily Observer Pass available?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Current out-of-stock state was lost.');
	oras_ai_assert_contains('not currently purchasable', $result->answer(), 'Out-of-stock pass was offered despite sale-blocking stock.');
	oras_ai_assert_not_contains('Buy Daily now', $result->answer(), 'Model overrode Woo stock.');
});

oras_ai_test('M8 Task 3 qualified Woo backorder remains a member-driven handoff', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_daily_woo_record(array(
		'stock_status' => 'onbackorder', 'in_stock' => true, 'purchasable' => true,
	))), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Your Daily pass was purchased.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'Is a Daily Observer Pass available?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified backorder state was lost.');
	oras_ai_assert_contains('currently purchasable', $result->answer(), 'Woo-authorized backorder was described as unavailable.');
	oras_ai_assert_not_contains('not currently purchasable', $result->answer(), 'Woo-authorized backorder was negated.');
	oras_ai_assert_contains('WooCommerce checkout', $result->answer(), 'Member checkout boundary was omitted.');
	oras_ai_assert_not_contains('purchased', $result->answer(), 'Model claimed completed purchase.');
});

oras_ai_test('M8 Task 3 malformed Annual price cannot erase Daily or qualified Annual availability', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$annual = oras_ai_test_woo_record(array('current_price' => 'not a price'));
	$daily = oras_ai_test_daily_woo_record();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array($annual, $daily), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence(array(
			'relevant_text' => 'Copied Annual price is 1.00 USD.',
			'fact_key' => '',
			'fact_keys' => array('product:observer-pass-annual:price'),
		)))),
		oras_ai_test_provider_success('Annual costs $1 and Daily is unavailable.'),
		array(),
		$live
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Malformed Annual price erased valid sibling facts.');
	oras_ai_assert_contains('Daily Observer Pass current price is 12.50 USD', $result->answer(), 'Daily current price was erased.');
	oras_ai_assert_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Qualified Annual availability was erased with its price.');
	oras_ai_assert_contains('Annual Observer Pass price', $result->answer(), 'Missing Annual price was not disclosed.');
	oras_ai_assert_not_contains('$1', $result->answer(), 'Model invented Annual price.');
	oras_ai_assert_not_contains('Copied Annual price', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Stale Annual price reached model after current price failure.');
	oras_ai_assert_same(1, count($provider->calls), 'Partial facts changed model call count.');
});

oras_ai_test('M8 Task 3 unavailable Daily does not erase current Annual handoff', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(
		oras_ai_test_woo_record(), oras_ai_test_daily_woo_record(array('status' => 'draft')),
	), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Daily and Annual are available.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Unavailable Daily erased Annual.');
	oras_ai_assert_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Annual current availability was omitted.');
	oras_ai_assert_not_contains('Daily Observer Pass is currently purchasable', $result->answer(), 'Unpublished Daily was offered.');
	oras_ai_assert_same(array('https://oras.org/product/annual-observer-pass/'), array_column($result->sources(), 'canonical_url'), 'Unavailable Daily received a handoff link.');
});

oras_ai_test('M8 Task 3 unsafe Annual URL cannot erase safe Daily link or become a handoff', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(
		oras_ai_test_woo_record(array('canonical_url' => 'https://evil.example/buy')),
		oras_ai_test_daily_woo_record(),
	), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Buy Annual at https://evil.example/buy.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Unsafe Annual URL erased safe Daily.');
	oras_ai_assert_contains('Daily Observer Pass is currently purchasable', $result->answer(), 'Safe Daily handoff was lost.');
	oras_ai_assert_not_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Unsafe Annual handoff was offered.');
	oras_ai_assert_not_contains('evil.example', wp_json_encode($result->to_array()), 'Unsafe Annual URL escaped.');
	oras_ai_assert_same(array('https://oras.org/product/daily-observer-pass/'), array_column($result->sources(), 'canonical_url'), 'Unsafe Annual link reached sources.');
});

oras_ai_test('M8 Task 3 duplicate Annual fails boundedly while Daily remains qualified', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$annual = oras_ai_test_woo_record();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(
		$annual, array_merge($annual, array('id' => 1099)), oras_ai_test_daily_woo_record(),
	), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Annual is available now.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Duplicate Annual erased Daily.');
	oras_ai_assert_contains('Daily Observer Pass is currently purchasable', $result->answer(), 'Qualified Daily was omitted.');
	oras_ai_assert_not_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Duplicate Annual was arbitrarily selected.');
	oras_ai_assert_same(array('https://oras.org/product/daily-observer-pass/'), array_column($result->sources(), 'canonical_url'), 'Duplicate Annual produced a link.');
});

oras_ai_test('M8 Task 3 missing Daily never substitutes a copied price beside current Annual', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_woo_record()), $lookups));
	$stale_daily = oras_ai_test_answer_evidence(array(
		'relevant_text' => 'Copied Daily price is 2.00 USD.',
		'fact_key' => '',
		'fact_keys' => array('product:observer-pass-daily:price'),
	));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(array($stale_daily)), oras_ai_test_provider_success('Daily costs 2.00 USD.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Missing Daily erased Annual.');
	oras_ai_assert_contains('Annual Observer Pass current price is 45.00 USD', $result->answer(), 'Current Annual was lost.');
	oras_ai_assert_not_contains('Daily costs', $result->answer(), 'Missing Daily became a current price.');
	oras_ai_assert_not_contains('Copied Daily price', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Stale Daily price reached model.');
});

oras_ai_test('M8 Task 3 many failed fields still block every matching stale product fact', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$annual = oras_ai_test_woo_record(array(
		'current_price' => 'invalid', 'stock_status' => 'invalid', 'purchasable' => 'invalid',
	));
	$daily = oras_ai_test_daily_woo_record(array(
		'stock_status' => 'invalid', 'purchasable' => 'invalid',
	));
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array($annual, $daily), $lookups));
	$stale = oras_ai_test_answer_evidence(array(
		'relevant_text' => 'Copied Daily pass is available and purchasable.',
		'fact_key' => '',
		'fact_keys' => array('product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable'),
	));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(array($stale)), oras_ai_test_provider_success('Daily is available.'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(81, 'What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified Daily price was erased by sibling failures.');
	oras_ai_assert_contains('Daily Observer Pass current price is 12.50 USD', $result->answer(), 'Qualified Daily price was lost.');
	oras_ai_assert_not_contains('Copied Daily pass', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Failed Daily fields allowed stale facts into model context.');
	oras_ai_assert_contains('could not verify whether', $result->answer(), 'Failed availability was not disclosed.');
	oras_ai_assert_not_contains('WooCommerce checkout', $result->answer(), 'Failed availability became a purchase handoff.');
});

oras_ai_test('M8 Task 3 partial operational failure counts safely while absent sibling does not', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$lookups = array();
	$service = oras_ai_test_observed_service(array(oras_ai_test_woo_connector(array(
		oras_ai_test_woo_record(array('current_price' => 'private invalid price payload')),
		oras_ai_test_daily_woo_record(),
	), $lookups)), $observer);
	$result = $service->query(oras_ai_test_woo_request('What Observer Passes are available and how much do they cost?'));
	oras_ai_assert_true($result->successful(), 'Healthy Daily facts were erased by Annual price failure.');
	$state = $observer->snapshot()['woocommerce'];
	oras_ai_assert_same(1, $state['failure_count'], 'Partial invalid current price was not counted.');
	oras_ai_assert_same('invalid_product_price', $state['last_failure_reason'], 'Partial failure reason was not bounded.');
	oras_ai_assert_not_contains('private invalid price payload', wp_json_encode($observer->snapshot()), 'Raw product value entered connector health.');

	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$lookups = array();
	$service = oras_ai_test_observed_service(array(oras_ai_test_woo_connector(array(oras_ai_test_woo_record()), $lookups)), $observer);
	oras_ai_assert_true($service->query(oras_ai_test_woo_request('What Observer Passes are available and how much do they cost?'))->successful(), 'Valid Annual was erased by absent Daily.');
	oras_ai_assert_same(0, $observer->snapshot()['woocommerce']['failure_count'], 'Missing Daily was counted as operational failure.');
});
