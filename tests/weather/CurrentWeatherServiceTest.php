<?php
declare(strict_types=1);

function oras_ai_test_weather_provider(callable $callback): ORAS_AI_Weather_Provider_Interface {
	return new class($callback) implements ORAS_AI_Weather_Provider_Interface {
		private $callback;
		public array $requests = array();
		public function __construct(callable $callback) { $this->callback = $callback; }
		public function provider_id() { return 'weather_test'; }
		public function fetch(ORAS_AI_Current_Data_Request $request) { $this->requests[] = $request; return ($this->callback)($request); }
	};
}

function oras_ai_test_darkness_provider(ORAS_AI_Clock_Interface $clock, bool $missing = false): ORAS_AI_Astronomy_Provider_Interface {
	return oras_ai_test_astronomy_provider('night_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock, $missing): ORAS_AI_Current_Data_Result {
		if ($missing) {
			return ORAS_AI_Current_Data_Result::unknown('night_test', 'calculation_unavailable');
		}
		$local = $request->local_requested_at();
		$date = $local->format('Y-m-d');
		$dusk = new DateTimeImmutable($date . ' 20:15:00', $request->site()->timezone());
		$dawn = new DateTimeImmutable($date . ' 05:25:00', $request->site()->timezone());
		return ORAS_AI_Current_Data_Result::success('night_test', array(
			new ORAS_AI_Astronomy_Fact('night_test', ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS, $dawn->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'iso8601', $clock->now(), $dawn, 'sun', 'astronomy:sun:astronomical-dawn', 'test-v1'),
			new ORAS_AI_Astronomy_Fact('night_test', ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS, $dusk->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'iso8601', $clock->now(), $dusk, 'sun', 'astronomy:sun:astronomical-dusk', 'test-v1'),
		));
	});
}

function oras_ai_test_weather_success(ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
	$at = $request->requested_at();
	return ORAS_AI_Current_Data_Result::success('weather_test', array(new ORAS_AI_Weather_Snapshot(
		'weather_test', $at, $at, $at, $request->window_end(),
		$request->window_end() > $at ? ORAS_AI_Weather_Snapshot::FORECAST : ORAS_AI_Weather_Snapshot::CURRENT,
		40, 10, 'none', 12, 2, null, 70, 16000, 'wind_gust_unavailable'
	)));
}

oras_ai_test('M6 tonight weather uses astronomical dusk through following dawn', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(951, 'What will the weather be like tonight?'));
	$request = $weather->requests[0];

	oras_ai_assert_true($result->has_facts(), 'Qualified tonight forecast did not produce weather evidence.');
	oras_ai_assert_same('2026-09-10T00:15:00+00:00', $request->requested_at()->format(DATE_ATOM), 'Forecast did not begin at qualified dusk.');
	oras_ai_assert_same('2026-09-10T09:25:00+00:00', $request->window_end()->format(DATE_ATOM), 'Forecast did not end at following qualified dawn.');
});

oras_ai_test('M6 named tomorrow observing night derives tomorrow dusk through next dawn', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$service->query(oras_ai_test_authorized_request(960, 'What is the observing forecast tomorrow night?'));

	oras_ai_assert_same('2026-09-11T00:15:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Named tomorrow night used today dusk.');
	oras_ai_assert_same('2026-09-11T09:25:00+00:00', $weather->requests[0]->window_end()->format(DATE_ATOM), 'Named tomorrow night did not use its following dawn.');
});

oras_ai_test('M6 summer and winter ORAS nights use different astronomical clock intervals', function (): void {
	$intervals = array();
	foreach (array('2026-06-15T16:00:00+00:00', '2026-12-15T17:00:00+00:00') as $instant) {
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable($instant));
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(new ORAS_AI_Local_Sun_Moon_Provider($clock), $clock), $clock);
		$service->query(oras_ai_test_authorized_request(952, 'What is the forecast tonight?'));
		$intervals[] = array(
			$weather->requests[0]->requested_at()->setTimezone(new DateTimeZone('America/New_York'))->format('H:i'),
			$weather->requests[0]->window_end()->setTimezone(new DateTimeZone('America/New_York'))->format('H:i'),
		);
	}
	oras_ai_assert_true($intervals[0] !== $intervals[1], 'Tonight remained a fixed clock interval across seasons.');
	oras_ai_assert_true($intervals[0] !== array('20:00', '04:00') && $intervals[1] !== array('20:00', '04:00'), 'Hard-coded 20:00-04:00 survived.');
});

