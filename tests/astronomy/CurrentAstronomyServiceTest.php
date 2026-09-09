<?php
declare(strict_types=1);

function oras_ai_test_astronomy_provider(string $providerId, callable $callback): ORAS_AI_Astronomy_Provider_Interface {
	return new class($providerId, $callback) implements ORAS_AI_Astronomy_Provider_Interface {
		private string $providerId;
		private $callback;
		public array $requests = array();
		public function __construct(string $providerId, callable $callback) { $this->providerId = $providerId; $this->callback = $callback; }
		public function provider_id() { return $this->providerId; }
		public function fetch(ORAS_AI_Current_Data_Request $request) { $this->requests[] = $request; return ($this->callback)($request); }
	};
}

function oras_ai_test_astronomy_fact(string $provider, string $factKey, string $target = 'moon'): ORAS_AI_Astronomy_Fact {
	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	return new ORAS_AI_Astronomy_Fact($provider, ORAS_AI_Current_Data_Request::MOON_STATE, 0.25, 'fraction', $instant, $instant, $target, $factKey, 'test-v1');
}

oras_ai_test('M6 astronomy service preserves local facts when a required planet lookup fails', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$local = oras_ai_test_astronomy_provider('local_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('local_test', array(oras_ai_test_astronomy_fact('local_test', 'astronomy:moon:illumination')));
	});
	$catalog = oras_ai_test_astronomy_provider('catalog_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unknown('catalog_test', 'target_not_resolved');
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unavailable('planet_test', 'provider_unavailable');
	});
	$service = new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	$result = $service->query(oras_ai_test_authorized_request(909, 'Where is Jupiter and what is the Moon doing tonight?'));

	oras_ai_assert_true($result->matched(), 'Current astronomy service did not match the compound request.');
	oras_ai_assert_true($result->has_facts(), 'Successful local facts were erased by planet failure.');
	$items = $result->evidence_packet()->items();
	oras_ai_assert_same(2, count($items), 'Expected one local fact and one fact-scoped failure item.');
	oras_ai_assert_same('astronomy:moon:illumination', $items[0]->field('fact_key'), 'Local Moon fact was lost.');
	oras_ai_assert_same(
		array('astronomy:planet:jupiter:altitude', 'astronomy:planet:jupiter:azimuth', 'astronomy:planet:jupiter:geometric_horizon'),
		$items[1]->field('fact_keys'),
		'Planet failure was not represented by exact normalized identities.'
	);
	oras_ai_assert_contains('could not be established', $items[1]->field('relevant_text'), 'Bounded partial failure cannot be disclosed.');
	oras_ai_assert_not_contains('exception', wp_json_encode($result->evidence_packet()->to_array()), 'Raw failure detail escaped.');
});

oras_ai_test('M6 current astronomy orchestration uses provider facts without URL sources or model current fallback', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$local = oras_ai_test_astronomy_provider('local_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unknown('local_test', 'unsupported_fact_type');
	});
	$catalog = oras_ai_test_astronomy_provider('catalog_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unknown('catalog_test', 'unsupported_fact_type');
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		$instant = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('planet_test', array(
			new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 31.25, 'degrees', $clock->now(), $instant, 'planet:jupiter', 'astronomy:planet:jupiter:altitude', 'fixture-v1'),
		));
	});
	$service = new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$serialized = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('31.25 degrees', $serialized, 'Normalized current fact did not reach grounding.');
			oras_ai_assert_not_contains('fixture-v1', $serialized, 'Unnecessary provider-version metadata entered model prose/context.');
			oras_ai_assert_not_contains('canonical_url', $serialized, 'Canonical URL metadata entered provider context.');
			return ORAS_AI_Provider_Answer::success('Jupiter is above the geometric horizon.', 'gpt-5.6-luna', 80, 20);
		},
		array(),
		null,
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(910, 'Where is Jupiter tonight?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified current astronomy did not reach the answer provider.');
	oras_ai_assert_same(ORAS_AI_Grounded_Context::CURRENT_ASTRONOMY, $provider->calls[0]['context']->scope(), 'Current astronomy scope changed.');
	oras_ai_assert_same(0, count($retriever->requests), 'Pure astronomy queried synchronized ORAS knowledge.');
	oras_ai_assert_same(array(), $result->sources(), 'URL-less astronomy facts created an empty Sources item.');
});

oras_ai_test('M6 failed current astronomy never falls back to model memory', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$unknown = static function (): ORAS_AI_Current_Data_Result { return ORAS_AI_Current_Data_Result::unknown('test_provider', 'calculation_unavailable'); };
	$providerStub = oras_ai_test_astronomy_provider('test_provider', $unknown);
	$service = new ORAS_AI_Current_Astronomy_Service($providerStub, $providerStub, $providerStub, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Invented current Jupiter position.'),
		array(),
		null,
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(911, 'Where is Jupiter tonight?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Failed current data did not fail closed.');
	oras_ai_assert_same(0, count($provider->calls), 'Model memory substituted for failed current astronomy.');
});

