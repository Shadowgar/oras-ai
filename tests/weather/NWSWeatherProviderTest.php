<?php
declare(strict_types=1);

function oras_ai_test_nws_response(array $body, int $status = 200, array $headers = array()): array {
	return array(
		'response' => array('code' => $status),
		'body' => wp_json_encode($body),
		'headers' => $headers,
	);
}

final class ORAS_AI_Test_NWS_Headers implements ArrayAccess {
	private array $headers;
	public function __construct(array $headers) { $this->headers = array_change_key_case($headers, CASE_LOWER); }
	public function offsetExists($offset): bool { return array_key_exists(strtolower((string) $offset), $this->headers); }
	public function offsetGet($offset): mixed { return $this->headers[strtolower((string) $offset)] ?? null; }
	public function offsetSet($offset, $value): void { $this->headers[strtolower((string) $offset)] = $value; }
	public function offsetUnset($offset): void { unset($this->headers[strtolower((string) $offset)]); }
}

function oras_ai_test_nws_point(): array {
	return array('properties' => array('gridId' => 'PBZ', 'gridX' => 87, 'gridY' => 108));
}

function oras_ai_test_nws_stations(): array {
	return array('features' => array(
		array(
			'geometry' => array('coordinates' => array(-79.8604, 41.3779)),
			'properties' => array('stationIdentifier' => 'KFKL'),
		),
	));
}

function oras_ai_test_nws_observation(string $timestamp): array {
	return array('properties' => array(
		'timestamp' => $timestamp,
		'temperature' => array('value' => 10.0, 'unitCode' => 'wmoUnit:degC'),
		'windSpeed' => array('value' => 5.0, 'unitCode' => 'wmoUnit:m_s-1'),
		'windGust' => array('value' => null, 'unitCode' => 'wmoUnit:m_s-1'),
		'relativeHumidity' => array('value' => 81.0, 'unitCode' => 'wmoUnit:percent'),
		'visibility' => array('value' => 16000.0, 'unitCode' => 'wmoUnit:m'),
		'presentWeather' => array(array('weather' => 'rain')),
	));
}

function oras_ai_test_nws_forecast(): array {
	return array('properties' => array(
		'updateTime' => '2026-09-09T20:00:00+00:00',
		'skyCover' => array('uom' => 'wmoUnit:percent', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 35))),
		'probabilityOfPrecipitation' => array('uom' => 'wmoUnit:percent', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 20))),
		'weather' => array('values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => array(array('weather' => 'rain'))))),
		'temperature' => array('uom' => 'wmoUnit:degC', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 11))),
		'windSpeed' => array('uom' => 'wmoUnit:km_h-1', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 18))),
		'windGust' => array('uom' => 'wmoUnit:km_h-1', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 36))),
		'relativeHumidity' => array('uom' => 'wmoUnit:percent', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 72))),
		'visibility' => array('uom' => 'wmoUnit:m', 'values' => array(array('validTime' => '2026-09-10T00:00:00+00:00/PT12H', 'value' => 14000))),
	));
}

function oras_ai_test_nws_http(array $responses, array &$calls): callable {
	return static function (string $url, array $args) use (&$responses, &$calls) {
		$calls[] = array('url' => $url, 'args' => $args);
		if (empty($responses)) {
			return new WP_Error('unexpected_http', 'No response queued.');
		}
		return array_shift($responses);
	};
}

function oras_ai_test_weather_request(ORAS_AI_Clock_Interface $clock, ?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null): ORAS_AI_Current_Data_Request {
	return ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(950, 'What is the observing weather?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::WEATHER_CONDITIONS),
		$start,
		$end
	);
}

oras_ai_test('M6 NWS observation age exactly ninety minutes remains current', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(oras_ai_test_nws_point()),
		oras_ai_test_nws_response(oras_ai_test_nws_stations()),
		oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T10:30:00+00:00')),
	);
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock));
	$result = $provider->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'An observation exactly at the approved freshness boundary was rejected.');
	oras_ai_assert_same('2026-09-09T10:30:00+00:00', $result->values()[0]->to_array()['issued_at'], 'Provider observation timestamp was not retained.');
	oras_ai_assert_same(90 * 60, ORAS_AI_NWS_Weather_Provider::CURRENT_OBSERVATION_MAX_AGE_SECONDS, 'The approved threshold is not centralized.');
});

