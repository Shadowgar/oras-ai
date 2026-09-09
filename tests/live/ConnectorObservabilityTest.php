<?php
declare(strict_types=1);

function oras_ai_test_observed_service(array $connectors, ORAS_AI_Connector_Observability $observability): ORAS_AI_Live_Service {
	return new ORAS_AI_Live_Service(
		$connectors,
		new ORAS_AI_URL_Policy(array('oras.org')),
		$observability
	);
}

oras_ai_test('operational connector failures increment only their bounded safe aggregate', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$throwingEvent = new ORAS_AI_Events_Calendar_Connector(
		static function (): array {
			throw new RuntimeException('raw event payload secret');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	oras_ai_test_observed_service(array($throwingEvent), $observer)->query(oras_ai_test_live_request('When is the next AstroBlast?'));

	$throwingWoo = new ORAS_AI_WooCommerce_Connector(
		static function (): array {
			throw new RuntimeException('raw Woo payload secret');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	oras_ai_test_observed_service(array($throwingWoo), $observer)->query(oras_ai_test_woo_request('How much is an Annual Observer Pass?'));

	$throwingPmpro = new ORAS_AI_PMPro_Context_Connector(
		static function (): array {
			throw new RuntimeException('raw PMPro member secret');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	oras_ai_test_observed_service(array($throwingPmpro), $observer)->query(oras_ai_test_pmpro_request('What is my membership status?'));

	$state = $observer->snapshot();
	oras_ai_assert_same(1, $state['events_calendar']['failure_count'], 'Events operational failure count changed.');
	oras_ai_assert_same('event_lookup_failed', $state['events_calendar']['last_failure_reason'], 'Events safe reason was not retained.');
	oras_ai_assert_same(1, $state['woocommerce']['failure_count'], 'Woo failure affected the wrong aggregate.');
	oras_ai_assert_same('product_lookup_failed', $state['woocommerce']['last_failure_reason'], 'Woo safe reason was not retained.');
	oras_ai_assert_same(1, $state['pmpro']['failure_count'], 'PMPro failure affected the wrong aggregate.');
	oras_ai_assert_same('membership_lookup_failed', $state['pmpro']['last_failure_reason'], 'PMPro safe reason was not retained.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Connector_Observability::OPTION], 'Connector health storage must not autoload.');

	$stored = wp_json_encode(get_option(ORAS_AI_Connector_Observability::OPTION));
	foreach (array('raw event payload secret', 'What is my membership status?', 'user_id', 'stack', 'trace') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $stored, 'Connector observability stored unsafe request or failure material.');
	}

	$successfulLookups = array();
	oras_ai_test_observed_service(array(oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $successfulLookups)), $observer)
		->query(oras_ai_test_live_request('When is the next AstroBlast?'));
	$recovered = $observer->snapshot()['events_calendar'];
	oras_ai_assert_same('healthy', $recovered['operational_state'], 'Successful call did not update current operational state.');
	oras_ai_assert_same(1, $recovered['failure_count'], 'Successful call reset the cumulative failure count.');
	oras_ai_assert_same('event_lookup_failed', $recovered['last_failure_reason'], 'Successful call erased the last bounded failure reason.');
});

oras_ai_test('valid domain outcomes including multiple active PMPro levels do not count as health failures', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$eventLookups = array();
	oras_ai_test_observed_service(array(oras_ai_test_event_adapter(array(), $eventLookups)), $observer)
		->query(oras_ai_test_live_request('When is the next AstroBlast?'));
	$wooLookups = array();
	oras_ai_test_observed_service(array(oras_ai_test_woo_connector(array(), $wooLookups)), $observer)
		->query(oras_ai_test_woo_request('How much is an Annual Observer Pass?'));
	$pmproLookups = array();
	$multipleLevels = array(oras_ai_test_pmpro_level('Member'), oras_ai_test_pmpro_level('Volunteer'));
	$pmproService = oras_ai_test_observed_service(array(oras_ai_test_pmpro_connector($multipleLevels, $pmproLookups)), $observer);
	$levelResult = $pmproService->query(oras_ai_test_pmpro_request('What is my membership level?'));

	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $levelResult->status(), 'Multiple valid PMPro levels must remain bounded unknown for a level request.');
	oras_ai_assert_same('membership_level_ambiguous', $levelResult->reason(), 'Multiple-level reason changed.');
	$state = $observer->snapshot();
	foreach (array('events_calendar', 'woocommerce', 'pmpro') as $connectorId) {
		oras_ai_assert_same(0, $state[$connectorId]['failure_count'], $connectorId . ' counted a valid domain state as operational failure.');
		oras_ai_assert_same('unknown', $state[$connectorId]['operational_state'], $connectorId . ' health changed for a non-operational outcome.');
	}
});

oras_ai_test('inactive membership absent product and denied access remain non-operational outcomes', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$pmproLookups = array();
	$inactive = oras_ai_test_observed_service(array(oras_ai_test_pmpro_connector(array(), $pmproLookups)), $observer)
		->query(oras_ai_test_pmpro_request('What is my membership status?'));
	oras_ai_assert_true($inactive->successful(), 'No active membership must remain a valid factual result.');

	$productLookups = array();
	$unavailableProduct = oras_ai_test_observed_service(
		array(oras_ai_test_woo_connector(array(oras_ai_test_woo_record(array('status' => 'draft'))), $productLookups)),
		$observer
	)->query(oras_ai_test_woo_request('How much is an Annual Observer Pass?'));
	oras_ai_assert_same('product_unavailable', $unavailableProduct->reason(), 'Legitimately unavailable product reason changed.');

	$deniedLookups = array();
	$denied = oras_ai_test_observed_service(array(oras_ai_test_pmpro_connector(array(), $deniedLookups)), $observer)
		->query(oras_ai_test_pmpro_request('What is other@example.test membership level?'));
	oras_ai_assert_same(ORAS_AI_Live_Result::DENIED, $denied->status(), 'Non-self membership request did not remain denied.');

	$state = $observer->snapshot();
	oras_ai_assert_same(0, $state['pmpro']['failure_count'], 'Inactive or denied membership counted as connector failure.');
	oras_ai_assert_same(0, $state['woocommerce']['failure_count'], 'Legitimately unavailable product counted as connector failure.');
});

oras_ai_test('invalid connector return and unsafe URL count only bounded operational reasons', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$malformed = new class implements ORAS_AI_Observable_Live_Connector_Interface {
		public function connector_id() { return 'events_calendar'; }
		public function is_available() { return true; }
		public function required_fact_keys(ORAS_AI_Live_Request $request) { return array('event:astroblast:start'); }
		public function supports(ORAS_AI_Live_Request $request) { return true; }
		public function fetch(ORAS_AI_Live_Request $request) { return array('raw' => 'vendor payload'); }
	};
	oras_ai_test_observed_service(array($malformed), $observer)->query(oras_ai_test_live_request('When is the next AstroBlast?'));

	$unsafeLookups = array();
	$unsafe = oras_ai_test_event_adapter(
		array(oras_ai_test_event_record(array('canonical_url' => 'http://127.0.0.1/private'))),
		$unsafeLookups
	);
	oras_ai_test_observed_service(array($unsafe), $observer)->query(oras_ai_test_live_request('When is the next AstroBlast?'));

	$state = $observer->snapshot()['events_calendar'];
	oras_ai_assert_same(2, $state['failure_count'], 'Operational result validation failures were not counted exactly.');
	oras_ai_assert_same('unsafe_canonical_url', $state['last_failure_reason'], 'Unsafe URL did not retain its bounded reason.');
	$stored = wp_json_encode(get_option(ORAS_AI_Connector_Observability::OPTION));
	oras_ai_assert_not_contains('vendor payload', $stored, 'Malformed connector payload entered health storage.');
	oras_ai_assert_not_contains('127.0.0.1', $stored, 'Unsafe connector URL entered health storage.');
});

