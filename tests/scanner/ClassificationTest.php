<?php
declare(strict_types=1);

function oras_ai_test_deterministic_result(string $postType, string $title, string $url) {
	$sourceId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_title' => $title,
			'post_content' => 'Source content',
		)
	);
	update_post_meta($sourceId, '_oras_ai_wp_post_type', $postType);
	update_post_meta($sourceId, '_oras_ai_source_url', $url);
	return oras_ai_invoke_private(new ORAS_AI_Sources(), 'deterministic_classification', array(get_post($sourceId)));
}

oras_ai_test('tribe_events remains deterministic public live data', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('tribe_events', 'AstroBlast 2026', 'https://oras.org/events/astroblast-2026/');
	oras_ai_assert_same('live_data', $result['source_kind'], 'Event source kind changed.');
	oras_ai_assert_same('AstroBlast', $result['category'], 'Event category heuristic changed.');
	oras_ai_assert_same('public', $result['visibility'], 'Event visibility changed.');
	oras_ai_assert_same('high', $result['confidence'], 'Event confidence changed.');
	oras_ai_assert_same('rule', $result['classified_by'], 'Event classification method changed.');
});

oras_ai_test('WooCommerce product remains deterministic public live data', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('product', 'Observer Pass', 'https://oras.org/product/observer-pass/');
	oras_ai_assert_same('live_data', $result['source_kind'], 'Product source kind changed.');
	oras_ai_assert_same('Observer Passes', $result['category'], 'Product category heuristic changed.');
	oras_ai_assert_same('public', $result['visibility'], 'Product visibility changed.');
});

oras_ai_test('WordPress template record types remain ignored admin utility content', function (): void {
	foreach (array('elementor_library', 'mailpoet_page', 'gm_menu_block') as $postType) {
		oras_ai_test_reset();
		$result = oras_ai_test_deterministic_result($postType, 'Template', 'https://oras.org/template/');
		oras_ai_assert_same('ignore', $result['source_kind'], "{$postType} source kind changed.");
		oras_ai_assert_same('Website / Technical Help', $result['category'], "{$postType} category changed.");
		oras_ai_assert_same('admin', $result['visibility'], "{$postType} visibility changed.");
		oras_ai_assert_same('rule', $result['classified_by'], "{$postType} classification method changed.");
	}
});

oras_ai_test('known utility page paths remain ignored with admin visibility', function (): void {
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
		$result = oras_ai_test_deterministic_result('page', 'Utility page', $url);
		oras_ai_assert_same('ignore', $result['source_kind'], "Utility detection changed for {$url}.");
		oras_ai_assert_same('admin', $result['visibility'], "Utility visibility changed for {$url}.");
	}
});

oras_ai_test('privacy and security pages have no deterministic v0.2.1 classification', function (): void {
	foreach (array('privacy-policy', 'security-policy') as $slug) {
		oras_ai_test_reset();
		$result = oras_ai_test_deterministic_result('page', ucwords(str_replace('-', ' ', $slug)), 'https://oras.org/' . $slug . '/');
		oras_ai_assert_same(null, $result, "{$slug} should continue to fall through to AI classification in v0.2.1.");
	}
});

oras_ai_test('ORAS speaker remains deterministic static public knowledge', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('oras_speaker', 'Dr. Nova', 'https://oras.org/speakers/dr-nova/');
	oras_ai_assert_same('static_knowledge', $result['source_kind'], 'Speaker source kind changed.');
	oras_ai_assert_same('Events', $result['category'], 'Speaker category changed.');
	oras_ai_assert_same('public', $result['visibility'], 'Speaker visibility changed.');
	oras_ai_assert_same('high', $result['confidence'], 'Speaker confidence changed.');
});

oras_ai_test('current deterministic category heuristics retain priority and fallback', function (): void {
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
	$sources = new ORAS_AI_Sources();
	foreach ($cases as $case) {
		$result = oras_ai_invoke_private($sources, 'deterministic_category', array($case[0], $case[1], 'Events'));
		oras_ai_assert_same($case[2], $result, 'Category heuristic changed for ' . $case[0] . '.');
	}
});