oras_ai_test('M6 astronomical night derivation honors America New York DST offsets', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-03-07T17:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(new ORAS_AI_Local_Sun_Moon_Provider($clock), $clock), $clock);
	$service->query(oras_ai_test_authorized_request(953, 'What is the forecast tonight?'));
	$start = $weather->requests[0]->requested_at()->setTimezone(new DateTimeZone('America/New_York'));
	$end = $weather->requests[0]->window_end()->setTimezone(new DateTimeZone('America/New_York'));

	oras_ai_assert_same('-05:00', $start->format('P'), 'Dusk did not use pre-transition New York offset.');
	oras_ai_assert_same('-04:00', $end->format('P'), 'Following dawn did not use post-transition New York offset.');
});

oras_ai_test('M6 trusted explicit weather interval overrides tonight derivation', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$night = oras_ai_test_darkness_provider($clock, true);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$start = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$end = new DateTimeImmutable('2026-09-10T03:30:00+00:00');
	$result = $service->query(oras_ai_test_authorized_request(954, 'What is the weather tonight?'), $start, $end);

	oras_ai_assert_true($result->has_facts(), 'Explicit interval incorrectly required astronomical default derivation.');
	oras_ai_assert_same($start->format(DATE_ATOM), $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Explicit start was replaced.');
	oras_ai_assert_same($end->format(DATE_ATOM), $weather->requests[0]->window_end()->format(DATE_ATOM), 'Explicit end was replaced.');
	oras_ai_assert_same(0, count($night->requests), 'Astronomical default ran despite trusted explicit interval.');
});

oras_ai_test('M6 member explicit local weather interval overrides tonight derivation', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$night = oras_ai_test_darkness_provider($clock, true);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(964, 'What is the observing weather from 9 PM to 11 PM tonight?'));

	oras_ai_assert_true($result->has_facts(), 'Explicit member interval incorrectly required default night boundaries.');
	oras_ai_assert_same('2026-09-10T01:00:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Local explicit start was not normalized to UTC.');
	oras_ai_assert_same('2026-09-10T03:00:00+00:00', $weather->requests[0]->window_end()->format(DATE_ATOM), 'Local explicit end was not normalized to UTC.');
	oras_ai_assert_same(0, count($night->requests), 'Default night derivation ran despite explicit member interval.');
});

oras_ai_test('M6 member explicit weather time uses a forecast point', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$night = oras_ai_test_darkness_provider($clock, true);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(965, 'Will it be cloudy at 9:30 PM tonight?'));

	oras_ai_assert_true($result->has_facts(), 'Explicit point incorrectly required default night boundaries.');
	oras_ai_assert_same('2026-09-10T01:30:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Local explicit point was not normalized to UTC.');
	oras_ai_assert_same($weather->requests[0]->requested_at()->format(DATE_ATOM), $weather->requests[0]->window_end()->format(DATE_ATOM), 'Explicit time was not represented as a point.');
});

oras_ai_test('M6 member explicit twenty-four-hour weather interval uses ORAS local time', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$night = oras_ai_test_darkness_provider($clock, true);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(966, 'What is the forecast from 20:30 to 23:15 tonight?'));

	oras_ai_assert_true($result->has_facts(), 'Explicit twenty-four-hour interval required default night boundaries.');
	oras_ai_assert_same('2026-09-10T00:30:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Twenty-four-hour start was not normalized.');
	oras_ai_assert_same('2026-09-10T03:15:00+00:00', $weather->requests[0]->window_end()->format(DATE_ATOM), 'Twenty-four-hour end was not normalized.');
});