oras_ai_test('M6 NWS observation older than ninety minutes is stale', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:01+00:00'));
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(oras_ai_test_nws_point()),
		oras_ai_test_nws_response(oras_ai_test_nws_stations()),
		oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T10:30:00+00:00')),
	);
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Stale observation remained eligible as current.');
	oras_ai_assert_same('stale_observation', $result->reason(), 'Staleness did not use the bounded reason.');
});

oras_ai_test('M6 fresh observation cache cannot conceal stale provider timestamp', function (): void {
	oras_ai_test_reset();
	$firstClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$cache = new ORAS_AI_NWS_Cache($firstClock);
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(oras_ai_test_nws_point()),
		oras_ai_test_nws_response(oras_ai_test_nws_stations()),
		oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T10:31:00+00:00')),
	);
	$first = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $firstClock, $cache);
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $first->fetch(oras_ai_test_weather_request($firstClock))->status(), 'Fresh fixture did not prime normalized cache.');

	$secondClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:04:00+00:00'));
	$cachedProvider = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', static function () { throw new RuntimeException('HTTP must not run'); }, $secondClock, new ORAS_AI_NWS_Cache($secondClock));
	$result = $cachedProvider->fetch(oras_ai_test_weather_request($secondClock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'A locally fresh cache entry concealed an old provider timestamp.');
	oras_ai_assert_same('stale_observation', $result->reason(), 'Cached provider timestamp did not govern freshness.');
});

oras_ai_test('M6 NWS observation discovery is fixed-host bounded and normalized', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(oras_ai_test_nws_point(), 200, array('cache-control' => 'max-age=999999')),
		oras_ai_test_nws_response(oras_ai_test_nws_stations()),
		oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T11:45:00+00:00')),
	);
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock));
	$data = $result->values()[0]->to_array();

	oras_ai_assert_same('https://api.weather.gov/points/41.3219,-79.5854', $calls[0]['url'], 'Authoritative ORAS coordinates were not used.');
	oras_ai_assert_same('https://api.weather.gov/gridpoints/PBZ/87,108/stations?limit=8', $calls[1]['url'], 'Station discovery did not use the validated grid mapping.');
	oras_ai_assert_same('https://api.weather.gov/stations/KFKL/observations/latest?require_qc=true', $calls[2]['url'], 'Latest observation endpoint was not server constructed.');
	oras_ai_assert_same(10, $calls[0]['args']['timeout'], 'HTTP timeout is not bounded.');
	oras_ai_assert_same(0, $calls[0]['args']['redirection'], 'Redirects were not disabled.');
	oras_ai_assert_same('ORAS AI test@example.org', $calls[0]['args']['headers']['User-Agent'], 'Configured NWS identity was not sent.');
	oras_ai_assert_same(10.0, $data['temperature_celsius'], 'Temperature was not normalized.');
	oras_ai_assert_same(5.0, $data['wind_speed_mps'], 'Wind speed was not normalized.');
	oras_ai_assert_same(null, $data['wind_gust_mps'], 'Null gust was fabricated.');
	oras_ai_assert_same('rain', $data['precipitation_type'], 'Structured precipitation was not normalized.');
	oras_ai_assert_same('unavailable', $data['seeing'], 'Seeing was inferred.');
	oras_ai_assert_same('unavailable', $data['transparency'], 'Transparency was inferred.');
	oras_ai_assert_not_contains('textDescription', wp_json_encode($result->to_array()), 'Raw provider prose escaped normalization.');
});