oras_ai_test('connector failure storage saturates and rejects unsafe reason codes', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$observer->record_outcome('events_calendar', ORAS_AI_Live_Result::UNKNOWN, 'raw failure / user@example.test');
	oras_ai_assert_same(0, $observer->snapshot()['events_calendar']['failure_count'], 'Unsafe free-form reason was counted.');

	update_option(
		ORAS_AI_Connector_Observability::OPTION,
		array(
			'events_calendar' => array(
				'operational_state' => 'failing',
				'failure_count' => ORAS_AI_Connector_Observability::MAX_FAILURE_COUNT,
				'last_failure_reason' => 'event_lookup_failed',
				'last_failure_at' => '2026-09-08 12:00:00',
			),
		),
		false
	);
	$observer->record_outcome('events_calendar', ORAS_AI_Live_Result::UNKNOWN, 'event_data_malformed');
	$state = $observer->snapshot();
	oras_ai_assert_same(ORAS_AI_Connector_Observability::MAX_FAILURE_COUNT, $state['events_calendar']['failure_count'], 'Failure count exceeded its fixed bound.');
	oras_ai_assert_same(array('events_calendar', 'woocommerce', 'pmpro'), array_keys($state), 'Connector storage grew beyond the fixed M5 connector set.');
});

oras_ai_test('partial multi-connector failure retains healthy facts and bounded failed requirements', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$eventLookups = array();
	$events = oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $eventLookups);
	$woo = new ORAS_AI_WooCommerce_Connector(
		static function () {
			return new WP_Error('oras_ai_woocommerce_unavailable', 'raw unavailable detail');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	$service = oras_ai_test_observed_service(array($events, $woo), $observer);
	$question = 'When is the next AstroBlast and how much is an Annual Observer Pass?';
	$request = oras_ai_test_live_request($question);
	$result = $service->query($request);

	oras_ai_assert_true($result->successful(), 'Healthy Events facts were discarded because WooCommerce was unavailable.');
	oras_ai_assert_contains('event:astroblast:start', implode(',', $result->fact_keys()), 'Healthy Events fact identity was lost.');
	oras_ai_assert_same(1, count($result->failures()), 'Required Woo failure was discarded after Events succeeded.');
	oras_ai_assert_same('woocommerce', $result->failures()[0]['connector'], 'Wrong partial failure connector retained.');
	oras_ai_assert_same('woocommerce_connector_unavailable', $result->failures()[0]['reason'], 'Partial failure reason was not bounded.');
	oras_ai_assert_same(array('product:observer-pass-annual:price'), $result->failures()[0]['fact_keys'], 'Failed Woo requirement lost its fact identity.');

	$staleWoo = oras_ai_test_answer_evidence(
		array(
			'relevant_text' => 'A copied page says the Annual Observer Pass costs 30.00 USD.',
			'fact_key' => '',
			'fact_keys' => array('product:observer-pass-annual:price'),
		)
	);
	$observatoryAddress = oras_ai_test_answer_evidence(
		array(
			'artifact_id' => 502,
			'source_title' => 'Observatory directions',
			'relevant_text' => 'The ORAS observatory is at 4249 Camp Coffman Road.',
			'fact_key' => '',
			'fact_keys' => array('facility:observatory:address'),
		)
	);
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($staleWoo, $observatoryAddress)),
		oras_ai_test_provider_success('AstroBlast timing is available; current Observer Pass price is unavailable.'),
		array(),
		$service
	);
	$answer = $orchestrator->answer(oras_ai_test_authorized_request(71, $question));
	$providerText = wp_json_encode($provider->calls[0]['context']->provider_input());
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $answer->status(), 'Partial live result did not reach the existing answer layer.');
	oras_ai_assert_contains('2026-09-18T20:00:00-04:00', $providerText, 'Healthy Events evidence was missing from partial answer context.');
	oras_ai_assert_contains('could not be established', $providerText, 'Unavailable Woo portion was not disclosed to the answer layer.');
	oras_ai_assert_not_contains('30.00 USD', $providerText, 'Stale Woo price substituted for a failed current connector.');
	oras_ai_assert_contains('4249 Camp Coffman Road', $providerText, 'Partial live failure removed an unrelated synchronized observatory fact.');
	oras_ai_assert_same(1, count($retriever->requests), 'Partial live failure disabled synchronized retrieval request-wide.');
	oras_ai_assert_same(
		array('product:observer-pass-annual:price'),
		$result->failed_fact_keys(),
		'Partial failure did not expose its bounded normalized fact identity set.'
	);
	oras_ai_assert_contains(
		'product:observer-pass-annual:price',
		implode(',', $retriever->requests[0]->fact_keys()),
		'Failed required fact identity did not reach the retrieval/grounding boundary.'
	);
	oras_ai_assert_same(2, $observer->snapshot()['woocommerce']['failure_count'], 'Repeated partial query did not remain connector-specific and cumulative.');
});

