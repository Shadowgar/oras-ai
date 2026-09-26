<?php
declare(strict_types=1);

function oras_ai_test_planner_fixture(?callable $weather_callback = null, ?callable $planet_callback = null): array {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00Z'));
	$night = oras_ai_test_darkness_provider($clock);
	$weather = oras_ai_test_weather_provider($weather_callback ?? static function (ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('weather_test', array(
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-10T00:00:00Z'), new DateTimeImmutable('2026-09-10T02:00:00Z'), 'forecast', 40, 10, 'none', 12, 2, null, 70, 16000, 'forecast_uncertain'),
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-10T02:00:00Z'), new DateTimeImmutable('2026-09-10T04:00:00Z'), 'forecast', 30, 10, 'none', 12, 2, null, 70, 16000, 'forecast_uncertain'),
		));
	});
	$local = oras_ai_test_astronomy_provider('local_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('local_test', array(
			new ORAS_AI_Astronomy_Fact('local_test', ORAS_AI_Current_Data_Request::MOON_STATE, 0.42, 'fraction', $clock->now(), $at, 'moon', 'astronomy:moon:illumination', 'fixture-v1'),
			new ORAS_AI_Astronomy_Fact('local_test', ORAS_AI_Current_Data_Request::MOON_STATE, 'above', 'geometric_horizon', $clock->now(), $at, 'moon', 'astronomy:moon:geometric_horizon', 'fixture-v1'),
		));
	});
	$catalog = oras_ai_test_astronomy_provider('catalog_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('catalog_test', array(
			new ORAS_AI_Astronomy_Fact('catalog_test', ORAS_AI_Current_Data_Request::TARGET_POSITION, -4.0, 'degrees', $clock->now(), $request->requested_at(), $request->target_identity(), 'astronomy:target:' . $request->target_identity() . ':altitude', 'fixture-v1'),
			new ORAS_AI_Astronomy_Fact('catalog_test', ORAS_AI_Current_Data_Request::TARGET_POSITION, 'below', 'geometric_horizon', $clock->now(), $request->requested_at(), $request->target_identity(), 'astronomy:target:' . $request->target_identity() . ':geometric_horizon', 'fixture-v1'),
		));
	});
	$planet = oras_ai_test_astronomy_provider('planet_test', $planet_callback ?? static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		$bodies = 'planet:all' === $request->target_identity() ? ORAS_AI_Planet_Targets::allowed() : array(substr($request->target_identity(), 7));
		$facts = array();
		foreach ($bodies as $body) {
			$facts[] = new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 31.0, 'degrees', $clock->now(), $at, 'planet:' . $body, 'astronomy:planet:' . $body . ':altitude', 'fixture-v1');
			$facts[] = new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 'above', 'geometric_horizon', $clock->now(), $at, 'planet:' . $body, 'astronomy:planet:' . $body . ':geometric_horizon', 'fixture-v1');
		}
		return ORAS_AI_Current_Data_Result::success('planet_test', $facts);
	});
	$astronomy = new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	$weather_service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$score = new ORAS_AI_Member_Hub_Score_Adapter($clock, ORAS_AI_Test_Member_Hub_Score_API::class);
	$planner = new ORAS_AI_Observing_Planner($weather_service, $astronomy, $score, $clock);
	return array($planner, $weather, $local, $catalog, $planet, $clock);
}

oras_ai_test('M6 planner evaluates each forecast overlap midpoint with same-period Moon and weather', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather, $local, $catalog, $planet) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1001, 'Can I see Jupiter tonight?'));
	oras_ai_assert_same('grounded', $result->state(), 'Complete aligned periods were not grounded.');
	oras_ai_assert_same(2, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Each weather period needs one authoritative score.');
	oras_ai_assert_same('2026-09-10T01:07:30+00:00', ORAS_AI_Test_Member_Hub_Score_API::$calls[0][4]->format(DATE_ATOM), 'First midpoint wrong.');
	oras_ai_assert_same('2026-09-10T03:00:00+00:00', ORAS_AI_Test_Member_Hub_Score_API::$calls[1][4]->format(DATE_ATOM), 'Second midpoint wrong.');
	oras_ai_assert_same(2, count($planet->requests), 'Expected one targeted planet lookup per interval.');
	oras_ai_assert_same('2026-09-10T03:00:00+00:00', $planet->requests[1]->requested_at()->format(DATE_ATOM), 'Planet geometry used the wrong weather period.');
	oras_ai_assert_same(2, count($local->requests), 'Moon state did not follow both forecast midpoints.');
	$items = $result->evidence_packet()->items();
	$keys = array();
	foreach ($items as $item) { $keys = array_merge($keys, (array) $item->field('fact_keys')); }
	oras_ai_assert_true(count(array_unique(array_filter($keys, static function ($key) { return strpos($key, 'jupiter:altitude') !== false; }))) === 2, 'Different interval target facts collided.');
	oras_ai_assert_not_contains('score_components', wp_json_encode($result->evidence_packet()->to_array()), 'Member Hub internals escaped.');
});