oras_ai_test('M6 NWS forecast retains structured fields issue and valid interval', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$start = new DateTimeImmutable('2026-09-10T01:00:00+00:00');
	$end = new DateTimeImmutable('2026-09-10T09:00:00+00:00');
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast()));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock, $start, $end));
	$data = $result->values()[0]->to_array();

	oras_ai_assert_same('https://api.weather.gov/gridpoints/PBZ/87,108', $calls[1]['url'], 'Structured forecast-grid endpoint was not used.');
	oras_ai_assert_same('forecast', $data['designation'], 'Forecast was collapsed into an observation.');
	oras_ai_assert_same('2026-09-09T20:00:00+00:00', $data['issued_at'], 'Forecast update time was not retained.');
	oras_ai_assert_same('2026-09-10T00:00:00+00:00', $data['valid_from'], 'Forecast valid start was not retained.');
	oras_ai_assert_same('2026-09-10T12:00:00+00:00', $data['valid_until'], 'Forecast valid end was not retained.');
	oras_ai_assert_same(35.0, $data['cloud_cover_percent'], 'Cloud cover was not normalized.');
	oras_ai_assert_same(20.0, $data['precipitation_probability_percent'], 'Precipitation probability was not normalized.');
	oras_ai_assert_same(5.0, $data['wind_speed_mps'], 'Forecast km/h wind was not converted to m/s.');
	oras_ai_assert_same(10.0, $data['wind_gust_mps'], 'Forecast gust was not normalized separately.');
});

oras_ai_test('M6 requested observing interval retains every overlapping structured forecast period', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$forecast = oras_ai_test_nws_forecast();
	foreach (array('skyCover', 'probabilityOfPrecipitation', 'temperature', 'windSpeed', 'windGust', 'relativeHumidity', 'visibility') as $field) {
		$forecast['properties'][$field]['values'] = array(
			array('validTime' => '2026-09-10T00:00:00+00:00/PT6H', 'value' => $forecast['properties'][$field]['values'][0]['value']),
			array('validTime' => '2026-09-10T06:00:00+00:00/PT6H', 'value' => $forecast['properties'][$field]['values'][0]['value']),
		);
	}
	$forecast['properties']['weather']['values'] = array(
		array('validTime' => '2026-09-10T00:00:00+00:00/PT6H', 'value' => array(array('weather' => 'rain'))),
		array('validTime' => '2026-09-10T06:00:00+00:00/PT6H', 'value' => array()),
	);
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response($forecast));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable('2026-09-10T01:00:00+00:00'), new DateTimeImmutable('2026-09-10T09:00:00+00:00')));

	oras_ai_assert_same(2, count($result->values()), 'Only the first overlapping NWS forecast period was retained.');
	oras_ai_assert_same('2026-09-10T00:00:00+00:00', $result->values()[0]->to_array()['valid_from'], 'First provider interval changed.');
	oras_ai_assert_same('2026-09-10T06:00:00+00:00', $result->values()[1]->to_array()['valid_from'], 'Second provider interval was lost.');
});

oras_ai_test('M6 future point-in-time weather uses forecast rather than current observation', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$at = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast()));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock, $at, $at));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Future point forecast was treated as a station observation.');
	oras_ai_assert_same(ORAS_AI_Weather_Snapshot::FORECAST, $result->values()[0]->to_array()['designation'], 'Future point was mislabeled current.');
	oras_ai_assert_contains('/gridpoints/PBZ/87,108', $calls[1]['url'], 'Future point did not use forecastGridData.');
});

oras_ai_test('M6 forecast retains valid temperature when cloud field is absent', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$forecast = oras_ai_test_nws_forecast();
	unset($forecast['properties']['skyCover']);
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response($forecast));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable('2026-09-10T01:00:00+00:00'), new DateTimeImmutable('2026-09-10T09:00:00+00:00')));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Missing cloud erased valid sibling forecast fields.');
	$data = $result->values()[0]->to_array();
	oras_ai_assert_same(null, $data['cloud_cover_percent'], 'Missing cloud was fabricated.');
	oras_ai_assert_same(11.0, $data['temperature_celsius'], 'Valid sibling temperature was lost.');
});

