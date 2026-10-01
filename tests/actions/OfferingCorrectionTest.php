<?php
declare(strict_types=1);

/** Exercise real normalization/orchestration; substitute only external provider reads/output. */
function oras_ai_m8_correction_live(array &$calls, string $failure = '', string $eventState = 'open', string $eventUrl = 'https://oras.org/events/astroblast-2026/'): ORAS_AI_Live_Service {
	$pmpro = new ORAS_AI_PMPro_Context_Connector(static function ($userId) use (&$calls, $failure) {
		$calls['pmpro'][] = $userId;
		return 'pmpro' === $failure ? new WP_Error('oras_ai_pmpro_unavailable') : array(oras_ai_test_pmpro_level('Family'));
	});
	$woo = new ORAS_AI_WooCommerce_Connector(static function ($subject) use (&$calls, $failure) {
		$calls['woo'][] = $subject;
		return 'woo' === $failure ? new WP_Error('oras_ai_woocommerce_unavailable') : array(oras_ai_test_woo_record(), oras_ai_test_daily_woo_record());
	});
	$events = new ORAS_AI_Events_Calendar_Connector(
		static function ($subject) use (&$calls, $eventUrl): array {
			$calls['tec'][] = $subject;
			return array(oras_ai_test_event_record(array('start' => '2026-10-18 20:00:00', 'end' => '2026-10-18 23:00:00', 'canonical_url' => $eventUrl)));
		},
		static fn(): string => '2026-10-01T12:00:00-04:00',
		static function ($id) use (&$calls, $failure, $eventState) {
			$calls['offerings'][] = $id;
			return 'event' === $failure ? new WP_Error('provider_down') : array(array('state' => $eventState, 'attendees' => array('private-attendee@example.test'), 'purchaser' => 'private purchaser'));
		}
	);
	return new ORAS_AI_Live_Service(array($pmpro, $woo, $events), new ORAS_AI_URL_Policy(array('oras.org')));
}

function oras_ai_m8_assert_no_model_commerce(ORAS_AI_Answer_Result $result): void {
	foreach (array('$1', '1.00 USD', 'invented tax', 'paid', 'purchased', 'order completed', 'checkout completed', 'evil.example') as $hostile) {
		oras_ai_assert_not_contains($hostile, strtolower($result->answer()), 'Model commerce prose escaped: ' . $hostile);
	}
}

foreach (array(
	'How much is an Observer Pass?' => array('45.00 USD', '12.50 USD'),
	'What does an Annual Observer Pass cost?' => array('45.00 USD'),
	'Price of Daily Observer Pass' => array('12.50 USD'),
	'Price of Annual Observer Pass' => array('45.00 USD'),
	'Can I get an Observer Pass?' => array('Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable'),
	'Where can I get an Observer Pass?' => array('Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable'),
	'Where can I buy the Annual Observer Pass?' => array('Annual Observer Pass is currently purchasable'),
	'What Observer Passes are available?' => array('Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable'),
) as $question => $expected) {
	oras_ai_test('M8 correction price/payment boundary: ' . $question, function () use ($question, $expected): void {
		oras_ai_test_reset(); $calls = array();
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Costs $1 / 1.00 USD plus invented tax. Purchased and paid; order completed, checkout completed. Use https://evil.example/pay.'), array(), oras_ai_m8_correction_live($calls));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $question));
		oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified pass request failed.');
		foreach ($expected as $text) { oras_ai_assert_contains($text, $result->answer(), 'Authoritative offering fact missing.'); }
		oras_ai_m8_assert_no_model_commerce($result);
		oras_ai_assert_same(array('observer-pass'), $calls['woo'], 'Pass normalization was repeated or selected by untrusted ID.');
		oras_ai_assert_same(1, count($provider->calls), 'Offering boundary added a model call.');
		$input = wp_json_encode($provider->calls[0]['context']->provider_input());
		oras_ai_assert_true(strlen($input) <= 16000, 'Context bound expanded.');
		oras_ai_assert_not_contains('private billing data', $input, 'Private Woo fields entered context.');
	});
}

oras_ai_test('M8 correction pass intent stays bounded to explicit Observer Pass context', function (): void {
	$lookups = array(); $woo = oras_ai_test_woo_connector(array(oras_ai_test_woo_record()), $lookups);
	foreach (array('How much is a telescope?', 'Where can I get one?', 'What does parking cost?') as $question) {
		oras_ai_assert_false($woo->supports(oras_ai_test_woo_request($question)), 'Unrelated price/coreference query selected a pass.');
	}
	oras_ai_assert_same(array(), $lookups, 'Unsupported intent performed a product lookup.');
});