oras_ai_test('M6 planner preserves partial facts when Member Hub score is absent or fails', function (): void {
	list($planner) = oras_ai_test_planner_fixture();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return new WP_Error('private', 'Private error'); };
	$result = $planner->query(oras_ai_test_authorized_request(1002, 'Can I see Jupiter tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same('partially_grounded', $result->state(), 'Missing score was not partial.');
	oras_ai_assert_contains('Jupiter', $text, 'Score failure erased planet facts.');
	oras_ai_assert_contains('Cloud cover', $text, 'Score failure erased NWS facts.');
	oras_ai_assert_contains('score', $text, 'Score unavailability was not disclosed.');
	oras_ai_assert_not_contains('Private error', $text, 'Raw Member Hub error escaped.');
});

oras_ai_test('M6 one malformed forecast interval does not erase a qualified sibling', function (): void {
	$bad = static function (ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('weather_test', array(
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-10T00:00:00Z'), new DateTimeImmutable('2026-09-10T02:00:00Z'), 'forecast', null, 10, 'none', 12, 2, null, 70, 16000, 'cloud_unavailable'),
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-10T02:00:00Z'), new DateTimeImmutable('2026-09-10T04:00:00Z'), 'forecast', 30, 10, 'none', 12, 2, null, 70, 16000, 'forecast_uncertain'),
		));
	};
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture($bad);
	$result = $planner->query(oras_ai_test_authorized_request(1003, 'Can I see Jupiter tonight?'));
	oras_ai_assert_same('partially_grounded', $result->state(), 'Malformed sibling must make plan partial.');
	oras_ai_assert_same(1, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Malformed period should not score or erase sibling.');
	oras_ai_assert_contains('2026-09-10T03:00:00', wp_json_encode($result->evidence_packet()->to_array()), 'Qualified sibling period was lost.');
});

oras_ai_test('M6 broad planet request uses one all-bodies lookup per period and no catalog scan', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather, $local, $catalog, $planet) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1004, 'What can I see tonight?'));
	oras_ai_assert_same(2, count($planet->requests), 'General seven-planet request expanded into seven calls.');
	oras_ai_assert_same('planet:all', $planet->requests[0]->target_identity(), 'General request did not use approved all-bodies path.');
	oras_ai_assert_same(0, count($catalog->requests), 'Broad request scanned OpenNGC.');
	oras_ai_assert_not_contains('visible=true', wp_json_encode($result->evidence_packet()->to_array()), 'Geometry became generic visibility.');
});

oras_ai_test('M6 tied authoritative scores remain tied with no nightly aggregate', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1005, 'When tonight is best for observing?'));
	oras_ai_assert_same(2, count($result->best_intervals()), 'Equal authoritative interval scores need an unbroken tie.');
	oras_ai_assert_not_contains('average', wp_json_encode($result->evidence_packet()->to_array()), 'A nightly aggregate was invented.');
});

