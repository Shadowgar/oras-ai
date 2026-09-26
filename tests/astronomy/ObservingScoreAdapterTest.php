<?php
declare(strict_types=1);

final class ORAS_AI_Test_Member_Hub_Score_API {
	public static $callback;
	public static array $calls = array();
	public static function compute_observing_score_for_context(...$args) {
		self::$calls[] = $args;
		return call_user_func(self::$callback, ...$args);
	}
	public static function get_payload() {
		throw new RuntimeException('The score adapter must not fetch Member Hub payloads.');
	}
}

final class ORAS_AI_Test_Member_Hub_Without_Score_API {}

function oras_ai_test_score_weather(?float $cloud = 40.0, ?float $precip = 10.0, ?float $wind = 2.0): ORAS_AI_Weather_Snapshot {
	return new ORAS_AI_Weather_Snapshot(
		'weather_test', new DateTimeImmutable('2026-09-09T23:30:00Z'), new DateTimeImmutable('2026-09-09T23:30:00Z'),
		new DateTimeImmutable('2026-09-10T00:00:00Z'), new DateTimeImmutable('2026-09-10T02:00:00Z'),
		ORAS_AI_Weather_Snapshot::FORECAST, $cloud, $precip, 'none', 12.0, $wind, null, 70.0, 16000.0, 'forecast_uncertain'
	);
}

function oras_ai_test_score_moon(DateTimeImmutable $at, bool $above = true): array {
	$calculated = new DateTimeImmutable('2026-09-09T23:40:00Z');
	return array(
		new ORAS_AI_Astronomy_Fact('suncalc', ORAS_AI_Current_Data_Request::MOON_STATE, 0.42, 'fraction', $calculated, $at, 'moon', 'astronomy:moon:illumination', 'test-v1'),
		new ORAS_AI_Astronomy_Fact('suncalc', ORAS_AI_Current_Data_Request::MOON_STATE, $above ? 'above' : 'below', 'geometric_horizon', $calculated, $at, 'moon', 'astronomy:moon:geometric_horizon', 'test-v1'),
	);
}

function oras_ai_test_score_api_result(): array {
	return array(
		'score' => 68, 'category' => 'marginal', 'dominant_limiter' => 'cloud', 'confidence_band' => 'low',
		'threshold_profile' => 'oras_custom', 'model_version' => 'v4-scientific-audit-tier-c', 'method_version' => 'tier-c-audit-2026-06',
	);
}

oras_ai_test('M6 score adapter returns bounded unavailable when Member Hub class or method is absent', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T23:45:00Z'));
	$at = new DateTimeImmutable('2026-09-10T01:00:00Z');
	foreach (array('ORAS_MH_Missing_Service', ORAS_AI_Test_Member_Hub_Without_Score_API::class) as $class) {
		$result = (new ORAS_AI_Member_Hub_Score_Adapter($clock, $class))->score(oras_ai_test_score_weather(), oras_ai_test_score_moon($at), $at);
		oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $result->status(), 'Missing Member Hub must not fatal or score locally.');
		oras_ai_assert_same('score_provider_unavailable', $result->reason(), 'Missing API leaked an unbounded reason.');
	}
});