foreach (array(
	'cloudy at 21:30' => array('2026-09-10T01:30:00+00:00', '2026-09-10T01:30:00+00:00'),
	'forecast from 20:30 to 23:15' => array('2026-09-10T00:30:00+00:00', '2026-09-10T03:15:00+00:00'),
	'cloudy at 9 pm' => array('2026-09-10T01:00:00+00:00', '2026-09-10T01:00:00+00:00'),
	'cloudy at 11 am' => array('2026-09-09T15:00:00+00:00', '2026-09-09T15:00:00+00:00'),
	'cloudy at 9:30 pm' => array('2026-09-10T01:30:00+00:00', '2026-09-10T01:30:00+00:00'),
	'forecast from 9 pm to 11 pm' => array('2026-09-10T01:00:00+00:00', '2026-09-10T03:00:00+00:00'),
	'forecast from 9:15 pm to 11 pm' => array('2026-09-10T01:15:00+00:00', '2026-09-10T03:00:00+00:00'),
	'forecast from 23:15 to 20:30' => array('2026-09-10T03:15:00+00:00', '2026-09-11T00:30:00+00:00'),
	'cloudy at 21:30 tomorrow' => array('2026-09-11T01:30:00+00:00', '2026-09-11T01:30:00+00:00'),
	'cloudy at 21:30 Friday' => array('2026-09-12T01:30:00+00:00', '2026-09-12T01:30:00+00:00'),
	'cloudy at 21:30 on 2026-09-10' => array('2026-09-11T01:30:00+00:00', '2026-09-11T01:30:00+00:00'),
) as $question => $expected) {
	oras_ai_test('M9 weather optional captures are warning-free: ' . $question, static function () use ($question, $expected): void {
		oras_ai_test_reset();
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock, true), $clock), $clock);
		set_error_handler(static function ($severity, $message, $file, $line): void {
			throw new ErrorException($message, 0, $severity, $file, $line);
		}, E_WARNING | E_USER_WARNING);
		try {
			$result = $service->query(oras_ai_test_authorized_request(966, $question));
		} finally {
			restore_error_handler();
		}
		oras_ai_assert_true($result->has_facts(), 'Supported time required unavailable default night boundaries.');
		oras_ai_assert_same(1, count($weather->requests), 'Explicit time did not make one bounded weather request.');
		oras_ai_assert_same($expected[0], $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Wrong explicit UTC start.');
		oras_ai_assert_same($expected[1], $weather->requests[0]->window_end()->format(DATE_ATOM), 'Wrong explicit UTC end.');
	});
}

foreach (array('cloudy at 21', 'cloudy at 25:30', 'cloudy at 13 pm', 'forecast from 20 to 23') as $question) {
	oras_ai_test('M9 weather invalid time retains tonight fallback: ' . $question, static function () use ($question): void {
		oras_ai_test_reset();
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
		set_error_handler(static function ($severity, $message, $file, $line): void {
			throw new ErrorException($message, 0, $severity, $file, $line);
		}, E_WARNING | E_USER_WARNING);
		try {
			$result = $service->query(oras_ai_test_authorized_request(966, $question));
		} finally {
			restore_error_handler();
		}
		oras_ai_assert_true($result->has_facts(), 'Invalid explicit time lost the existing tonight fallback.');
		oras_ai_assert_same('2026-09-10T00:15:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Invalid time did not fall back to dusk.');
		oras_ai_assert_same('2026-09-10T09:25:00+00:00', $weather->requests[0]->window_end()->format(DATE_ATOM), 'Invalid time did not fall back to dawn.');
	});
}

oras_ai_test('M6 missing astronomical night is a fact-scoped weather uncertainty', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock, true), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(955, 'What is the weather tonight?'));
	$packet = $result->evidence_packet();

	oras_ai_assert_false($result->has_facts(), 'Missing night boundaries fabricated weather facts.');
	oras_ai_assert_same(0, count($weather->requests), 'NWS was called with an invented fallback interval.');
	oras_ai_assert_same('weather:forecast:interval', $packet->items()[0]->field('fact_keys')[0], 'Night failure was not scoped to the affected weather interval.');
	oras_ai_assert_contains('night_window_unavailable', $packet->items()[0]->field('relevant_text'), 'Bounded night failure was not disclosable.');
});

oras_ai_test('M6 missing night boundaries do not erase unrelated ORAS evidence', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock, true), $clock), $clock);
	$orasEvidence = oras_ai_test_answer_evidence(array('relevant_text' => 'The ORAS observatory is at 4249 Camp Coffman Road.'));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($orasEvidence)),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$input = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('4249 Camp Coffman Road', $input, 'Weather interval failure erased unrelated ORAS evidence.');
			oras_ai_assert_contains('night_window_unavailable', $input, 'Affected forecast-window uncertainty was lost.');
			return ORAS_AI_Provider_Answer::success('The address is known; the forecast window is unavailable.', 'gpt-5.6-luna', 80, 20);
		},
		array(), null, null, $service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(962, 'What is the ORAS observatory address and forecast tonight?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Night-boundary failure globally failed a crossover request.');
	oras_ai_assert_same(1, count($provider->calls), 'Existing answer layer did not receive safe partial evidence.');
});