oras_ai_test('M6 observing plan reaches guarded grounding beside ORAS evidence with URL-less score', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture();
	$oras = oras_ai_test_answer_evidence(array('relevant_text' => 'ORAS observatory access requires member orientation.'));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($oras)),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$text = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('ORAS Observing Score', $text, 'Authoritative score did not reach grounding.');
			oras_ai_assert_contains('member orientation', $text, 'Higher-authority ORAS fact was erased.');
			oras_ai_assert_contains('geometric horizon', $text, 'Qualified target geometry was erased.');
			oras_ai_assert_not_contains('score_components', $text, 'Member Hub internals reached the model.');
			oras_ai_assert_not_contains('canonical_url', $text, 'Source metadata entered model prose.');
			return ORAS_AI_Provider_Answer::success('Grounded observing answer.', 'gpt-5.6-luna', 100, 30);
		},
		array(), null, null, null, $planner
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(1006, 'At the ORAS observatory, can I see Jupiter tonight?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Planner was not used by the authorized answer path.');
	oras_ai_assert_same(array('https://oras.org/observatory-guide/'), array_column($result->sources(), 'canonical_url'), 'URL-less score created an empty source.');
	oras_ai_assert_same(1, count($provider->calls), 'Guarded synthesis should be invoked once.');
});

oras_ai_test('M6 failed NWS retains qualified astronomy and does not invent a score', function (): void {
	$failed = static function (): ORAS_AI_Current_Data_Result { return ORAS_AI_Current_Data_Result::unavailable('weather_test', 'provider_unavailable'); };
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture($failed);
	$result = $planner->query(oras_ai_test_authorized_request(1007, 'Can I see Jupiter tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same('partially_grounded', $result->state(), 'NWS failure should leave a partial plan.');
	oras_ai_assert_contains('provider_unavailable', $text, 'Weather failure was not disclosed.');
	oras_ai_assert_contains('Jupiter', $text, 'Astronomy did not survive weather failure.');
	oras_ai_assert_same(0, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Weather failure incorrectly produced a score.');
});

oras_ai_test('M6 planner resolves M31 only when explicitly requested and keeps below-horizon geometric wording', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather, $local, $catalog, $planet) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1008, 'Can I see M31 tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same(2, count($catalog->requests), 'M31 geometry was not evaluated per period.');
	oras_ai_assert_same(0, count($planet->requests), 'An unrelated planet was requested.');
	oras_ai_assert_contains('below the geometric horizon', $text, 'Below-horizon target was not factual.');
	oras_ai_assert_not_contains('visible=true', $text, 'Below-horizon target became observable.');
});

oras_ai_test('M6 unresolved catalog target leaves the qualified night and weather intact', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather, $local, $catalog) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1020, 'Can I see M999999 tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same('partially_grounded', $result->state(), 'Unresolved target was treated as a complete plan.');
	oras_ai_assert_same(0, count($catalog->requests), 'An unresolved target reached the catalog provider.');
	oras_ai_assert_contains('target_not_resolved', $text, 'Target uncertainty was not disclosed.');
	oras_ai_assert_contains('Cloud cover', $text, 'Qualified weather was erased by an unresolved target.');
	oras_ai_assert_contains('ORAS observing interval', $text, 'Night interval was erased by an unresolved target.');
});

oras_ai_test('M6 planner compares only complete authoritative interval scores and preserves ties', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function ($weather, $smoke, $moon, $health, $instant) {
		$result = oras_ai_test_score_api_result();
		$result['score'] = 1 === (int) $instant->format('G') ? 60 : 74;
		return $result;
	};
	list($planner) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1009, 'When tonight is best for observing?'));
	oras_ai_assert_same(1, count($result->best_intervals()), 'Higher authoritative score did not identify one interval.');
	oras_ai_assert_same('2026-09-10T02:00:00+00:00', $result->best_intervals()[0]['valid_from'], 'Wrong interval won.');
});

oras_ai_test('M6 weekend comparison abstains without comparable qualified nights', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1010, 'Best ORAS night this weekend?'));
	oras_ai_assert_same(array(), $result->best_intervals(), 'One current night was misrepresented as weekend comparison.');
	oras_ai_assert_same(0, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Unqualified weekend ranking invoked score.');
	oras_ai_assert_contains('unavailable', wp_json_encode($result->evidence_packet()->to_array()), 'Comparison limitation was not disclosed.');
});

