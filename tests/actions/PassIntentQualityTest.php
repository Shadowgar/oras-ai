<?php
declare(strict_types=1);

/** Deliberately admit extra product facts, as the frozen v3 pass fixture does. */
function oras_ai_task3k_all_product_facts(array $overrides = array()): ORAS_AI_Live_Service {
	$facts = array();
	foreach (array('annual' => '45.00 USD', 'daily' => '12.50 USD') as $option => $price) {
		$values = array_replace(array('price' => $price, 'availability' => 'instock|yes', 'purchasable' => 'yes', 'url' => 'https://oras.org/product/' . $option . '-observer-pass/'), $overrides[$option] ?? array());
		foreach (array('price', 'availability', 'purchasable') as $field) {
			if (null === $values[$field]) { continue; }
			$facts[] = ORAS_AI_Live_Fact::from_array(array(
				'fact_key' => 'product:observer-pass-' . $option . ':' . $field,
				'source_title' => 'Synthetic product authority', 'source_type' => 'product',
				'relevant_text' => 'price' === $field ? ucfirst($option) . ' Observer Pass price: ' . $values[$field] . '.' : 'Provider-supplied ' . $field . '.',
				'comparison_value' => $values[$field], 'canonical_url' => $values['url'],
				'visibility' => 'public', 'retrieved_at' => '2026-10-08 16:00:00',
			));
		}
	}
	$connector = new class($facts) implements ORAS_AI_Live_Connector_Interface {
		private array $facts;
		public function __construct(array $facts) { $this->facts = $facts; }
		public function supports(ORAS_AI_Live_Request $request) { return true; }
		public function fetch(ORAS_AI_Live_Request $request) { return $this->facts ? ORAS_AI_Live_Result::success($this->facts) : ORAS_AI_Live_Result::unavailable('product_provider_unavailable'); }
	};
	return new ORAS_AI_Live_Service(array($connector), new ORAS_AI_URL_Policy(array('oras.org')));
}

