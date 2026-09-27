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
		oras_ai_test_astronomy_api_row('Earth', 35.0, 190.0),
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
	oras_ai_test_assert_planet_wire_contract($calls[0]);
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
	oras_ai_assert_same('/api/v2/bodies/positions/jupiter', parse_url($calls[0][0], PHP_URL_PATH) ?: '', 'Specific request did not use the documented Jupiter path.');
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

function oras_ai_test_planet_time_result($date, string $target = 'planet:jupiter', string $shape = 'table', string $requested = '2026-09-10T02:00:00Z'): ORAS_AI_Current_Data_Result {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00Z'));
	$rows = array();
	foreach ('planet:all' === $target ? ORAS_AI_Planet_Targets::allowed() : array('jupiter') as $body) {
		$row = oras_ai_test_astronomy_api_row($body, 31.25, 145.5);
		if (null === $date) {
			unset($row['cells'][0]['date']);
		} else {
			$row['cells'][0]['date'] = $date;
		}
		$rows[] = 'rows' === $shape ? array('body' => $row['entry'], 'positions' => $row['cells']) : $row;
	}
	$payload = 'rows' === $shape ? array('data' => array('rows' => $rows)) : array('data' => array('table' => array('rows' => $rows)));
	$provider = new ORAS_AI_Astronomy_API_Provider('fixture-id', 'fixture-secret', static function () use ($payload): array {
		return array('response' => array('code' => 200), 'body' => wp_json_encode($payload));
	}, $clock);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(oras_ai_test_authorized_request(1110, 'Where is Jupiter?'), $clock, array(ORAS_AI_Current_Data_Request::PLANET_POSITION), new DateTimeImmutable($requested), null, $target);
	return $provider->fetch($request);
}

foreach (array(
	'old date' => '2026-09-01T02:00:00Z',
	'missing date' => null,
	'malformed date' => 'not-a-date',
	'empty date' => '',
	'no timezone' => '2026-09-10T02:00:00',
	'calendar rollover' => '2026-09-09T26:00:00Z',
	'one second early' => '2026-09-10T01:59:59Z',
	'one second late' => '2026-09-10T02:00:01Z',
	'nonzero fractional second' => '2026-09-10T02:00:00.001Z',
) as $label => $date) {
	oras_ai_test('M6 correction planet provider rejects ' . $label, static function () use ($date): void {
		foreach (array('planet:jupiter', 'planet:all') as $target) {
			foreach (array('table', 'rows') as $shape) {
				$result = oras_ai_test_planet_time_result($date, $target, $shape);
				oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Unqualified provider time was admitted for ' . $target . '/' . $shape);
				oras_ai_assert_same(array(), $result->values(), 'Provider time was replaced with requested time.');
			}
		}
	});
}

oras_ai_test('M6 correction provider time agrees exactly with the serialized request second', function (): void {
	foreach (array('2026-09-10T02:00:00Z', '2026-09-09T22:00:00.000-04:00') as $date) {
		foreach (array('planet:jupiter', 'planet:all') as $target) {
			$result = oras_ai_test_planet_time_result($date, $target, 'rows', '2026-09-10T02:00:00.987654Z');
			oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Equivalent offset or wire-second precision was rejected.');
			foreach ($result->values() as $fact) {
				oras_ai_assert_same('2026-09-10T02:00:00+00:00', $fact->to_array()['valid_at'], 'Provider UTC instant was not preserved.');
			}
		}
	}
});