oras_ai_test('M6 absent structured precipitation remains unavailable rather than invented none', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$observation = oras_ai_test_nws_observation('2026-09-09T11:45:00+00:00');
	unset($observation['properties']['presentWeather']);
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_stations()), oras_ai_test_nws_response($observation));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same('', $result->values()[0]->to_array()['precipitation_type'], 'Absent precipitation field was asserted as none.');
});

oras_ai_test('M6 NWS cache uses bounded fixed slots and approved maximum TTLs', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$cache = new ORAS_AI_NWS_Cache($clock);
	$cache->put('point_mapping', array('grid_id' => 'PBZ'), 999999);
	$cache->put('station_mapping', array('station_id' => 'KFKL'), 999999);
	$cache->put('latest_observation', array('normalized' => true), 999999);
	$cache->put('forecast_grid', array('normalized' => true), 999999);
	$stored = get_option(ORAS_AI_NWS_Cache::OPTION);

	oras_ai_assert_same(array('point_mapping', 'station_mapping', 'latest_observation', 'forecast_grid'), array_keys($stored), 'Cache created unbounded per-request keys.');
	oras_ai_assert_same(86400, $stored['point_mapping']['expires_at'] - $clock->now()->getTimestamp(), 'Point mapping exceeded 24 hours.');
	oras_ai_assert_same(86400, $stored['station_mapping']['expires_at'] - $clock->now()->getTimestamp(), 'Station mapping exceeded 24 hours.');
	oras_ai_assert_same(300, $stored['latest_observation']['expires_at'] - $clock->now()->getTimestamp(), 'Observation cache exceeded five minutes.');
	oras_ai_assert_same(600, $stored['forecast_grid']['expires_at'] - $clock->now()->getTimestamp(), 'Forecast cache exceeded ten minutes.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_NWS_Cache::OPTION], 'NWS cache must not autoload.');
	oras_ai_assert_not_contains('950', wp_json_encode($stored), 'Cache stored user identity.');
	oras_ai_assert_not_contains('observing weather', strtolower(wp_json_encode($stored)), 'Cache stored raw question text.');
});

oras_ai_test('M6 NWS cache expires exactly at its configured TTL', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	(new ORAS_AI_NWS_Cache($clock))->put('latest_observation', array('issued_at' => '2026-09-09T12:00:00+00:00'), 300);
	$edgeClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:05:00+00:00'));
	oras_ai_assert_same(null, (new ORAS_AI_NWS_Cache($edgeClock))->get('latest_observation'), 'Cache remained reusable at its maximum TTL boundary.');
});

oras_ai_test('M6 malformed NWS units and payloads fail with bounded reasons', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$bad = oras_ai_test_nws_observation('2026-09-09T11:45:00+00:00');
	$bad['properties']['temperature']['unitCode'] = 'browserUnit:furlong';
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_stations()), oras_ai_test_nws_response($bad));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Malformed units were silently mixed.');
	oras_ai_assert_same('malformed_provider_units', $result->reason(), 'Malformed units exposed an unbounded reason.');
	oras_ai_assert_not_contains('furlong', wp_json_encode($result->to_array()), 'Raw malformed provider data escaped.');
});

oras_ai_test('M6 oversized NWS response is rejected before raw payload normalization', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$calls = array();
	$responses = array(array(
		'response' => array('code' => 200),
		'body' => wp_json_encode(array('properties' => array('padding' => str_repeat('x', ORAS_AI_NWS_Weather_Provider::MAX_RESPONSE_BYTES)))),
		'headers' => array(),
	));
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock)))
		->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Oversized payload was admitted.');
	oras_ai_assert_same('malformed_provider_response', $result->reason(), 'Oversized payload did not fail with a bounded reason.');
	oras_ai_assert_not_contains(str_repeat('x', 100), wp_json_encode($result->to_array()), 'Oversized raw payload escaped.');
});

oras_ai_test('M6 NWS provider has no astronomy implementation dependency', function (): void {
	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-nws-weather-provider.php');
	foreach (array('SunCalc', 'Local_Sun_Moon', 'Astronomy_API', 'getSunTimes', 'astronomical_dusk') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $source, 'NWS adapter depends on astronomy calculation details.');
	}
});