oras_ai_test('M6 unrelated provider forecast periods cannot enter planning evidence', function (): void {
	$outside = static function (ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('weather_test', array(
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-10T00:15:00Z'), new DateTimeImmutable('2026-09-10T02:00:00Z'), 'forecast', 40, 10, 'none', 12, 2, null, 70, 16000, 'forecast_uncertain'),
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-11T00:15:00Z'), new DateTimeImmutable('2026-09-11T02:00:00Z'), 'forecast', 99, 99, 'snow', 12, 2, null, 70, 16000, 'forecast_uncertain'),
		));
	};
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture($outside);
	$result = $planner->query(oras_ai_test_authorized_request(1011, 'Can I see Jupiter tonight?'));
	oras_ai_assert_not_contains('2026-09-11T00:15:00', wp_json_encode($result->evidence_packet()->to_array()), 'Tomorrow forecast leaked into tonight plan.');
});

oras_ai_test('M6 planet failure preserves Moon weather and score while leaving target unknown', function (): void {
	$failed_planet = static function (): ORAS_AI_Current_Data_Result { return ORAS_AI_Current_Data_Result::unavailable('planet_test', 'provider_unavailable'); };
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture(null, $failed_planet);
	$result = $planner->query(oras_ai_test_authorized_request(1012, 'Can I see Jupiter tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same('partially_grounded', $result->state(), 'Planet failure incorrectly grounded a complete recommendation.');
	oras_ai_assert_contains('provider_unavailable', $text, 'Planet failure was not disclosed.');
	oras_ai_assert_contains('ORAS Observing Score', $text, 'Moon/weather score was erased by planet failure.');
});

oras_ai_test('M6 absent Member Hub leaves astronomy and weather with bounded score unavailability', function (): void {
	list($planner, $weather, $local, $catalog, $planet, $clock) = oras_ai_test_planner_fixture();
	$planner = new ORAS_AI_Observing_Planner(
		new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock),
		new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock),
		new ORAS_AI_Member_Hub_Score_Adapter($clock, 'ORAS_MH_Missing_Service'), $clock
	);
	$result = $planner->query(oras_ai_test_authorized_request(1013, 'Can I see Jupiter tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_same('partially_grounded', $result->state(), 'Absent Member Hub did not make score partial.');
	oras_ai_assert_contains('Cloud cover', $text, 'Weather was erased by absent score provider.');
	oras_ai_assert_contains('Jupiter', $text, 'Astronomy was erased by absent score provider.');
	oras_ai_assert_contains('score_provider_unavailable', $text, 'Bounded score absence was not disclosed.');
});

oras_ai_test('M6 broad observing and weekend questions enter the guarded astronomy boundary', function (): void {
	$guard = new ORAS_AI_Domain_Guard();
	oras_ai_assert_same(ORAS_AI_Domain_Result::ASTRONOMY, $guard->classify('What can I see tonight?')->outcome(), 'Broad observing intent was not recognized.');
	oras_ai_assert_same(ORAS_AI_Domain_Result::CROSSOVER, $guard->classify('Best ORAS night this weekend?')->outcome(), 'ORAS weekend observing intent was not a current-data crossover.');
});

oras_ai_test('M6 planner does not turn an untimed general astronomy question into tonight forecast', function (): void {
	list($planner, $weather, $local, $catalog, $planet) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1021, 'Can I see Jupiter from Earth in general?'));
	oras_ai_assert_false($result->matched(), 'Untimed general question was forced into the current-night planner.');
	oras_ai_assert_same(0, count($weather->requests), 'Untimed general question fetched weather.');
	oras_ai_assert_same(0, count($planet->requests), 'Untimed general question fetched current planet geometry.');
});

oras_ai_test('M6 planner admits a trusted explicit twenty-four-hour observing time', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1022, 'Can I see Jupiter at 22:00?'));
	oras_ai_assert_true($result->matched(), 'Explicit twenty-four-hour time was not routed to the planner.');
	oras_ai_assert_same(1, count($weather->requests), 'Explicit observing time did not reach weather selection.');
	oras_ai_assert_same('2026-09-10T02:00:00+00:00', ORAS_AI_Test_Member_Hub_Score_API::$calls[0][4]->format(DATE_ATOM), 'Point request at a forecast boundary was not evaluated in its valid period.');
});