oras_ai_test('M6 current weather service keeps valid fields when a sibling field is unavailable', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(956, 'What is the weather right now?'));
	$text = $result->evidence_packet()->items()[0]->field('relevant_text');

	oras_ai_assert_contains('Temperature', $text, 'Valid temperature did not reach normalized evidence.');
	oras_ai_assert_contains('wind gust unavailable', strtolower($text), 'Null gust was not represented as unavailable.');
	oras_ai_assert_not_contains('canonical_url', wp_json_encode(array($result->evidence_packet()->items()[0]->field('relevant_text'))), 'Provider URL metadata entered model prose.');
});

oras_ai_test('M6 distinct forecast periods retain distinct fact identities through grounding', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$weather = oras_ai_test_weather_provider(static function (ORAS_AI_Current_Data_Request $request): ORAS_AI_Current_Data_Result {
		$issue = new DateTimeImmutable('2026-09-09T11:55:00+00:00');
		return ORAS_AI_Current_Data_Result::success('weather_test', array(
			new ORAS_AI_Weather_Snapshot('weather_test', $issue, $issue, $request->requested_at(), $request->requested_at()->modify('+1 hour'), 'forecast', 20, 5, 'none', 12, 2, null, 70, 16000, ''),
			new ORAS_AI_Weather_Snapshot('weather_test', $issue, $issue, $request->requested_at()->modify('+1 hour'), $request->requested_at()->modify('+2 hours'), 'forecast', 80, 40, 'rain', 10, 4, 7, 85, 9000, ''),
		));
	});
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(963, 'What is the observing forecast tonight?'));
	$items = $result->evidence_packet()->items();

	oras_ai_assert_same(2, count($items), 'A multi-period forecast was collapsed before grounding.');
	oras_ai_assert_true($items[0]->field('fact_keys') !== $items[1]->field('fact_keys'), 'Distinct forecast periods share universal fact identities.');
	$guarded = new ORAS_AI_Guarded_Request(oras_ai_test_authorized_request(963, 'What is the observing forecast tonight?'), ORAS_AI_Domain_Result::from_outcome(ORAS_AI_Domain_Result::ASTRONOMY));
	$context = (new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()))->assemble($guarded, $result->evidence_packet(), ORAS_AI_Retrieval_Request::INTENT_CURRENT, ORAS_AI_Grounded_Context::CURRENT_ASTRONOMY);
	oras_ai_assert_same(2, count($context->evidence_packet()->items()), 'Grounding collapsed distinct forecast periods as one fact.');
});

oras_ai_test('M6 cloudy seeing and transparency wording routes to qualified weather data', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	foreach (array('Will it be cloudy tonight?', 'What is the seeing tonight?', 'What is the transparency tonight?') as $question) {
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
		$result = $service->query(oras_ai_test_authorized_request(961, $question));
		oras_ai_assert_true($result->matched(), 'Observing-weather wording bypassed qualified weather routing.');
		oras_ai_assert_same(1, count($weather->requests), 'Qualified weather provider was not called once.');
	}
});

oras_ai_test('M6 cloudy now selects a station observation instead of tonight forecast', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$night = oras_ai_test_darkness_provider($clock, true);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver($night, $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(967, 'Is it cloudy now at the observatory?'));

	oras_ai_assert_true($result->has_facts(), 'A current conditions question was sent through night derivation.');
	oras_ai_assert_same($weather->requests[0]->requested_at()->format(DATE_ATOM), $weather->requests[0]->window_end()->format(DATE_ATOM), 'Current conditions were not represented as an observation instant.');
	oras_ai_assert_same(0, count($night->requests), 'Current conditions unnecessarily invoked dusk/dawn.');
});

oras_ai_test('M6 qualified current weather reaches grounding and never creates an empty source', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$weatherService = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$input = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('Temperature 12 C', $input, 'Normalized weather did not reach grounding.');
			oras_ai_assert_contains('issued', $input, 'Weather valid-time context was omitted.');
			$policy = strtolower($context->provider_input()[0]['content']);
			oras_ai_assert_contains('current astronomy and weather facts', $policy, 'Grounding does not forbid model-memory weather substitution.');
			oras_ai_assert_contains('forecast uncertainty', $policy, 'Forecast freshness and uncertainty disclosure is not required.');
			oras_ai_assert_not_contains('canonical_url', $input, 'Canonical metadata entered model context.');
			return ORAS_AI_Provider_Answer::success('Current conditions are available.', 'gpt-5.6-luna', 80, 20);
		},
		array(), null, null, $weatherService
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(957, 'What are the observing weather conditions right now?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified current weather did not reach the answer provider.');
	oras_ai_assert_same(1, count($provider->calls), 'Answer provider did not receive current weather once.');
	oras_ai_assert_same(0, count($retriever->requests), 'Pure observing weather queried synchronized ORAS knowledge.');
	oras_ai_assert_same(array(), $result->sources(), 'URL-less NWS facts created an empty Sources item.');
});