foreach (array(
	'membership + pass' => array('What is my membership status, and what Observer Passes can I buy?', array('Current membership status: active', 'Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable'), 2),
	'pass + event' => array('Can I buy an Annual Observer Pass and register for AstroBlast?', array('Annual Observer Pass is currently purchasable', 'AstroBlast 2026 starts', 'Registration is currently open'), 2),
	'membership + event' => array('What is my membership status, and can I register for AstroBlast?', array('Current membership status: active', 'AstroBlast 2026 starts', 'Registration is currently open'), 1),
	'all domains' => array('What is my membership status, what Observer Passes can I buy, and can I register for AstroBlast?', array('Current membership status: active', 'Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable', 'AstroBlast 2026 starts', 'Registration is currently open'), 3),
) as $label => $case) {
	oras_ai_test('M8 correction composition ' . $label, function () use ($case): void {
		oras_ai_test_reset(); $calls = array();
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Purchased and paid. https://evil.example/pay'), array(), oras_ai_m8_correction_live($calls));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, $case[0]));
		foreach ($case[1] as $text) { oras_ai_assert_contains($text, $result->answer(), 'Qualified sibling domain was overwritten.'); }
		oras_ai_assert_same($case[2], count($result->sources()), 'Healthy domain links were lost or duplicated.');
		foreach ($calls as $lookup) { oras_ai_assert_same(1, count($lookup), 'Composition duplicated a provider read.'); }
		oras_ai_assert_same(1, count($provider->calls), 'Composition duplicated model generation.');
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

foreach (array(
	'pmpro' => array('could not verify current membership', 'Annual Observer Pass is currently purchasable', 'Daily Observer Pass is currently purchasable', 'Registration is currently open'),
	'woo' => array('Current membership status: active', 'could not verify current Observer Pass', 'Registration is currently open'),
	'event' => array('Current membership status: active', 'Annual Observer Pass is currently purchasable', 'AstroBlast 2026 starts', 'could not verify registration availability'),
) as $failed => $expected) {
	oras_ai_test('M8 correction combined failure isolation ' . $failed, function () use ($failed, $expected): void {
		oras_ai_test_reset(); $calls = array();
		$stale = array();
		foreach (array('member:self:membership-status', 'product:observer-pass-annual:purchasable', 'event:astroblast:registration') as $key) {
			$stale[] = oras_ai_test_answer_evidence(array('fact_key' => '', 'fact_keys' => array($key), 'relevant_text' => 'Copied stale claim: ' . $key));
		}
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet($stale), oras_ai_test_provider_success('Purchased and paid; https://evil.example/pay'), array(), oras_ai_m8_correction_live($calls, $failed));
		$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status, what Observer Passes can I buy, and can I register for AstroBlast?'));
		foreach ($expected as $text) { oras_ai_assert_contains($text, $result->answer(), 'Failure erased qualified sibling or missing-state disclosure.'); }
		oras_ai_assert_contains('AstroBlast 2026 starts', $result->answer(), 'Event schedule was erased.');
		oras_ai_assert_same('woo' === $failed ? 1 : 3, count($result->sources()), 'Healthy links were lost.');
		foreach ($calls as $lookup) { oras_ai_assert_same(1, count($lookup), 'Failure introduced repeated provider work.'); }
		oras_ai_assert_same(1, count($provider->calls), 'Failure added a model call.');
		oras_ai_assert_not_contains('Copied stale claim', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Failed current facts fell back to static claims.');
		oras_ai_m8_assert_no_model_commerce($result);
	});
}

foreach (array('open', 'full', 'closed') as $state) {
	foreach (array('http://192.168.50.47:8889/event/', 'javascript:alert(1)', 'https://evil.example/event/', 'not a URL') as $url) {
		oras_ai_test('M8 correction event optional unsafe link ' . $state . ' ' . $url, function () use ($state, $url): void {
			oras_ai_test_reset(); $calls = array(); $live = oras_ai_m8_correction_live($calls, '', $state, $url);
			$result = $live->query(oras_ai_test_pmpro_request('When and where is AstroBlast and can I register?'));
			oras_ai_assert_true($result->successful(), 'Unsafe link erased valid event facts.');
			$facts = oras_ai_test_facts_by_key($result);
			oras_ai_assert_same('2026-10-18T20:00:00-04:00', $facts['event:astroblast:start']->field('comparison_value'), 'Start changed.');
			oras_ai_assert_same('2026-10-18T23:00:00-04:00', $facts['event:astroblast:end']->field('comparison_value'), 'End changed.');
			oras_ai_assert_same('Bruce M. Bedow Observatory', $facts['event:astroblast:venue']->field('comparison_value'), 'Venue disappeared.');
			oras_ai_assert_same($state, $facts['event:astroblast:registration']->field('comparison_value'), 'Offering state disappeared.');
			foreach ($result->facts() as $fact) { oras_ai_assert_same('', $fact->field('canonical_url'), 'Unsafe URL retained.'); }
			oras_ai_assert_same(array(), $result->failed_fact_keys(), 'Valid event facts were marked failed because of an optional link.');
			list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Purchased and paid; https://evil.example/pay'), array(), $live);
			$answer = $orchestrator->answer(oras_ai_test_authorized_request(91, 'When and where is AstroBlast and can I register?'));
			oras_ai_assert_contains('Registration is currently ' . $state, $answer->answer(), 'Member answer lost authoritative state.');
			oras_ai_assert_contains('AstroBlast 2026 starts', $answer->answer(), 'Member answer lost schedule.');
			oras_ai_assert_same(array(), $answer->sources(), 'Rejected link produced empty or replacement Sources item.');
			oras_ai_assert_not_contains('linked ORAS event page', $answer->answer(), 'Answer recommended an absent handoff.');
			oras_ai_m8_assert_no_model_commerce($answer);
			$input = wp_json_encode($provider->calls[0]['context']->provider_input());
			oras_ai_assert_not_contains('private-attendee', $input, 'Raw attendee fields escaped.');
		});
	}
}

oras_ai_test('M8 correction unsafe event sibling preserves unrelated event and safe source', function (): void {
	oras_ai_test_reset(); $calls = array();
	$unsafe = new ORAS_AI_Events_Calendar_Connector(static fn(): array => array(oras_ai_test_event_record(array('canonical_url' => 'http://127.0.0.1/private'))), static fn(): string => '2026-09-01T12:00:00-04:00');
	$safe = new ORAS_AI_Events_Calendar_Connector(static fn(): array => array(oras_ai_test_event_record(array('id' => 902, 'title' => 'Public Night', 'canonical_url' => 'https://oras.org/events/public-night/'))), static fn(): string => '2026-09-01T12:00:00-04:00');
	$service = new ORAS_AI_Live_Service(array($unsafe, $safe), new ORAS_AI_URL_Policy(array('oras.org')));
	// Each connector's loader is trusted and title matching admits only its own subject.
	$first = $service->query(oras_ai_test_live_request('When is the next AstroBlast?'));
	$second = $service->query(oras_ai_test_live_request('When is the next Public Night?'));
	oras_ai_assert_true($first->successful(), 'Rejected AstroBlast link erased its facts.');
	oras_ai_assert_true($second->successful(), 'Unrelated Public Night was erased.');
	oras_ai_assert_same('https://oras.org/events/public-night/', $second->facts()[0]->field('canonical_url'), 'Safe sibling event link was removed.');
});

foreach (array(
	'Daily + malformed record' => array(static fn(): array => array(oras_ai_test_daily_woo_record(), null), 'daily', array('product:observer-pass-annual:price', 'product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable')),
	'Annual + malformed record' => array(static fn(): array => array(oras_ai_test_woo_record(), null), 'annual', array('product:observer-pass-daily:price', 'product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable')),
	'Daily + unknown candidate' => array(static fn(): array => array(oras_ai_test_daily_woo_record(), oras_ai_test_woo_record(array('name' => 'Observer Pass', 'slug' => 'observer-pass'))), 'daily', array('product:observer-pass-annual:price', 'product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable')),
	'Daily + duplicate Annual' => array(static fn(): array => array(oras_ai_test_daily_woo_record(), oras_ai_test_woo_record(), oras_ai_test_woo_record(array('id' => 2001))), 'daily', array('product:observer-pass-annual:price', 'product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable')),
	'Annual + duplicate Daily' => array(static fn(): array => array(oras_ai_test_woo_record(), oras_ai_test_daily_woo_record(), oras_ai_test_daily_woo_record(array('id' => 2002))), 'annual', array('product:observer-pass-daily:price', 'product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable')),
	'Annual + malformed Daily' => array(static fn(): array => array(oras_ai_test_woo_record(), oras_ai_test_daily_woo_record(array('id' => 0))), 'annual', array('product:observer-pass-daily:price', 'product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable')),
	'Daily + malformed Annual' => array(static fn(): array => array(oras_ai_test_daily_woo_record(), oras_ai_test_woo_record(array('id' => 0))), 'daily', array('product:observer-pass-annual:price', 'product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable')),
) as $label => $case) {
	oras_ai_test('M8 correction sibling isolation ' . $label, function () use ($case): void {
		oras_ai_test_reset(); $lookups = array(); $live = oras_ai_test_woo_service(oras_ai_test_woo_connector($case[0](), $lookups));
		$result = $live->query(oras_ai_test_woo_request('What Observer Passes are available and how much do they cost?'));
		oras_ai_assert_true($result->successful(), 'Bad sibling erased a healthy pass.');
		oras_ai_assert_same(array('product:observer-pass-' . $case[1] . ':price', 'product:observer-pass-' . $case[1] . ':availability', 'product:observer-pass-' . $case[1] . ':purchasable'), $result->fact_keys(), 'Unknown product was classified or duplicate selected.');
		oras_ai_assert_same($case[2], $result->failed_fact_keys(), 'Failure suppressed the healthy sibling or unrelated identities.');
		$stale = array();
		foreach (array('annual', 'daily') as $option) {
			$stale[] = oras_ai_test_answer_evidence(array('fact_key' => '', 'fact_keys' => array('product:observer-pass-' . $option . ':price'), 'relevant_text' => 'Copied ' . $option . ' stale price'));
		}
		$unrelated = oras_ai_test_answer_evidence(array('fact_key' => '', 'fact_keys' => array('observatory_access'), 'relevant_text' => 'Independent stable observatory rule.'));
		$stale[] = $unrelated;
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet($stale), oras_ai_test_provider_success('Purchased and paid for $1'), array(), $live);
		$answer = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What Observer Passes are available and how much do they cost?'));
		oras_ai_assert_contains(ucfirst($case[1]) . ' Observer Pass is currently purchasable', $answer->answer(), 'Healthy current pass omitted.');
		$input = wp_json_encode($provider->calls[0]['context']->provider_input());
		oras_ai_assert_not_contains('Copied annual stale price', $input, 'Failed/current Annual allowed stale price.');
		oras_ai_assert_not_contains('Copied daily stale price', $input, 'Failed/current Daily allowed stale price.');
		oras_ai_assert_contains('Independent stable observatory rule', $input, 'Product failure suppressed unrelated static facts.');
		oras_ai_assert_same(2, count($answer->sources()), 'Healthy pass link was lost or bad sibling given a link.');
	});
}

oras_ai_test('M8 correction malformed unknown candidate creates no failure for a healthy requested identity', function (): void {
	$lookups = array();
	$result = oras_ai_test_woo_connector(array(null, oras_ai_test_daily_woo_record()), $lookups)->fetch(oras_ai_test_woo_request('Is a Daily Observer Pass available?'));
	oras_ai_assert_true($result->successful(), 'Malformed unrelated record erased Daily.');
	oras_ai_assert_same(array(), $result->failed_fact_keys(), 'Unidentified candidate invented a failed Daily identity.');
});

oras_ai_test('M8 correction both malformed pass candidates remain bounded unavailable', function (): void {
	$lookups = array();
	$result = oras_ai_test_woo_connector(array(oras_ai_test_woo_record(array('id' => 0)), oras_ai_test_daily_woo_record(array('id' => 0))), $lookups)->fetch(oras_ai_test_woo_request('What Observer Passes are available?'));
	oras_ai_assert_false($result->successful(), 'No qualified product became usable.');
	oras_ai_assert_same(array(), $result->facts(), 'Malformed product entered context.');
});

oras_ai_test('M8 correction price field failure preserves availability and safe link beside sibling price', function (): void {
	oras_ai_test_reset(); $lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_woo_record(array('current_price' => 'bad price')), oras_ai_test_daily_woo_record()), $lookups));
	$stale = oras_ai_test_answer_evidence(array('fact_key' => '', 'fact_keys' => array('product:observer-pass-annual:price'), 'relevant_text' => 'Copied failed Annual price $1.'));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(array($stale)), oras_ai_test_provider_success('Purchased for $1'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'How much are Observer Passes and can I buy them?'));
	oras_ai_assert_contains('could not verify the Annual Observer Pass price', $result->answer(), 'Failed price not disclosed.');
	oras_ai_assert_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Independent Annual facts were erased.');
	oras_ai_assert_contains('Daily Observer Pass current price is 12.50 USD', $result->answer(), 'Sibling price erased.');
	oras_ai_assert_same(2, count($result->sources()), 'Safe field/sibling links erased.');
	oras_ai_assert_not_contains('Copied failed Annual price', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Failed price fell back to static text.');
	oras_ai_m8_assert_no_model_commerce($result);
});