oras_ai_test('one failed fact suppresses only its exact static identity', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$eventLookups = array();
	$events = oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $eventLookups);
	$woo = new ORAS_AI_WooCommerce_Connector(
		static function () {
			return new WP_Error('oras_ai_woocommerce_unavailable', 'unavailable');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	$service = oras_ai_test_observed_service(array($events, $woo), $observer);
	$packet = new ORAS_AI_Evidence_Packet(
		array(
			oras_ai_test_answer_evidence(array('artifact_id' => 601, 'relevant_text' => 'Annual current price is 30.00 USD.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-annual:price'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 602, 'relevant_text' => 'Daily Observer Pass price is 12.50 USD.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-daily:price'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 603, 'relevant_text' => 'Annual Observer Pass availability details remain published.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-annual:availability'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 604, 'relevant_text' => 'Observatory access policy requires orientation.', 'fact_key' => '', 'fact_keys' => array('policy:observatory:access'))),
		)
	);
	$question = 'When is AstroBlast, how much is an Annual Observer Pass, and what is the observatory access policy?';
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		$packet,
		oras_ai_test_provider_success('Partial grounded answer.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(71, $question));
	$providerText = wp_json_encode($provider->calls[0]['context']->provider_input());

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Exact failed-fact suppression prevented a partial answer.');
	oras_ai_assert_not_contains('30.00 USD', $providerText, 'Matching failed Annual price survived grounding.');
	oras_ai_assert_contains('12.50 USD', $providerText, 'Failed Annual price suppressed Daily price.');
	oras_ai_assert_contains('availability details remain published', $providerText, 'Failed Annual price suppressed Annual availability.');
	oras_ai_assert_contains('requires orientation', $providerText, 'Failed Annual price suppressed unrelated policy evidence.');
	oras_ai_assert_same(1, count($retriever->requests), 'Fact-scoped suppression skipped normal synchronized retrieval.');
});

oras_ai_test('multiple failed fact identities suppress only those identities', function (): void {
	oras_ai_test_reset();
	$observer = new ORAS_AI_Connector_Observability();
	$eventLookups = array();
	$events = oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $eventLookups);
	$woo = new ORAS_AI_WooCommerce_Connector(
		static function () {
			return new WP_Error('oras_ai_woocommerce_unavailable', 'unavailable');
		},
		static fn(): string => '2026-09-08T12:00:00-04:00'
	);
	$service = oras_ai_test_observed_service(array($events, $woo), $observer);
	$packet = new ORAS_AI_Evidence_Packet(
		array(
			oras_ai_test_answer_evidence(array('artifact_id' => 701, 'relevant_text' => 'Copied Annual stock says in stock.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-annual:availability'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 702, 'relevant_text' => 'Copied Annual purchase state says yes.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-annual:purchasable'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 703, 'relevant_text' => 'Daily Observer Pass price remains 12.50 USD.', 'fact_key' => '', 'fact_keys' => array('product:observer-pass-daily:price'))),
			oras_ai_test_answer_evidence(array('artifact_id' => 704, 'relevant_text' => 'The observatory is at 4249 Camp Coffman Road.', 'fact_key' => '', 'fact_keys' => array('facility:observatory:address'))),
		)
	);
	$question = 'When is AstroBlast, and is an Annual Observer Pass available to purchase?';
	$live = $service->query(oras_ai_test_live_request($question));
	oras_ai_assert_same(
		array('product:observer-pass-annual:availability', 'product:observer-pass-annual:purchasable'),
		$live->failed_fact_keys(),
		'Multiple failed Woo facts were not retained as a bounded normalized set.'
	);
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		$packet,
		oras_ai_test_provider_success('Partial grounded answer.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(71, $question));
	$providerText = wp_json_encode($provider->calls[0]['context']->provider_input());

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Multiple failed facts prevented healthy partial grounding.');
	oras_ai_assert_not_contains('Copied Annual stock', $providerText, 'Failed availability accepted a static substitute.');
	oras_ai_assert_not_contains('Copied Annual purchase', $providerText, 'Failed purchasability accepted a static substitute.');
	oras_ai_assert_contains('12.50 USD', $providerText, 'Multiple failed facts suppressed unrelated Daily price.');
	oras_ai_assert_contains('4249 Camp Coffman Road', $providerText, 'Multiple failed facts suppressed unrelated facility evidence.');
	oras_ai_assert_same(1, count($retriever->requests), 'Multiple failed facts disabled synchronized retrieval globally.');
});