oras_ai_test('M6 failed NWS cannot erase valid sibling astronomy or trigger weather memory fallback', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
	$astronomyProvider = oras_ai_test_astronomy_provider('moon_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::success('moon_test', array(
			new ORAS_AI_Astronomy_Fact('moon_test', ORAS_AI_Current_Data_Request::MOON_STATE, 0.5, 'fraction', $clock->now(), $request->requested_at(), 'moon', 'astronomy:moon:illumination', 'test-v1'),
		));
	});
	$astronomy = new ORAS_AI_Current_Astronomy_Service($astronomyProvider, $astronomyProvider, $astronomyProvider, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	$failedWeather = oras_ai_test_weather_provider(static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unavailable('weather_test', 'provider_unavailable');
	});
	$weatherService = new ORAS_AI_Current_Weather_Service($failedWeather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$input = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('Moon illumination', $input, 'NWS failure erased sibling Moon evidence.');
			oras_ai_assert_contains('provider_unavailable', $input, 'Bounded weather failure could not be disclosed.');
			return ORAS_AI_Provider_Answer::success('Moon data is available; weather is unavailable.', 'gpt-5.6-luna', 80, 20);
		},
		array(), null, $astronomy, $weatherService
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(958, 'What is the Moon and weather doing right now?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'NWS failure globally failed valid sibling astronomy.');
	oras_ai_assert_same(1, count($provider->calls), 'Existing answer layer did not receive bounded partial evidence.');
});

oras_ai_test('M6 failed Moon lookup does not erase valid sibling weather', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
	$failedAstronomy = oras_ai_test_astronomy_provider('moon_test', static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unavailable('moon_test', 'provider_unavailable');
	});
	$astronomy = new ORAS_AI_Current_Astronomy_Service($failedAstronomy, $failedAstronomy, $failedAstronomy, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$weatherService = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		static function (ORAS_AI_Grounded_Context $context): ORAS_AI_Provider_Answer {
			$input = wp_json_encode($context->provider_input());
			oras_ai_assert_contains('Temperature 12 C', $input, 'Failed Moon lookup erased valid weather.');
			oras_ai_assert_contains('provider_unavailable', $input, 'Bounded Moon failure was lost.');
			return ORAS_AI_Provider_Answer::success('Weather is available; Moon data is unavailable.', 'gpt-5.6-luna', 80, 20);
		},
		array(), null, $astronomy, $weatherService
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(968, 'What is the Moon and weather doing right now?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Sibling Moon failure globally failed current weather.');
	oras_ai_assert_same(1, count($provider->calls), 'Valid weather was not sent to the answer layer.');
});

oras_ai_test('M6 weather-only failure never falls back to model memory', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
	$failed = oras_ai_test_weather_provider(static function (): ORAS_AI_Current_Data_Result {
		return ORAS_AI_Current_Data_Result::unavailable('weather_test', 'provider_unavailable');
	});
	$service = new ORAS_AI_Current_Weather_Service($failed, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Invented clear sky.'), array(), null, null, $service);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(959, 'What are the observing weather conditions right now?'));

	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Failed current weather fell back to model memory.');
	oras_ai_assert_same(0, count($provider->calls), 'Model was called without a qualified current weather fact.');
});

oras_ai_test('M6 plugin wires NWS behind the current weather service and shared astronomy contract', function (): void {
	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/oras-ai-assistant.php');
	oras_ai_assert_contains('new ORAS_AI_NWS_Weather_Provider(', $source, 'Plugin does not construct the approved weather adapter.');
	oras_ai_assert_contains('new ORAS_AI_Current_Weather_Service(', $source, 'Plugin does not wire the provider-independent weather service.');
	oras_ai_assert_contains('new ORAS_AI_Astronomical_Night_Resolver(', $source, 'Plugin does not route night boundaries through the astronomy contract.');
	oras_ai_assert_contains('ORAS_AI_Config::get_nws_user_agent()', $source, 'Plugin does not use the validated server-side NWS identity.');
});

