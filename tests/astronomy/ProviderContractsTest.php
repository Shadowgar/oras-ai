<?php
declare(strict_types=1);

oras_ai_test('M6 provider interfaces are independent of approved vendor implementations', function (): void {
	$root = dirname(__DIR__, 2);
	$files = array(
		'interface-oras-ai-astronomy-provider.php',
		'interface-oras-ai-weather-provider.php',
		'class-oras-ai-current-data-request.php',
		'class-oras-ai-current-data-result.php',
		'class-oras-ai-astronomy-fact.php',
		'class-oras-ai-weather-snapshot.php',
	);
	$source = '';
	foreach ($files as $file) {
		$source .= (string) file_get_contents($root . '/includes/' . $file);
	}

	foreach (array('SunCalc', 'AstronomyAPI', 'api.astronomyapi.com', 'OpenNGC', 'api.weather.gov', 'wp_remote_') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $source, 'Vendor implementation leaked into the M6 contract boundary.');
	}
	oras_ai_assert_true(interface_exists('ORAS_AI_Astronomy_Provider_Interface'), 'Astronomy provider contract missing.');
	oras_ai_assert_true(interface_exists('ORAS_AI_Weather_Provider_Interface'), 'Weather provider contract missing.');
});

oras_ai_test('M6 current astronomy service remains vendor independent', function (): void {
	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-current-astronomy-service.php');
	foreach (array('Astronomy_API_Provider', 'api.astronomyapi.com', 'SunCalc', 'NGC.csv', 'wp_remote_') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $source, 'Vendor implementation leaked into current astronomy orchestration.');
	}
});

oras_ai_test('M6 current-data result exposes only bounded states and normalized values', function (): void {
	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$fact = new ORAS_AI_Astronomy_Fact('local_sun_moon', 'moon_state', 'waxing_gibbous', '', $instant, $instant, 'moon');
	$result = ORAS_AI_Current_Data_Result::success('local_sun_moon', array($fact));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Normalized success state changed.');
	oras_ai_assert_same('current_astronomy_weather', $fact->authority_class(), 'Astronomy fact authority is incorrect.');
	oras_ai_assert_same(array($fact), $result->values(), 'Normalized result values changed.');

	foreach (
		array(
			ORAS_AI_Current_Data_Result::unavailable('nws', 'provider_unavailable'),
			ORAS_AI_Current_Data_Result::unknown('nws', 'field_unavailable'),
			ORAS_AI_Current_Data_Result::denied('nws', 'request_denied'),
		) as $bounded
	) {
		oras_ai_assert_true(in_array($bounded->status(), array('unavailable', 'unknown', 'denied'), true), 'Unbounded current-data status returned.');
	}
});

oras_ai_test('M6 raw provider payload cannot become a normalized current-data result', function (): void {
	oras_ai_test_expect_invalid_argument(
		static function (): void {
			ORAS_AI_Current_Data_Result::success('nws', array((object) array('temperature' => 72, 'secret' => 'raw')));
		},
		'Raw provider object was accepted.'
	);
});

oras_ai_test('M6 astronomy fact validates bounded normalized arguments', function (): void {
	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$fact = new ORAS_AI_Astronomy_Fact('astronomy_api', 'planet_position', 31.25, 'degrees', $instant, $instant, 'planet:saturn');
	$serialized = $fact->to_array();

	oras_ai_assert_same('astronomy_api', $serialized['provider'], 'Provider identity missing.');
	oras_ai_assert_same('planet_position', $serialized['fact_type'], 'Fact type missing.');
	oras_ai_assert_same('planet:saturn', $serialized['target_identity'], 'Target identity missing.');
	oras_ai_assert_not_contains('live_oras_state', wp_json_encode($serialized), 'Astronomy facts must not be labeled live ORAS state.');

	oras_ai_test_expect_invalid_argument(
		static function () use ($instant): void {
			new ORAS_AI_Astronomy_Fact('https://evil.example', 'planet_position', (object) array('raw' => true), '', $instant, $instant, 'planet:saturn');
		},
		'Malformed astronomy fact was accepted.'
	);
});