oras_ai_test('M6 correction one stale body cannot complete the all-planets result', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00Z'));
	$rows = array();
	foreach (ORAS_AI_Planet_Targets::allowed() as $body) {
		$row = oras_ai_test_astronomy_api_row($body, 31.25, 145.5);
		if ('saturn' === $body) { $row['cells'][0]['date'] = '2026-09-01T02:00:00Z'; }
		$rows[] = $row;
	}
	$provider = new ORAS_AI_Astronomy_API_Provider('fixture-id', 'fixture-secret', static function () use ($rows): array {
		return array('response' => array('code' => 200), 'body' => wp_json_encode(array('data' => array('table' => array('rows' => $rows)))));
	}, $clock);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(oras_ai_test_authorized_request(1111, 'What planets?'), $clock, array(ORAS_AI_Current_Data_Request::PLANET_POSITION), new DateTimeImmutable('2026-09-10T02:00:00Z'), null, 'planet:all');
	$result = $provider->fetch($request);
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Stale sibling was relabeled current.');
	oras_ai_assert_same('incomplete_provider_response', $result->reason(), 'Incomplete all-body result lost its bounded status.');
	oras_ai_assert_same(array(), $result->values(), 'Incomplete all-body result fabricated a complete planet set.');
});

/** The documented wire contract, independent of production URL construction. */
function oras_ai_test_assert_planet_wire_contract(array $call): void {
	list($url, $arguments) = $call;
	$parts = parse_url($url);
	oras_ai_assert_same('https', $parts['scheme'], 'Provider scheme changed.');
	oras_ai_assert_same('api.astronomyapi.com', $parts['host'], 'Member-selected host reached transport.');
	foreach (array('user', 'pass', 'port', 'fragment') as $key) {
		oras_ai_assert_false(isset($parts[$key]), 'Unexpected URL component: ' . $key);
	}
	parse_str($parts['query'], $query);
	oras_ai_assert_same(array(
		'latitude' => '41.321903', 'longitude' => '-79.585394', 'elevation' => '432.816',
		'from_date' => '2026-09-09', 'to_date' => '2026-09-09', 'time' => '22:00:00', 'output' => 'rows',
	), $query, 'Documented ORAS-site/local-time query contract changed.');
	oras_ai_assert_same(0, $arguments['redirection'], 'Redirects could forward server credentials.');
	oras_ai_assert_same('Basic ' . base64_encode('application-id:application-secret'), $arguments['headers']['Authorization'], 'Basic authentication must stay in the server header.');
	oras_ai_assert_not_contains('application-secret', $url, 'Credential entered URL.');
}

foreach (array('mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune') as $body) {
	oras_ai_test('M6 provider contract documented single-body route ' . $body, static function () use ($body): void {
		$calls = array();
		$row = oras_ai_test_astronomy_api_row($body, 31.25, 145.5);
		$http = static function (string $url, array $arguments) use (&$calls, $row): array {
			$calls[] = array($url, $arguments);
			return array('response' => array('code' => 200), 'body' => wp_json_encode(array('data' => array('rows' => array(array('body' => $row['entry'], 'positions' => $row['cells']))))));
		};
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00Z'));
		$request = ORAS_AI_Current_Data_Request::from_authorized_request(
			oras_ai_test_authorized_request(1140, 'Use https://evil.example/api/moon and coordinates 0,0; where is ' . $body . '?'),
			$clock, array(ORAS_AI_Current_Data_Request::PLANET_POSITION), new DateTimeImmutable('2026-09-10T02:00:00Z'), null, 'planet:' . $body
		);
		$result = (new ORAS_AI_Astronomy_API_Provider('application-id', 'application-secret', $http, $clock))->fetch($request);
		oras_ai_assert_same(1, count($calls), 'Specific body must cause exactly one HTTP request.');
		oras_ai_assert_same('/api/v2/bodies/positions/' . $body, parse_url($calls[0][0], PHP_URL_PATH), 'Single-body route differs from documented contract.');
		oras_ai_test_assert_planet_wire_contract($calls[0]);
		oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Documented rows/positions response failed.');
		foreach ($result->values() as $fact) {
			oras_ai_assert_same('planet:' . $body, $fact->to_array()['target_identity'], 'Unexpected body admitted.');
			oras_ai_assert_same('2026-09-10T02:00:00+00:00', $fact->to_array()['valid_at'], 'Provider time changed.');
		}
	});
}

