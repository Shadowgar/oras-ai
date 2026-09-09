<?php
declare(strict_types=1);

function oras_ai_test_astronomy_api_row(string $name, float $altitude, float $azimuth): array {
	return array(
		'entry'     => array(
			'id'   => strtolower($name),
			'name' => $name,
		),
		'cells'     => array(
			array(
				'date'     => '2026-09-09T22:00:00-04:00',
				'extraInfo'=> array('raw_provider_field' => 'must-not-escape'),
				'position' => array(
					'horizontal' => array(
						'altitude' => array('degrees' => $altitude),
						'azimuth'  => array('degrees' => $azimuth),
					),
				),
			),
		),
	);
}

oras_ai_test('M6 general planet request uses one all-bodies call and admits only seven planets', function (): void {
	$calls = array();
	$rows = array(
		oras_ai_test_astronomy_api_row('Mercury', 10.0, 100.0),
		oras_ai_test_astronomy_api_row('Venus', -5.0, 110.0),
		oras_ai_test_astronomy_api_row('Mars', 20.0, 120.0),
		oras_ai_test_astronomy_api_row('Jupiter', 30.0, 130.0),
		oras_ai_test_astronomy_api_row('Saturn', 40.0, 140.0),
		oras_ai_test_astronomy_api_row('Uranus', 50.0, 150.0),
		oras_ai_test_astronomy_api_row('Neptune', 60.0, 160.0),
		oras_ai_test_astronomy_api_row('Sun', 15.0, 170.0),
		oras_ai_test_astronomy_api_row('Moon', 25.0, 180.0),
		oras_ai_test_astronomy_api_row('Pluto', 35.0, 190.0),
	);
	$http = static function (string $url, array $arguments) use (&$calls, $rows): array {
		$calls[] = array($url, $arguments);
		return array(
			'response' => array('code' => 200),
			'body'     => wp_json_encode(array('data' => array('table' => array('rows' => $rows)))),
		);
	};
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(901, 'What planets can I see tonight?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		new DateTimeImmutable('2026-09-10T02:00:00+00:00'),
		null,
		'planet:all'
	);
	$provider = new ORAS_AI_Astronomy_API_Provider('application-id', 'application-secret', $http, $clock);
	$result = $provider->fetch($request);

	oras_ai_assert_same(1, count($calls), 'General seven-planet request must use one all-bodies HTTP call.');
	oras_ai_assert_same('/api/v2/bodies/positions', parse_url($calls[0][0], PHP_URL_PATH) ?: '', 'All-bodies request must not be expanded into a body-specific path.');
	oras_ai_assert_same('Basic ' . base64_encode('application-id:application-secret'), $calls[0][1]['headers']['Authorization'], 'AstronomyAPI credentials were not kept in the server request.');
	oras_ai_assert_same(0, $calls[0][1]['redirection'], 'AstronomyAPI redirects must remain disabled.');
	oras_ai_assert_same(10, $calls[0][1]['timeout'], 'AstronomyAPI timeout changed.');
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Allowlisted all-bodies fixture was not normalized.');

	$targets = array();
	foreach ($result->values() as $fact) {
		$targets[$fact->to_array()['target_identity']] = true;
	}
	$expected = array(
		'planet:jupiter',
		'planet:mars',
		'planet:mercury',
		'planet:neptune',
		'planet:saturn',
		'planet:uranus',
		'planet:venus',
	);
	sort($expected);
	$actual = array_keys($targets);
	sort($actual);
	oras_ai_assert_same($expected, $actual, 'Non-allowlisted all-bodies data escaped normalization.');
	oras_ai_assert_not_contains('pluto', wp_json_encode($result->to_array()), 'Discarded body entered normalized output.');
	oras_ai_assert_not_contains('must-not-escape', wp_json_encode($result->to_array()), 'Raw all-bodies field escaped normalization.');
	oras_ai_assert_not_contains('visible', wp_json_encode($result->to_array()), 'Positive altitude must not become generic visibility.');
});