oras_ai_test('M6 weather snapshot supports every required normalized field and explicit unavailability', function (): void {
	$issue = new DateTimeImmutable('2026-09-09T10:00:00+00:00');
	$fresh = new DateTimeImmutable('2026-09-09T10:05:00+00:00');
	$from = new DateTimeImmutable('2026-09-09T11:00:00+00:00');
	$until = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$weather = new ORAS_AI_Weather_Snapshot(
		'nws',
		$issue,
		$fresh,
		$from,
		$until,
		ORAS_AI_Weather_Snapshot::FORECAST,
		65.0,
		20.0,
		'rain',
		18.5,
		4.2,
		null,
		77.0,
		16000.0,
		'wind_gust_unavailable'
	);
	$data = $weather->to_array();

	oras_ai_assert_same('2026-09-09T10:00:00+00:00', $data['issued_at'], 'Issue time missing.');
	oras_ai_assert_same('2026-09-09T10:05:00+00:00', $data['fresh_at'], 'Freshness time missing or collapsed into issue time.');
	oras_ai_assert_same('2026-09-09T11:00:00+00:00', $data['valid_from'], 'Valid-from time missing.');
	oras_ai_assert_same('2026-09-09T12:00:00+00:00', $data['valid_until'], 'Valid-until time missing.');
	oras_ai_assert_same('forecast', $data['designation'], 'Current/forecast designation missing.');
	oras_ai_assert_same(65.0, $data['cloud_cover_percent'], 'Cloud cover missing.');
	oras_ai_assert_same(20.0, $data['precipitation_probability_percent'], 'Precipitation probability missing.');
	oras_ai_assert_same('rain', $data['precipitation_type'], 'Precipitation type missing.');
	oras_ai_assert_same(18.5, $data['temperature_celsius'], 'Temperature missing.');
	oras_ai_assert_same(4.2, $data['wind_speed_mps'], 'Wind speed missing.');
	oras_ai_assert_same(null, $data['wind_gust_mps'], 'Unavailable gust must remain null.');
	oras_ai_assert_same(77.0, $data['humidity_percent'], 'Humidity missing.');
	oras_ai_assert_same(16000.0, $data['visibility_meters'], 'Visibility missing.');
	oras_ai_assert_same('unavailable', $data['seeing'], 'Seeing must be explicitly unavailable.');
	oras_ai_assert_same('unavailable', $data['transparency'], 'Transparency must be explicitly unavailable.');
	oras_ai_assert_same('wind_gust_unavailable', $data['uncertainty'], 'Bounded uncertainty missing.');
	oras_ai_assert_same('current_astronomy_weather', $weather->authority_class(), 'Weather authority is incorrect.');
});

oras_ai_test('M6 weather snapshot rejects malformed ranges and values', function (): void {
	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$invalid = array(
		array('designation' => 'browser_selected'),
		array('cloud' => 101.0),
		array('precipitation' => -1.0),
		array('humidity' => INF),
		array('valid_from' => $instant->modify('+1 hour'), 'valid_until' => $instant),
	);

	foreach ($invalid as $case) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($instant, $case): void {
				new ORAS_AI_Weather_Snapshot(
					'nws',
					$instant,
					$instant,
					$case['valid_from'] ?? $instant,
					$case['valid_until'] ?? $instant,
					$case['designation'] ?? ORAS_AI_Weather_Snapshot::CURRENT,
					$case['cloud'] ?? null,
					$case['precipitation'] ?? null,
					'',
					null,
					null,
					null,
					$case['humidity'] ?? null,
					null,
					''
				);
			},
			'Malformed weather snapshot was accepted.'
		);
	}
});

oras_ai_test('M6 observing score is versioned bounded and contains no scoring algorithm', function (): void {
	$calculated = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$dataTime = new DateTimeImmutable('2026-09-09T11:55:00+00:00');
	$score = new ORAS_AI_Observing_Score_Result(74, 'marginal', 'v4-scientific-audit-tier-c', 'medium', 'cloud', 'variable_cloud', $calculated, $dataTime);
	$serialized = $score->to_array();

	oras_ai_assert_same(74, $serialized['score'], 'Score changed.');
	oras_ai_assert_same('marginal', $serialized['category'], 'Score category changed.');
	oras_ai_assert_same('v4-scientific-audit-tier-c', $serialized['model_version'], 'Score version missing.');
	oras_ai_assert_same('2026-09-09T12:00:00+00:00', $serialized['calculated_at'], 'Calculation timestamp missing.');
	oras_ai_assert_same('2026-09-09T11:55:00+00:00', $serialized['data_at'], 'Source data timestamp missing.');
	oras_ai_assert_false(method_exists($score, 'calculate'), 'Task 1 must not duplicate the score algorithm.');
	oras_ai_assert_false(method_exists(ORAS_AI_Observing_Score_Result::class, 'from_model'), 'Model output must not create an authoritative score.');

	foreach (array(-1, 101, 74.5) as $invalidScore) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($invalidScore, $calculated, $dataTime): void {
				new ORAS_AI_Observing_Score_Result($invalidScore, 'marginal', 'v1', '', '', '', $calculated, $dataTime);
			},
			'Unbounded score was accepted.'
		);
	}
});

oras_ai_test('M6 current astronomy weather authority retains frozen precedence', function (): void {
	oras_ai_assert_same(600, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::LIVE_ORAS_STATE), 'Live ORAS priority changed.');
	oras_ai_assert_same(500, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::APPROVED_ORAS_POLICY), 'ORAS policy priority changed.');
	oras_ai_assert_same(400, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::SYNCHRONIZED_ORAS_KNOWLEDGE), 'Synchronized knowledge priority changed.');
	oras_ai_assert_same(300, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER), 'Current astronomy/weather priority changed.');
	oras_ai_assert_same(200, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::GENERAL_MODEL_ASTRONOMY), 'General astronomy priority changed.');
	oras_ai_assert_same(100, ORAS_AI_Source_Precedence::priority(ORAS_AI_Source_Precedence::NO_ANSWER_ESCALATION), 'No-answer priority changed.');
});