oras_ai_test('M6 score adapter maps only qualified normalized inputs and preserves authoritative output', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T23:45:00Z'));
	$at = new DateTimeImmutable('2026-09-10T01:00:00Z');
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	$result = (new ORAS_AI_Member_Hub_Score_Adapter($clock, ORAS_AI_Test_Member_Hub_Score_API::class))->score(oras_ai_test_score_weather(), oras_ai_test_score_moon($at, false), $at);
	$call = ORAS_AI_Test_Member_Hub_Score_API::$calls[0] ?? array();
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Qualified Member Hub result was not admitted.');
	oras_ai_assert_same(40.0, $call[0]['cloud_cover_pct'], 'Cloud cover mapping changed.');
	oras_ai_assert_same(10.0, $call[0]['precip_probability'], 'Precipitation mapping changed.');
	oras_ai_assert_true(abs($call[0]['wind_mph'] - 4.4738725842) < 0.0000001, 'SI wind was not converted to mph exactly at the adapter.');
	oras_ai_assert_same(array(), $call[1], 'Unavailable smoke was invented.');
	oras_ai_assert_same(42.0, $call[2]['illumination'], 'Moon fraction was not converted to a percentage.');
	oras_ai_assert_same('missing', $call[3]['smoke']['status'], 'Missing-smoke health was not explicit.');
	oras_ai_assert_same($at->format(DATE_ATOM), $call[4]->format(DATE_ATOM), 'Score instant diverged from weather midpoint.');
	oras_ai_assert_false($call[5], 'Moon geometric state was not supplied.');
	oras_ai_assert_false(isset($call[0]['cloud_cover_low_pct']), 'Unqualified cloud layer was fabricated.');
	oras_ai_assert_false(isset($call[1]['pm2_5']), 'PM2.5 was fabricated.');
	$data = $result->values()[0]->to_array();
	oras_ai_assert_same(68, $data['score'], 'Authoritative score changed.');
	oras_ai_assert_same('marginal', $data['category'], 'Machine category changed.');
	oras_ai_assert_same('v4-scientific-audit-tier-c', $data['model_version'], 'Model version changed.');
	oras_ai_assert_same('tier-c-audit-2026-06', $data['method_version'], 'Method version changed.');
	oras_ai_assert_same('cloud', $data['limiter'], 'Dominant limiter changed.');
	oras_ai_assert_same('low', $data['confidence'], 'Missing-smoke confidence changed.');
	oras_ai_assert_same('2026-09-09T23:30:00+00:00', $data['data_at'], 'Weather source freshness was not preserved.');
	oras_ai_assert_same('2026-09-09T23:45:00+00:00', $data['calculated_at'], 'Trusted calculation time was not attached.');
	oras_ai_assert_same(1, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Score API was called more than once.');
});

oras_ai_test('M6 score adapter bounds WP_Error and malformed score results', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T23:45:00Z'));
	$at = new DateTimeImmutable('2026-09-10T01:00:00Z');
	$adapter = new ORAS_AI_Member_Hub_Score_Adapter($clock, ORAS_AI_Test_Member_Hub_Score_API::class);
	foreach (array(new WP_Error('secret_provider_detail', 'Private detail'), array('score' => 1000), 'invalid') as $invalid) {
		ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () use ($invalid) { return $invalid; };
		$result = $adapter->score(oras_ai_test_score_weather(), oras_ai_test_score_moon($at), $at);
		oras_ai_assert_true(in_array($result->status(), array(ORAS_AI_Current_Data_Result::UNAVAILABLE, ORAS_AI_Current_Data_Result::UNKNOWN), true), 'Malformed score escaped.');
		oras_ai_assert_not_contains('secret_provider_detail', wp_json_encode($result->to_array()), 'Raw Member Hub error escaped.');
	}
});

oras_ai_test('M6 missing weather or Moon blocks score without invoking Member Hub', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T23:45:00Z'));
	$at = new DateTimeImmutable('2026-09-10T01:00:00Z');
	ORAS_AI_Test_Member_Hub_Score_API::$calls = array();
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	$adapter = new ORAS_AI_Member_Hub_Score_Adapter($clock, ORAS_AI_Test_Member_Hub_Score_API::class);
	$missing = array(
		$adapter->score(oras_ai_test_score_weather(null), oras_ai_test_score_moon($at), $at),
		$adapter->score(oras_ai_test_score_weather(), array(), $at),
	);
	foreach ($missing as $result) {
		oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $result->status(), 'Missing qualified input fabricated a score.');
	}
	oras_ai_assert_same(0, count(ORAS_AI_Test_Member_Hub_Score_API::$calls), 'Member Hub was called with missing inputs.');
});