foreach (array(
	'before dusk' => array('2026-09-09T16:00:00Z', '2026-09-10T00:15:00+00:00', '2026-09-10T09:25:00+00:00'),
	'exact dusk' => array('2026-09-10T00:15:00Z', '2026-09-10T00:15:00+00:00', '2026-09-10T09:25:00+00:00'),
	'after dusk' => array('2026-09-10T02:00:00Z', '2026-09-10T02:00:00+00:00', '2026-09-10T09:25:00+00:00'),
	'after midnight before dawn' => array('2026-09-10T07:00:00Z', '2026-09-10T07:00:00+00:00', '2026-09-10T09:25:00+00:00'),
	'exact dawn' => array('2026-09-10T09:25:00Z', '2026-09-11T00:15:00+00:00', '2026-09-11T09:25:00+00:00'),
	'after dawn' => array('2026-09-10T10:00:00Z', '2026-09-11T00:15:00+00:00', '2026-09-11T09:25:00+00:00'),
	'spring DST active night' => array('2026-03-08T06:30:00Z', '2026-03-08T06:30:00+00:00', '2026-03-08T09:25:00+00:00'),
	'fall DST first repeated hour' => array('2026-11-01T05:30:00Z', '2026-11-01T05:30:00+00:00', '2026-11-01T10:25:00+00:00'),
	'fall DST second repeated hour' => array('2026-11-01T06:30:00Z', '2026-11-01T06:30:00+00:00', '2026-11-01T10:25:00+00:00'),
) as $label => $case) {
	oras_ai_test('M6 correction tonight window ' . $label, static function () use ($case): void {
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable($case[0]));
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
		$result = $service->query(oras_ai_test_authorized_request(1100, 'What is the weather tonight?'));
		oras_ai_assert_true($result->has_facts(), 'Active/prospective night was rejected before weather.');
		oras_ai_assert_same(1, count($weather->requests), 'Night must consult weather once.');
		oras_ai_assert_same($case[1], $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Wrong effective night start.');
		oras_ai_assert_same($case[2], $weather->requests[0]->window_end()->format(DATE_ATOM), 'Wrong following dawn.');
		oras_ai_assert_true($weather->requests[0]->requested_at() >= $clock->now(), 'Expired night portion was requested.');
	});
}

oras_ai_test('M6 correction active tonight does not reinterpret a named future night or stale explicit interval', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-10T07:00:00Z'));
	foreach (array('What is the forecast Thursday night?', 'What is the forecast 2026-09-10?') as $question) {
		$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
		$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
		$service->query(oras_ai_test_authorized_request(1101, $question));
		oras_ai_assert_same('2026-09-11T00:15:00+00:00', $weather->requests[0]->requested_at()->format(DATE_ATOM), 'Named evening became the previous active night.');
	}
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(1102, 'What is the forecast?'), $clock->now()->modify('-1 second'), $clock->now()->modify('+1 hour'));
	oras_ai_assert_false($result->has_facts(), 'Unrelated stale explicit request was accepted.');
	oras_ai_assert_same(0, count($weather->requests), 'Stale explicit interval reached provider.');
});

oras_ai_test('M6 correction active night samples trusted now at request construction with an advancing clock', function (): void {
	$clock = new class implements ORAS_AI_Clock_Interface {
		private int $ticks = 0;
		public function now(): DateTimeImmutable {
			return (new DateTimeImmutable('2026-09-10T07:00:00Z'))->modify('+' . $this->ticks++ . ' seconds');
		}
	};
	$weather = oras_ai_test_weather_provider('oras_ai_test_weather_success');
	$service = new ORAS_AI_Current_Weather_Service($weather, new ORAS_AI_Astronomical_Night_Resolver(oras_ai_test_darkness_provider($clock), $clock), $clock);
	$result = $service->query(oras_ai_test_authorized_request(1103, 'What is the weather tonight?'));
	oras_ai_assert_true($result->has_facts(), 'Clock advancement rejected a derived current-night start.');
	oras_ai_assert_same(1, count($weather->requests), 'Weather was not consulted with the current request.');
	oras_ai_assert_true($weather->requests[0]->requested_at() > new DateTimeImmutable('2026-09-10T07:00:00Z'), 'Factory did not sample current trusted time.');
	oras_ai_assert_same($weather->requests[0]->requested_at(), $result->requested_at(), 'Result retained an older captured start.');
});
