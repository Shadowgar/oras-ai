<?php
declare(strict_types=1);

oras_ai_test('source classification rules expose stable version-one metadata semantics', function (): void {
	oras_ai_test_reset();
	$rules = new ORAS_AI_Source_Classification_Rules();

	oras_ai_assert_same(1, ORAS_AI_Source_Classification_Rules::VERSION, 'Current deterministic rule version changed.');
	oras_ai_assert_same('_oras_ai_rule_version', ORAS_AI_Source_Classification_Rules::META_RULE_VERSION, 'Rule-version meta key changed.');
	oras_ai_assert_same(1, $rules->version(), 'Rules component version changed.');
	oras_ai_assert_same(1, $rules->effective_version(''), 'Missing version must remain compatible with legacy version one.');
	oras_ai_assert_same(0, $rules->effective_version('0'), 'Explicit stale version zero must remain stale.');
	oras_ai_assert_same(999, $rules->effective_version('999'), 'Explicit future fixture version must remain distinct.');
});

oras_ai_test('rules classify tribe events as current public live data', function (): void {
	oras_ai_test_reset();
	$result = (new ORAS_AI_Source_Classification_Rules())->classify(
		'tribe_events',
		'AstroBlast 2026',
		'https://oras.org/events/astroblast-2026/'
	);

	oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'Rule must return the application result.');
	oras_ai_assert_same('live_data', $result->source_kind(), 'Event source kind changed.');
	oras_ai_assert_same('AstroBlast', $result->category(), 'Event category changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Event visibility changed.');
	oras_ai_assert_same('high', $result->confidence(), 'Event confidence changed.');
	oras_ai_assert_same('rule', $result->classified_by(), 'Event classifier marker changed.');
});

oras_ai_test('rules classify products as current public live data', function (): void {
	oras_ai_test_reset();
	$result = (new ORAS_AI_Source_Classification_Rules())->classify(
		'product',
		'Observer Pass',
		'https://oras.org/product/observer-pass/'
	);

	oras_ai_assert_same('live_data', $result->source_kind(), 'Product source kind changed.');
	oras_ai_assert_same('Observer Passes', $result->category(), 'Product category changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Product visibility changed.');
	oras_ai_assert_same('rule', $result->classified_by(), 'Product classifier marker changed.');
});

oras_ai_test('rules keep current WordPress template types ignored for administrators', function (): void {
	foreach (array('elementor_library', 'mailpoet_page', 'gm_menu_block') as $post_type) {
		oras_ai_test_reset();
		$result = (new ORAS_AI_Source_Classification_Rules())->classify(
			$post_type,
			'Template',
			'https://oras.org/template/'
		);

		oras_ai_assert_same('ignore', $result->source_kind(), "{$post_type} source kind changed.");
		oras_ai_assert_same('Website / Technical Help', $result->category(), "{$post_type} category changed.");
		oras_ai_assert_same('admin', $result->visibility(), "{$post_type} visibility changed.");
		oras_ai_assert_same('rule', $result->classified_by(), "{$post_type} classifier marker changed.");
	}
});

oras_ai_test('rules keep known utility page paths ignored for administrators', function (): void {
	$paths = array(
		'https://oras.org/cart/',
		'https://oras.org/membership-account/membership-billing/',
		'https://oras.org/members-hub/equipment-exchange/my-listings/',
		'https://oras.org/order/checkout/review/',
		'https://oras.org/form/confirmation/123/',
		'https://oras.org/donation/thank-you/',
	);

	foreach ($paths as $url) {
		oras_ai_test_reset();
		$result = (new ORAS_AI_Source_Classification_Rules())->classify('page', 'Utility page', $url);
		oras_ai_assert_same('ignore', $result->source_kind(), "Utility detection changed for {$url}.");
		oras_ai_assert_same('admin', $result->visibility(), "Utility visibility changed for {$url}.");
	}
});

oras_ai_test('rules keep ORAS speakers as static public event knowledge', function (): void {
	oras_ai_test_reset();
	$result = (new ORAS_AI_Source_Classification_Rules())->classify(
		'oras_speaker',
		'Dr. Nova',
		'https://oras.org/speakers/dr-nova/'
	);

	oras_ai_assert_same('static_knowledge', $result->source_kind(), 'Speaker source kind changed.');
	oras_ai_assert_same('Events', $result->category(), 'Speaker category changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Speaker visibility changed.');
	oras_ai_assert_same('high', $result->confidence(), 'Speaker confidence changed.');
	oras_ai_assert_same('rule', $result->classified_by(), 'Speaker classifier marker changed.');
});

oras_ai_test('rules preserve current deterministic category priority and fallback', function (): void {
	$cases = array(
		array('AstroBlast Membership', 'https://oras.org/member/', 'AstroBlast'),
		array('Public Night', 'https://oras.org/events/', 'Public Nights'),
		array('Observer Pass', 'https://oras.org/passes/', 'Observer Passes'),
		array('Member handbook', 'https://oras.org/handbook/', 'Membership'),
		array('Telescope Loan', 'https://oras.org/equipment/', 'Telescopes & Equipment'),
		array('Donate', 'https://oras.org/give/', 'Payments / Treasurer'),
		array('Access', 'https://oras.org/observatory/', 'Observatory Access'),
		array('Unmatched', 'https://oras.org/other/', 'Events'),
	);
	$rules = new ORAS_AI_Source_Classification_Rules();

	foreach ($cases as $case) {
		oras_ai_assert_same($case[2], $rules->category_for($case[0], $case[1], 'Events'), 'Category changed for ' . $case[0] . '.');
	}
});

oras_ai_test('rules leave unmatched privacy and security pages for AI classification', function (): void {
	$rules = new ORAS_AI_Source_Classification_Rules();

	oras_ai_assert_same(null, $rules->classify('page', 'Ordinary page', 'https://oras.org/ordinary/'), 'Unmatched page should remain unmatched.');
	oras_ai_assert_same(null, $rules->classify('page', 'Privacy Policy', 'https://oras.org/privacy-policy/'), 'Privacy policy should remain unmatched.');
	oras_ai_assert_same(null, $rules->classify('page', 'Security Policy', 'https://oras.org/security-policy/'), 'Security policy should remain unmatched.');
});