oras_ai_test('M6 NWS honors WordPress response cache header objects within local caps', function (): void {
	oras_ai_test_reset();
	$firstClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$calls = array();
	$point = oras_ai_test_nws_response(oras_ai_test_nws_point());
	$point['headers'] = new ORAS_AI_Test_NWS_Headers(array('Cache-Control' => 'max-age=1'));
	$forecast = oras_ai_test_nws_response(oras_ai_test_nws_forecast());
	$forecast['headers'] = new ORAS_AI_Test_NWS_Headers(array('Cache-Control' => 'no-store'));
	$responses = array($point, $forecast);
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $firstClock, new ORAS_AI_NWS_Cache($firstClock));
	$start = new DateTimeImmutable('2026-09-10T01:00:00+00:00');
	$end = new DateTimeImmutable('2026-09-10T09:00:00+00:00');
	$provider->fetch(oras_ai_test_weather_request($firstClock, $start, $end));

	$secondClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:02+00:00'));
	$secondCalls = array();
	$secondResponses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast()));
	$second = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($secondResponses, $secondCalls), $secondClock, new ORAS_AI_NWS_Cache($secondClock));
	$second->fetch(oras_ai_test_weather_request($secondClock, $start, $end));

	oras_ai_assert_same('https://api.weather.gov/points/41.3219,-79.5854', $secondCalls[0]['url'], 'Expired object-backed HTTP cache metadata did not force point rediscovery.');
});

oras_ai_test('M6 NWS Expires header bounds mapping reuse when max-age is absent', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$calls = array();
	$point = oras_ai_test_nws_response(oras_ai_test_nws_point(), 200, array('Expires' => 'Wed, 09 Sep 2026 12:01:00 GMT'));
	$responses = array($point, oras_ai_test_nws_response(oras_ai_test_nws_stations()), oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T11:45:00+00:00')));
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, new ORAS_AI_NWS_Cache($clock));
	$provider->fetch(oras_ai_test_weather_request($clock));
	$stored = get_option(ORAS_AI_NWS_Cache::OPTION);

	oras_ai_assert_same(60, $stored['point_mapping']['expires_at'] - $clock->now()->getTimestamp(), 'Expires was ignored when Cache-Control max-age was absent.');
});

oras_ai_test('M6 expired observation cache never becomes stale-on-network-error fallback', function (): void {
	oras_ai_test_reset();
	$firstClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$calls = array();
	$responses = array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_stations()), oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T11:59:00+00:00')));
	$first = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $firstClock, new ORAS_AI_NWS_Cache($firstClock));
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $first->fetch(oras_ai_test_weather_request($firstClock))->status(), 'Observation cache was not primed.');

	$secondClock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:06:00+00:00'));
	$networkCalls = 0;
	$http = static function () use (&$networkCalls) { $networkCalls++; return new WP_Error('timeout', 'secret provider detail'); };
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', $http, $secondClock, new ORAS_AI_NWS_Cache($secondClock)))
		->fetch(oras_ai_test_weather_request($secondClock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $result->status(), 'Expired observation was returned after refresh failure.');
	oras_ai_assert_same('provider_request_failed', $result->reason(), 'Network failure did not use a safe reason.');
	oras_ai_assert_same(1, $networkCalls, 'General network failure was retried.');
	oras_ai_assert_not_contains('secret provider detail', wp_json_encode($result->to_array()), 'Raw network error escaped.');
});