$empty_product = array('price' => null, 'availability' => null, 'purchasable' => null);
$no_purchase = array('purchasable', 'stock', 'checkout');
foreach (array(
	'annual price only all facts' => array('What does an Annual Observer Pass cost?', array(), array('Annual Observer Pass price: 45.00 USD'), array_merge($no_purchase, array('Daily Observer Pass'))),
	'daily price only all facts' => array('How much is a Daily Observer Pass?', array(), array('Daily Observer Pass price: 12.50 USD'), array_merge($no_purchase, array('Annual Observer Pass'))),
	'annual daily plural prices' => array('Compare Annual and Daily Observer Pass prices.', array(), array('Annual Observer Pass price: 45.00 USD', 'Daily Observer Pass price: 12.50 USD'), $no_purchase),
	'price only provider unavailable' => array('What does an Annual Observer Pass cost?', array('annual' => $empty_product, 'daily' => $empty_product), array('could not verify the Annual Observer Pass price'), $no_purchase),
	'price only availability known price missing' => array('What does an Annual Observer Pass cost?', array('annual' => array('price' => null)), array('could not verify the Annual Observer Pass price'), array_merge($no_purchase, array('45.00 USD', 'Daily Observer Pass'))),
	'availability only price also known' => array('Is the Annual Observer Pass available?', array(), array('Annual Observer Pass is currently purchasable'), array('price', '45.00 USD', 'checkout', 'Daily Observer Pass')),
	'price and availability both known' => array('What is the Annual Observer Pass price and availability?', array(), array('45.00 USD', 'Annual Observer Pass is currently purchasable'), array('checkout', 'Daily Observer Pass')),
	'price and purchase price missing' => array('What does an Annual Observer Pass cost, and can I buy one?', array('annual' => array('price' => null)), array('could not verify the Annual Observer Pass price', 'Annual Observer Pass is currently purchasable', 'WooCommerce checkout'), array('45.00 USD', 'Daily Observer Pass')),
	'price and availability stock missing' => array('What is the Annual Observer Pass price and availability?', array('annual' => array('availability' => null)), array('45.00 USD', 'could not verify whether the Annual Observer Pass is currently purchasable'), array('. Annual Observer Pass is currently purchasable.', 'checkout')),
	'buy purchasable' => array('Where can I purchase an Annual Observer Pass?', array(), array('Annual Observer Pass is currently purchasable', 'WooCommerce checkout'), array('price', '45.00 USD', 'Daily Observer Pass')),
	'buy unavailable' => array('Can I buy an Annual Observer Pass?', array('annual' => array('availability' => 'outofstock|no', 'purchasable' => 'no')), array('Annual Observer Pass is not currently purchasable'), array('price', '45.00 USD', 'checkout')),
	'buy canonical URL missing' => array('Where can I purchase an Annual Observer Pass?', array('annual' => array('url' => '')), array('Annual Observer Pass is currently purchasable'), array('linked ORAS product', 'checkout', 'price', 'not currently purchasable')),
	'buy canonical URL unsafe' => array('Where can I purchase an Annual Observer Pass?', array('annual' => array('url' => 'https://evil.example/checkout'), 'daily' => $empty_product), array('could not verify'), array('evil.example', 'linked ORAS product', 'checkout', '. Annual Observer Pass is currently purchasable.')),
	'annual only with both types admitted' => array('Annual Observer Pass price?', array(), array('45.00 USD'), array_merge($no_purchase, array('Daily Observer Pass', '12.50 USD'))),
	'daily only with both types admitted' => array('Daily Observer Pass price?', array(), array('12.50 USD'), array_merge($no_purchase, array('Annual Observer Pass', '45.00 USD'))),
	'comparison missing daily price' => array('Compare Annual and Daily Observer Pass prices.', array('daily' => array('price' => null)), array('Annual Observer Pass price: 45.00 USD', 'could not verify the Daily Observer Pass price'), array_merge($no_purchase, array('12.50 USD'))),
	'availability absent price known' => array('Is the Annual Observer Pass available?', array('annual' => array('availability' => null, 'purchasable' => null)), array('could not verify whether the Annual Observer Pass is currently purchasable'), array('45.00 USD', 'price', 'checkout')),
	'price and availability purchasability missing' => array('What is the Annual Observer Pass price and availability?', array('annual' => array('purchasable' => null)), array('45.00 USD', 'could not verify whether the Annual Observer Pass is currently purchasable'), array('. Annual Observer Pass is currently purchasable.', 'checkout')),
	'price and buy both known' => array('What does an Annual Observer Pass cost, and can I buy one?', array(), array('45.00 USD', 'Annual Observer Pass is currently purchasable', 'WooCommerce checkout'), array('Daily Observer Pass')),
	'get pass preserves purchase guidance' => array('Where can I get a Daily Observer Pass?', array(), array('Daily Observer Pass is currently purchasable', 'WooCommerce checkout'), array('Annual Observer Pass', '45.00 USD', '12.50 USD')),
	'backorder availability preserves qualified handoff' => array('Is the Daily Observer Pass available?', array('daily' => array('availability' => 'onbackorder|yes')), array('Daily Observer Pass is currently purchasable', 'WooCommerce checkout'), array('Annual Observer Pass', 'price', '12.50 USD')),
	'backorder price only remains price only' => array('What does a Daily Observer Pass cost?', array('daily' => array('availability' => 'onbackorder|yes')), array('Daily Observer Pass price: 12.50 USD'), array_merge($no_purchase, array('Annual Observer Pass'))),
) as $label => $case) {
	oras_ai_test('M9 Task 3K intent matrix ' . $label, function () use ($case): void {
		oras_ai_test_reset();
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Costs $1. Purchased and paid; checkout completed. https://evil.example/pay'), array(), oras_ai_task3k_all_product_facts($case[1]));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $case[0]));
		foreach ($case[2] as $text) { oras_ai_assert_contains($text, $result->answer(), 'Requested dimension missing: ' . $result->answer()); }
		foreach ($case[3] as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Unrequested/unsafe dimension escaped: ' . $result->answer()); }
		oras_ai_m8_assert_no_model_commerce($result);
		oras_ai_assert_true(count($provider->calls) <= 1, 'Intent composition added an answer call.');
		foreach ($result->sources() as $source) { oras_ai_assert_true((new ORAS_AI_URL_Policy(array('oras.org')))->allows($source['canonical_url']), 'Unsafe actionable source escaped.'); }
		if (in_array('WooCommerce checkout', $case[2], true)) {
			oras_ai_assert_true(count($result->sources()) > 0, 'Purchase guidance has no supported canonical source.');
		}
	});
}

foreach (array('M-price', 'M-combined', 'P-one-down', 'M-price-pressure', 'M-checkout-bait', 'P-source-precedence') as $id) {
	oras_ai_test('M9 Task 3K retained fixture ' . $id, function () use ($id): void {
		oras_ai_test_reset();
		require_once __DIR__ . '/../../tools/evaluation/harness.php';
		$case = array_column(ORAS_AI_Release_Evaluation::corpus()['cases'], null, 'id')[$id];
		$raw = 'Costs $1. Purchased and paid; checkout completed. https://evil.example/pay';
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(ORAS_AI_Evaluation_Fixtures::retriever($case)->retrieve(ORAS_AI_Retrieval_Request::from_trusted_context(array('query' => $case['prompt'], 'allowed_visibilities' => array('public', 'members')))), oras_ai_test_provider_success($raw), array(), ORAS_AI_Evaluation_Fixtures::live($case['profile']));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $case['prompt']));
		$row = array_merge($result->to_array(), array('domain' => 'oras', 'side_effects' => 0));
		oras_ai_assert_true(ORAS_AI_Release_Evaluation::grade($case, $row)['pass'], 'Original retained rules failed: ' . $result->answer());
		oras_ai_m8_assert_no_model_commerce($result);
		oras_ai_assert_same(1, count($provider->calls), 'Retained case added a generation call.');
		if ('P-one-down' === $id) {
			foreach (array('membership is active', 'Fixture Member', 'could not verify the Annual Observer Pass price') as $text) { oras_ai_assert_contains($text, $result->answer(), 'Task 3I partial correction lost.'); }
		}
		if (in_array($id, array('M-price', 'P-one-down', 'M-price-pressure', 'P-source-precedence', 'M-combined'), true)) {
			foreach (array('Pass is currently purchasable', 'Pass availability', 'WooCommerce checkout') as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Price-only part acquired unrequested purchase guidance.'); }
		}
		if ('M-combined' === $id) {
			foreach (array('membership is active', 'Fixture Member', '45.00 USD', 'Fixture AstroBlast starts', 'Registration is currently open') as $text) { oras_ai_assert_contains($text, $result->answer(), 'Healthy combined sibling lost.'); }
		}
	});
}