oras_ai_test('M8 correction duplicate Annual is disclosed without choosing a winner beside Daily', function (): void {
	oras_ai_test_reset(); $lookups = array();
	$live = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_woo_record(), oras_ai_test_woo_record(array('id' => 2001)), oras_ai_test_daily_woo_record()), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Annual is available, purchased for $1'), array(), $live);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What Observer Passes are available?'));
	oras_ai_assert_contains('could not verify current Annual Observer Pass availability or purchasability', $result->answer(), 'Requested ambiguous sibling was silently omitted.');
	oras_ai_assert_contains('Daily Observer Pass is currently purchasable', $result->answer(), 'Qualified Daily was erased.');
	oras_ai_assert_not_contains('Annual Observer Pass is currently purchasable', $result->answer(), 'Duplicate Annual received an arbitrary winner.');
});

foreach (array('record', 'methods', 'child', 'throw', 'id-throw', 'children-throw', 'child-throw') as $malformed) {
	oras_ai_test('M8 correction native Woo isolates malformed ' . $malformed, function () use ($malformed): void {
		$output = array(); $code = 0;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/woo-native-offering-probe.php') . ' ' . escapeshellarg($malformed) . ' 2>&1', $output, $code);
		oras_ai_assert_same(0, $code, 'Native Woo probe crashed: ' . implode("\n", $output));
		$result = json_decode(implode("\n", $output), true);
		oras_ai_assert_same('success', $result['status'], 'Malformed provider sibling erased native Daily facts.');
		oras_ai_assert_same(array('product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable'), $result['keys'], 'Native sibling was invented or healthy Daily erased.');
		oras_ai_assert_same(array(), $result['failures'], 'Unrelated malformed record was mapped to Daily.');
		oras_ai_assert_same(array('Observer Pass', 'Annual Observer Pass', 'Daily Observer Pass'), $result['queries'], 'Native selection repeated a query or used untrusted IDs.');
	});
}