oras_ai_test('M6 specific planet request uses the fixed single-body route', function (): void {
	$calls = array();
	$http = static function (string $url, array $arguments) use (&$calls): array {
		$calls[] = array($url, $arguments);
		return array(
			'response' => array('code' => 200),
			'body'     => wp_json_encode(
				array('data' => array('table' => array('rows' => array(oras_ai_test_astronomy_api_row('Jupiter', 31.25, 145.5)))))
			),
		);
	};
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(902, 'Where is Jupiter?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		new DateTimeImmutable('2026-09-10T02:00:00+00:00'),
		null,
		'planet:jupiter'
	);
	$provider = new ORAS_AI_Astronomy_API_Provider('application-id', 'application-secret', $http, $clock);
	$result = $provider->fetch($request);

	oras_ai_assert_same(1, count($calls), 'Specific planet request must use one HTTP call.');
	oras_ai_assert_contains('/bodies/jupiter/positions', parse_url($calls[0][0], PHP_URL_PATH) ?: '', 'Specific request did not use the allowlisted Jupiter path.');
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Specific Jupiter response was not normalized.');
	$facts = oras_ai_test_fact_map($result);
	oras_ai_test_assert_float_near(31.25, (float) $facts['astronomy:planet:jupiter:altitude']['value'], 0.01, 'AstronomyAPI altitude exceeded the locked +/-0.01 degree normalization tolerance.');
	oras_ai_test_assert_float_near(145.5, (float) $facts['astronomy:planet:jupiter:azimuth']['value'], 0.01, 'AstronomyAPI azimuth exceeded the locked +/-0.01 degree normalization tolerance.');
	oras_ai_assert_same('above', $facts['astronomy:planet:jupiter:geometric_horizon']['value'], 'Positive altitude did not remain a geometric-horizon fact.');
	foreach ($result->values() as $fact) {
		oras_ai_assert_same('planet:jupiter', $fact->to_array()['target_identity'], 'Specific route admitted another body.');
	}
});

oras_ai_test('M6 AstronomyAPI failures are bounded do not retry and never expose secrets or raw payloads', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(905, 'Where is Saturn?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		null,
		null,
		'planet:saturn'
	);
	$cases = array(
		array('timeout', static function (): array { throw new RuntimeException('secret transport detail'); }, ORAS_AI_Current_Data_Result::UNAVAILABLE, 'provider_request_failed'),
		array('429', static function (): array { return array('response' => array('code' => 429), 'body' => '{"secret":"raw"}'); }, ORAS_AI_Current_Data_Result::UNAVAILABLE, 'provider_unavailable'),
		array('504', static function (): array { return array('response' => array('code' => 504), 'body' => '<html>raw</html>'); }, ORAS_AI_Current_Data_Result::UNAVAILABLE, 'provider_unavailable'),
		array('malformed', static function (): array { return array('response' => array('code' => 200), 'body' => '{"data":{"rows":[{"secret":"raw"}]}}'); }, ORAS_AI_Current_Data_Result::UNKNOWN, 'malformed_provider_response'),
	);
	foreach ($cases as $case) {
		$count = 0;
		$http = static function (string $url, array $arguments) use (&$count, $case): array {
			$count++;
			return $case[1]($url, $arguments);
		};
		$result = (new ORAS_AI_Astronomy_API_Provider('fake-id', 'fake-secret', $http, $clock))->fetch($request);
		oras_ai_assert_same(1, $count, $case[0] . ' response was retried.');
		oras_ai_assert_same($case[2], $result->status(), $case[0] . ' status changed.');
		oras_ai_assert_same($case[3], $result->reason(), $case[0] . ' reason changed.');
		$serialized = wp_json_encode($result->to_array());
		oras_ai_assert_not_contains('fake-secret', $serialized, 'Credential escaped in normalized failure.');
		oras_ai_assert_not_contains('raw', $serialized, 'Raw provider payload escaped in normalized failure.');
	}
});

oras_ai_test('M6 AstronomyAPI request surface cannot select arbitrary bodies hosts or credentials', function (): void {
	$count = 0;
	$http = static function () use (&$count): array {
		$count++;
		return array('response' => array('code' => 500), 'body' => '');
	};
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(906, 'Where is Pluto?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		null,
		null,
		'planet:pluto'
	);
	$result = (new ORAS_AI_Astronomy_API_Provider('fake-id', 'fake-secret', $http, $clock))->fetch($request);
	oras_ai_assert_same(0, $count, 'Non-allowlisted body reached HTTP.');
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::DENIED, $result->status(), 'Non-allowlisted body was not denied safely.');
	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-astronomy-api-provider.php');
	oras_ai_assert_contains("const BASE_URL    = 'https://api.astronomyapi.com/api/v2/bodies';", $source, 'AstronomyAPI host is not fixed server-side.');
	oras_ai_assert_not_contains('request->url', $source, 'Member request can select an API URL.');
});

oras_ai_test('M6 unconfigured AstronomyAPI fails before HTTP without breaking local providers', function (): void {
	$count = 0;
	$http = static function () use (&$count): array { $count++; return array(); };
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(912, 'Where is Jupiter?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		null,
		null,
		'planet:jupiter'
	);
	$result = (new ORAS_AI_Astronomy_API_Provider('', '', $http, $clock))->fetch($request);
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $result->status(), 'Missing deployment credentials did not fail boundedly.');
	oras_ai_assert_same('provider_not_configured', $result->reason(), 'Missing-credential reason changed.');
	oras_ai_assert_same(0, $count, 'Unconfigured provider attempted HTTP.');
});