oras_ai_test('M6 grounded policy forbids model score or visibility invention', function (): void {
	$guarded = new ORAS_AI_Guarded_Request(
		oras_ai_test_authorized_request(1014, 'Can I see Jupiter tonight?'),
		ORAS_AI_Domain_Result::from_outcome(ORAS_AI_Domain_Result::ASTRONOMY)
	);
	$context = (new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()))->assemble($guarded, new ORAS_AI_Evidence_Packet(), ORAS_AI_Retrieval_Request::INTENT_CURRENT, ORAS_AI_Grounded_Context::CURRENT_ASTRONOMY);
	$policy = $context->provider_input()[0]['content'];
	oras_ai_assert_contains('Do not recalculate', $policy, 'Model could alter authoritative score policy.');
	oras_ai_assert_contains('above the geometric horizon', $policy, 'Model could call geometric position observable.');
});

oras_ai_test('M6 explicit member observing interval controls the planner forecast request', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner, $weather) = oras_ai_test_planner_fixture();
	$planner->query(oras_ai_test_authorized_request(1015, 'Can I see Jupiter from 9 PM to 11 PM tonight?'));
	oras_ai_assert_same('2026-09-10T01:00:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Explicit member start was ignored.');
	oras_ai_assert_same('2026-09-10T03:00:00+00:00', $weather->requests[0]->window_end()->format(DATE_ATOM), 'Explicit member end was ignored.');
});

oras_ai_test('M6 planner astronomy failure identities remain distinct across forecast periods', function (): void {
	$failed_planet = static function (): ORAS_AI_Current_Data_Result { return ORAS_AI_Current_Data_Result::unavailable('planet_test', 'provider_unavailable'); };
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture(null, $failed_planet);
	$result = $planner->query(oras_ai_test_authorized_request(1016, 'Can I see Jupiter tonight?'));
	$keys = array();
	foreach ($result->evidence_packet()->items() as $item) {
		if ('current_astronomy_status' === $item->field('source_type')) {
			$keys[] = $item->field('fact_keys')[0];
		}
	}
	oras_ai_assert_same(2, count(array_unique($keys)), 'Two failed period identities collapsed into one fact.');
});

oras_ai_test('M6 time-scoped astronomy evidence names its exact evaluated instant', function (): void {
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture();
	$result = $planner->query(oras_ai_test_authorized_request(1017, 'Can I see Jupiter tonight?'));
	$text = wp_json_encode($result->evidence_packet()->to_array());
	oras_ai_assert_contains('Jupiter altitude is 31 degrees at 2026-09-10T01:07:30+00:00', $text, 'Target geometry did not identify its evaluated instant.');
});

oras_ai_test('M6 unavailable observing night never invokes model memory for a current answer', function (): void {
	oras_ai_test_reset();
	list($planner, $weather, $local, $catalog, $planet, $clock) = oras_ai_test_planner_fixture();
	$failed_night = oras_ai_test_darkness_provider($clock, true);
	$planner = new ORAS_AI_Observing_Planner(
		new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($failed_night, $clock), $clock),
		new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock),
		new ORAS_AI_Member_Hub_Score_Adapter($clock, 'ORAS_MH_Missing_Service'), $clock
	);
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Invented good viewing.'), array(), null, null, null, $planner);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(1018, 'Can I see Jupiter tonight?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Unavailable observing night reached model synthesis.');
	oras_ai_assert_same(0, count($provider->calls), 'Model memory could substitute for absent night data.');
});

oras_ai_test('M6 edge forecast is evaluated inside its requested overlap without altering provider validity', function (): void {
	$edge = static function (ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
		$at = $request->requested_at();
		return ORAS_AI_Current_Data_Result::success('weather_test', array(
			new ORAS_AI_Weather_Snapshot('weather_test', $at, $at, new DateTimeImmutable('2026-09-09T23:00:00Z'), new DateTimeImmutable('2026-09-10T02:00:00Z'), 'forecast', 40, 10, 'none', 12, 2, null, 70, 16000, 'forecast_uncertain'),
		));
	};
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	list($planner) = oras_ai_test_planner_fixture($edge);
	$result = $planner->query(oras_ai_test_authorized_request(1019, 'Can I see Jupiter from 9 PM to 11 PM tonight?'));
	oras_ai_assert_same('2026-09-10T01:30:00+00:00', ORAS_AI_Test_Member_Hub_Score_API::$calls[0][4]->format(DATE_ATOM), 'Edge period midpoint escaped the requested interval.');
	oras_ai_assert_contains('2026-09-09T23:00:00', wp_json_encode($result->evidence_packet()->to_array()), 'Original provider valid-from time was lost.');
});