oras_ai_test('M6 cached invalid grid receives one bounded rediscovery and no retry loop', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T20:30:00+00:00'));
	$cache = new ORAS_AI_NWS_Cache($clock);
	$cache->put('point_mapping', array('grid_id' => 'OLD', 'grid_x' => 1, 'grid_y' => 2), 86400);
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(array('title' => 'not found'), 404),
		oras_ai_test_nws_response(oras_ai_test_nws_point()),
		oras_ai_test_nws_response(oras_ai_test_nws_forecast()),
	);
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, $cache);
	$result = $provider->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable('2026-09-10T01:00:00+00:00'), new DateTimeImmutable('2026-09-10T09:00:00+00:00')));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'One invalid cached mapping did not rediscover safely.');
	oras_ai_assert_same(3, count($calls), 'Invalid mapping caused either no rediscovery or an unbounded retry loop.');
	oras_ai_assert_contains('/gridpoints/OLD/1,2', $calls[0]['url'], 'Cached mapping was not tried first.');
	oras_ai_assert_contains('/points/41.3219,-79.5854', $calls[1]['url'], 'Authoritative point was not rediscovered.');
});

oras_ai_test('M6 invalid cached grid during station discovery receives one point rediscovery', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$cache = new ORAS_AI_NWS_Cache($clock);
	$cache->put('point_mapping', array('grid_id' => 'OLD', 'grid_x' => 1, 'grid_y' => 2), 86400);
	$calls = array();
	$responses = array(
		oras_ai_test_nws_response(array('title' => 'old mapping'), 404),
		oras_ai_test_nws_response(oras_ai_test_nws_point()),
		oras_ai_test_nws_response(oras_ai_test_nws_stations()),
		oras_ai_test_nws_response(oras_ai_test_nws_observation('2026-09-09T11:45:00+00:00')),
	);
	$result = (new ORAS_AI_NWS_Weather_Provider('ORAS AI test@example.org', oras_ai_test_nws_http($responses, $calls), $clock, $cache))
		->fetch(oras_ai_test_weather_request($clock));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Invalid cached grid could not recover station discovery.');
	oras_ai_assert_same(4, count($calls), 'Station discovery retried too many times or skipped rediscovery.');
	oras_ai_assert_contains('/points/41.3219,-79.5854', $calls[1]['url'], 'Authoritative point mapping was not re-resolved.');
});

foreach (array(
	'end equals request start' => array('2026-09-10T12:00:00Z', '2026-09-10T14:00:00Z', false),
	'start equals request end' => array('2026-09-09T22:00:00Z', '2026-09-10T00:00:00Z', false),
	'one second overlap' => array('2026-09-10T11:59:59Z', '2026-09-10T14:00:00Z', true),
	'contained' => array('2026-09-10T01:00:00Z', '2026-09-10T02:00:00Z', true),
	'enclosing' => array('2026-09-09T23:00:00Z', '2026-09-10T13:00:00Z', true),
	'identical' => array('2026-09-10T00:00:00Z', '2026-09-10T12:00:00Z', true),
	'point at start' => array('2026-09-10T00:00:00Z', '2026-09-10T00:00:00Z', true),
	'point inside' => array('2026-09-10T01:00:00Z', '2026-09-10T01:00:00Z', true),
	'point at end' => array('2026-09-10T12:00:00Z', '2026-09-10T12:00:00Z', false),
) as $label => $case) {
	oras_ai_test('M6 correction half-open NWS ' . $label, static function () use ($case): void {
		oras_ai_test_reset();
		$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00Z'));
		$calls = array();
		$provider = new ORAS_AI_NWS_Weather_Provider('ORAS fixture', oras_ai_test_nws_http(array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast())), $calls), $clock);
		$result = $provider->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable($case[0]), new DateTimeImmutable($case[1])));
		oras_ai_assert_same($case[2], ORAS_AI_Current_Data_Result::SUCCESS === $result->status(), 'Incorrect positive overlap or point containment.');
		if ($case[2]) {
			$data = $result->values()[0]->to_array();
			oras_ai_assert_same('2026-09-10T00:00:00+00:00', $data['valid_from'], 'Provider start was altered.');
			oras_ai_assert_same('2026-09-10T12:00:00+00:00', $data['valid_until'], 'Provider end was altered.');
		}
	});
}