oras_ai_test('M6 unresolved target does not erase independent Moon and planet facts', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$local = oras_ai_test_astronomy_provider('local_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('local_test', array(oras_ai_test_astronomy_fact('local_test', 'astronomy:moon:illumination')));
	});
	$catalog = oras_ai_test_astronomy_provider('catalog_test', static function (): ORAS_AI_Current_Data_Result {
		throw new RuntimeException('Catalog provider should not receive unresolved coordinates.');
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('planet_test', array(
			new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 12.0, 'degrees', $clock->now(), $request->requested_at(), 'planet:jupiter', 'astronomy:planet:jupiter:altitude', 'fixture-v1'),
		));
	});
	$result = (new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock))
		->query(oras_ai_test_authorized_request(913, 'Where are Jupiter, the Moon, and Mystery Galaxy tonight?'));
	$serialized = wp_json_encode($result->evidence_packet()->to_array());

	oras_ai_assert_true($result->has_facts(), 'Unresolved target erased independent current facts.');
	oras_ai_assert_contains('astronomy:moon:illumination', $serialized, 'Moon fact was lost after target resolution failure.');
	oras_ai_assert_contains('astronomy:planet:jupiter:altitude', $serialized, 'Planet fact was lost after target resolution failure.');
	oras_ai_assert_contains('target_not_resolved', $serialized, 'Bounded unresolved-target state was lost.');
});

oras_ai_test('M6 corrupt OpenNGC data does not disable independent Sun Moon or planet providers', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$local = oras_ai_test_astronomy_provider('local_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('local_test', array(oras_ai_test_astronomy_fact('local_test', 'astronomy:moon:illumination')));
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('planet_test', array(
			new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 12.0, 'degrees', $clock->now(), $request->requested_at(), 'planet:jupiter', 'astronomy:planet:jupiter:altitude', 'fixture-v1'),
		));
	});
	$corrupt_resolver = new ORAS_AI_OpenNGC_Target_Resolver(
		static function () {
			return 'corrupt shard';
		}
	);
	$catalog = new ORAS_AI_OpenNGC_Astronomy_Provider($corrupt_resolver, new ORAS_AI_Horizontal_Position_Calculator(), $clock);
	$result = (new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, $corrupt_resolver, $clock))
		->query(oras_ai_test_authorized_request(916, 'Where are Jupiter, the Moon, and M31 tonight?'));
	$serialized = wp_json_encode($result->evidence_packet()->to_array());

	oras_ai_assert_contains('astronomy:moon:illumination', $serialized, 'Corrupt OpenNGC data erased independent Moon facts.');
	oras_ai_assert_contains('astronomy:planet:jupiter:altitude', $serialized, 'Corrupt OpenNGC data erased independent planet facts.');
	oras_ai_assert_contains('target_not_resolved', $serialized, 'Corrupt OpenNGC data was not represented as a bounded unresolved target.');
	oras_ai_assert_not_contains('ra_degrees', $serialized, 'Corrupt OpenNGC data fabricated target coordinates.');
});

oras_ai_test('M6 crossover grounding combines ORAS evidence with current catalog facts', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-10T02:00:00+00:00'));
	$unused = oras_ai_test_astronomy_provider('unused_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unknown('unused_test', 'unsupported_fact_type');
	});
	$catalog = new ORAS_AI_OpenNGC_Astronomy_Provider(new ORAS_AI_OpenNGC_Target_Resolver(), new ORAS_AI_Horizontal_Position_Calculator(), $clock);
	$service = new ORAS_AI_Current_Astronomy_Service($unused, $catalog, $unused, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence())),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$text = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('orientation', $text, 'ORAS evidence was lost from current crossover.');
			oras_ai_assert_contains('geometric horizon', $text, 'Current catalog fact was lost from crossover.');
			return ORAS_AI_Provider_Answer::success('Grounded crossover answer.', 'gpt-5.6-luna', 80, 20);
		},
		array(),
		null,
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(914, 'At the ORAS observatory, where is M31 tonight?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Current astronomy crossover did not succeed.');
	oras_ai_assert_same(ORAS_AI_Grounded_Context::CROSSOVER_CURRENT, $provider->calls[0]['context']->scope(), 'Current crossover scope changed.');
	oras_ai_assert_same(1, count($retriever->requests), 'Current crossover skipped ORAS retrieval.');
	oras_ai_assert_same(array('https://oras.org/observatory-guide/'), array_column($result->sources(), 'canonical_url'), 'URL-less catalog fact created a source item.');
});

oras_ai_test('M6 unsupported planet rise transit set remains bounded beside current position facts', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$unused = oras_ai_test_astronomy_provider('unused_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unknown('unused_test', 'unsupported_fact_type');
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('planet_test', array(
			new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 12.0, 'degrees', $clock->now(), $request->requested_at(), 'planet:jupiter', 'astronomy:planet:jupiter:altitude', 'fixture-v1'),
		));
	});
	$result = (new ORAS_AI_Current_Astronomy_Service($unused, $unused, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock))
		->query(oras_ai_test_authorized_request(915, 'When does Jupiter rise, transit, and set tonight?'));
	$items = $result->evidence_packet()->items();

	oras_ai_assert_true($result->has_facts(), 'Available current position was discarded because rise/set is unsupported.');
	oras_ai_assert_same('rise_transit_set_unavailable', $items[1]->field('source_type'), 'Unsupported rise/transit/set was not explicit.');
	oras_ai_assert_same(
		array('astronomy:planet:jupiter:rise', 'astronomy:planet:jupiter:transit', 'astronomy:planet:jupiter:set'),
		$items[1]->field('fact_keys'),
		'Unsupported planet window fields lost their exact fact identities.'
	);
});