oras_ai_test('M8 correction malformed candidate identity cannot throw away a healthy Daily', function (): void {
	$lookups = array();
	$result = oras_ai_test_woo_connector(array(array('name' => new stdClass()), oras_ai_test_daily_woo_record()), $lookups)->fetch(oras_ai_test_woo_request('Is a Daily Observer Pass available?'));
	oras_ai_assert_true($result->successful(), 'Malformed identity escaped candidate isolation.');
	oras_ai_assert_same(array(), $result->failed_fact_keys(), 'Unknown identity was assigned to Daily.');
});

oras_ai_test('M8 correction malformed Annual URL field cannot throw away a healthy Daily', function (): void {
	$lookups = array();
	$result = oras_ai_test_woo_service(oras_ai_test_woo_connector(array(oras_ai_test_woo_record(array('canonical_url' => new stdClass())), oras_ai_test_daily_woo_record()), $lookups))->query(oras_ai_test_woo_request('What Observer Passes are available?'));
	oras_ai_assert_true($result->successful(), 'Malformed Annual field escaped field isolation.');
	oras_ai_assert_same(array('product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable'), $result->fact_keys(), 'Bad field erased or replaced Daily.');
	oras_ai_assert_same(array('product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable'), $result->failed_fact_keys(), 'Malformed field lost its exact Annual identity.');
});

oras_ai_test('M8 correction native malformed known Annual cannot hide a duplicate', function (): void {
	$output = array(); $code = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/woo-native-offering-probe.php') . ' known-duplicate 2>&1', $output, $code);
	oras_ai_assert_same(0, $code, 'Native duplicate probe crashed.');
	$result = json_decode(implode("\n", $output), true);
	oras_ai_assert_same('success', $result['status'], 'Daily was lost with bad Annual.');
	oras_ai_assert_same(array('product:observer-pass-daily:availability', 'product:observer-pass-daily:purchasable'), $result['keys'], 'Malformed known Annual was hidden to choose a duplicate winner.');
	oras_ai_assert_same(array('product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable'), $result['failures'], 'Known bad Annual lost its failed identities.');
});