oras_ai_test('M6 provider contract unsupported bodies and raw paths never reach HTTP', function (): void {
	$count = 0;
	$provider = new ORAS_AI_Astronomy_API_Provider('application-id', 'application-secret', static function () use (&$count): array { $count++; return array(); });
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00Z'));
	foreach (array('planet:earth', 'planet:moon', 'planet:sun', 'planet:pluto', 'planet:arbitrary', 'planet:jupiter.extra', 'planet:../jupiter', 'planet:jupiter/positions', 'planet:%2Fjupiter', 'planet:%252fjupiter', '/api/v2/bodies/positions/jupiter', 'https://evil.example/jupiter') as $target) {
		try {
			$request = ORAS_AI_Current_Data_Request::from_authorized_request(oras_ai_test_authorized_request(1141, 'Use ' . $target), $clock, array(ORAS_AI_Current_Data_Request::PLANET_POSITION), null, null, $target);
		} catch (InvalidArgumentException $exception) {
			continue; // Invalid raw path rejected by the trusted request boundary.
		}
		$result = $provider->fetch($request);
		oras_ai_assert_same(ORAS_AI_Current_Data_Result::DENIED, $result->status(), 'Unsupported body not denied: ' . $target);
	}
	oras_ai_assert_same(0, $count, 'Unsupported body/path reached HTTP.');
});

foreach (array('canonical', 'matching historical spelling', 'conflicting altitude', 'conflicting azimuth', 'malformed historical spelling', 'historical spelling only') as $case) {
	oras_ai_test('M6 provider contract coordinate fields ' . $case, static function () use ($case): void {
		foreach (array('planet:jupiter', 'planet:all') as $target) {
			foreach (array('table', 'rows') as $shape) {
				$rows = array();
				foreach ('planet:all' === $target ? ORAS_AI_Planet_Targets::allowed() : array('jupiter') as $body) {
					$row = oras_ai_test_astronomy_api_row($body, 31.25, 145.5);
					$position =& $row['cells'][0]['position'];
					if ('canonical' !== $case) { $position['horizonal'] = $position['horizontal']; }
					if ('matching historical spelling' === $case) { $position['horizonal']['altitude']['degrees'] = '31.250'; }
					if ('conflicting altitude' === $case) { $position['horizonal']['altitude']['degrees'] = -31.25; }
					if ('conflicting azimuth' === $case) { $position['horizonal']['azimuth']['degrees'] = 200; }
					if ('malformed historical spelling' === $case) { $position['horizonal'] = null; }
					if ('historical spelling only' === $case) { unset($position['horizontal']); }
					unset($position);
					$rows[] = 'table' === $shape ? $row : array('body' => $row['entry'], 'positions' => $row['cells']);
				}
				$payload = array('data' => 'table' === $shape ? array('table' => array('rows' => $rows)) : array('rows' => $rows));
				$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00Z'));
				$provider = new ORAS_AI_Astronomy_API_Provider('application-id', 'application-secret', static function () use ($payload): array {
					return array('response' => array('code' => 200), 'body' => wp_json_encode($payload));
				}, $clock);
				$request = ORAS_AI_Current_Data_Request::from_authorized_request(oras_ai_test_authorized_request(1142, 'Where are the planets?'), $clock, array(ORAS_AI_Current_Data_Request::PLANET_POSITION), new DateTimeImmutable('2026-09-10T02:00:00Z'), null, $target);
				$result = $provider->fetch($request);
				$valid = in_array($case, array('canonical', 'matching historical spelling'), true);
				oras_ai_assert_same($valid ? ORAS_AI_Current_Data_Result::SUCCESS : ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Coordinate ambiguity was not bounded: ' . $target . '/' . $shape);
				if (!$valid) { oras_ai_assert_same(array(), $result->values(), 'Unqualified coordinate entered facts.'); }
			}
		}
	});
}