oras_ai_test('M6 correction adjacent NWS periods have a single boundary owner for fields and points', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00Z'));
	$body = oras_ai_test_nws_forecast();
	// The sky midpoint is exactly a wind boundary; only the new wind period owns it.
	$body['properties']['windSpeed']['values'] = array(
		array('validTime' => '2026-09-10T00:00:00Z/PT6H', 'value' => 3.6),
		array('validTime' => '2026-09-10T06:00:00Z/PT6H', 'value' => 36),
	);
	$calls = array();
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS fixture', oras_ai_test_nws_http(array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response($body)), $calls), $clock);
	$result = $provider->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable('2026-09-10T01:00:00Z'), new DateTimeImmutable('2026-09-10T11:00:00Z')));
	oras_ai_assert_same(10.0, $result->values()[0]->to_array()['wind_speed_mps'], 'Expired wind field claimed adjacent boundary.');
	oras_ai_test_reset();
	$body['properties']['skyCover']['values'] = array(
		array('validTime' => '2026-09-10T00:00:00Z/PT6H', 'value' => 20),
		array('validTime' => '2026-09-10T06:00:00Z/PT6H', 'value' => 80),
	);
	$calls = array();
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS fixture', oras_ai_test_nws_http(array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response($body)), $calls), $clock);
	$point = new DateTimeImmutable('2026-09-10T06:00:00Z');
	$result = $provider->fetch(oras_ai_test_weather_request($clock, $point, $point));
	oras_ai_assert_same(1, count($result->values()), 'Both adjacent periods claimed the boundary point.');
	oras_ai_assert_same(80.0, $result->values()[0]->to_array()['cloud_cover_percent'], 'Wrong point period won.');
});

oras_ai_test('M6 correction expired boundary forecast cannot reach the answer model', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-10T12:00:00Z'));
	$calls = array();
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS fixture', oras_ai_test_nws_http(array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast())), $calls), $clock);
	$weather = new ORAS_AI_Current_Weather_Service($provider, new ORAS_AI_Astronomical_Night_Resolver(new ORAS_AI_Local_Sun_Moon_Provider($clock), $clock), $clock);
	list($orchestrator, $model) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), null, null, $weather);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(1120, 'What is the weather at ORAS from 8 AM to 10 AM?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Bounded crossover unavailability could not be explained.');
	$context = $model->calls[0]['context'];
	foreach ($context->evidence_packet()->items() as $item) {
		oras_ai_assert_true('current_weather' !== $item->field('source_type'), 'Expired weather fact reached model context.');
	}
	oras_ai_assert_contains('forecast_interval_unavailable', wp_json_encode($context->provider_input()), 'Forecast absence was hidden.');
	oras_ai_assert_not_contains('Cloud cover 35', wp_json_encode($context->provider_input()), 'Expired cloud forecast reached synthesis.');
});

oras_ai_test('M6 correction cached NWS forecast obeys the same half-open requested window', function (): void {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T16:00:00Z'));
	$calls = array();
	$provider = new ORAS_AI_NWS_Weather_Provider('ORAS fixture', oras_ai_test_nws_http(array(oras_ai_test_nws_response(oras_ai_test_nws_point()), oras_ai_test_nws_response(oras_ai_test_nws_forecast())), $calls), $clock);
	$prime = $provider->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable('2026-09-09T23:00:00Z'), new DateTimeImmutable('2026-09-10T14:00:00Z')));
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $prime->status(), 'Cache did not prime.');
	foreach (array(
		array('2026-09-10T12:00:00Z', '2026-09-10T14:00:00Z'),
		array('2026-09-09T23:00:00Z', '2026-09-10T00:00:00Z'),
		array('2026-09-10T12:00:00Z', '2026-09-10T12:00:00Z'),
	) as $case) {
		$result = $provider->fetch(oras_ai_test_weather_request($clock, new DateTimeImmutable($case[0]), new DateTimeImmutable($case[1])));
		oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Cache bypassed half-open validity.');
		oras_ai_assert_same(array(), $result->values(), 'Non-overlapping cached forecast escaped.');
	}
	oras_ai_assert_same(2, count($calls), 'Valid cache reuse made unnecessary HTTP calls.');
});