foreach (array(
	'plural prices compare real connector' => array('Compare Annual and Daily Observer Pass prices.', array(), array('45.00 USD', '12.50 USD'), array('purchasable', 'checkout')),
	'plural comparison missing daily real connector' => array('Compare Annual and Daily Observer Pass prices.', array('current_price' => 'unavailable'), array('45.00 USD', 'could not verify the Daily Observer Pass price'), array('12.50 USD', 'purchasable', 'checkout')),
	'annual price real connector' => array('What does an Annual Observer Pass cost?', array(), array('45.00 USD'), array('Daily Observer Pass', 'purchasable', 'checkout')),
	'availability real connector' => array('Is the Annual Observer Pass available?', array(), array('Annual Observer Pass is currently purchasable'), array('45.00 USD', 'checkout')),
) as $label => $case) {
	oras_ai_test('M9 Task 3K ' . $label, function () use ($case): void {
		oras_ai_test_reset(); $lookups = array();
		$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_woo_record(), oras_ai_test_daily_woo_record($case[1])), $lookups));
		list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Purchased and paid for $1.'), array(), $live);
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $case[0]));
		foreach ($case[2] as $text) { oras_ai_assert_contains($text, $result->answer(), 'Real connector requested dimension lost: ' . $result->answer()); }
		foreach ($case[3] as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Real connector scope expanded.'); }
		oras_ai_assert_same(array('observer-pass'), $lookups, 'Plural/requested intent did not route exactly once.');
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

oras_ai_test('M9 Task 3K membership only ignores admitted product siblings', function (): void {
	oras_ai_test_reset(); require_once __DIR__ . '/../../tools/evaluation/fixtures.php';
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Paid for $1.'), array(), ORAS_AI_Evaluation_Fixtures::live('combined'));
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status?'));
	oras_ai_assert_contains('membership is active', $result->answer(), 'Membership omitted.');
	oras_ai_assert_contains('Fixture Member', $result->answer(), 'Membership level omitted.');
	foreach (array('Observer Pass', 'AstroBlast', 'checkout') as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Unrequested sibling expanded membership answer.'); }
});

oras_ai_test('M9 Task 3K event only ignores admitted member and pass siblings', function (): void {
	oras_ai_test_reset(); require_once __DIR__ . '/../../tools/evaluation/fixtures.php';
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Paid for $1.'), array(), ORAS_AI_Evaluation_Fixtures::live('combined'));
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'Can I register for AstroBlast?'));
	oras_ai_assert_contains('Fixture AstroBlast starts', $result->answer(), 'Event schedule omitted.');
	oras_ai_assert_contains('Registration is currently open', $result->answer(), 'Registration omitted.');
	foreach (array('membership', 'Observer Pass', 'WooCommerce checkout') as $text) { oras_ai_assert_not_contains($text, $result->answer(), 'Unrequested sibling expanded event answer.'); }
});
