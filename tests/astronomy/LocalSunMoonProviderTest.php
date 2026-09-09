<?php
declare(strict_types=1);

function oras_ai_test_fact_map(ORAS_AI_Current_Data_Result $result): array {
	$map = array();
	foreach ($result->values() as $fact) {
		$data = $fact->to_array();
		$map[$data['fact_key']] = $data;
	}
	return $map;
}

function oras_ai_test_assert_minutes_near(string $expected, string $actual, int $minutes, string $message): void {
	$delta = abs((new DateTimeImmutable($expected))->getTimestamp() - (new DateTimeImmutable($actual))->getTimestamp());
	oras_ai_assert_true($delta <= $minutes * 60, $message . " Delta was {$delta} seconds.");
}

function oras_ai_test_assert_float_near(float $expected, float $actual, float $tolerance, string $message): void {
	oras_ai_assert_true(abs($expected - $actual) <= $tolerance, $message . ' Expected ' . $expected . ' +/- ' . $tolerance . ', got ' . $actual . '.');
}

oras_ai_test('M6 missing SunCalc dependency is bounded without disabling sibling astronomy', function (): void {
	oras_ai_assert_false(class_exists('Tlab\\SunCalc\\SunCalc', false), 'SunCalc was already loaded before the missing-dependency regression could run.');
	$autoloaders = spl_autoload_functions() ?: array();
	$composer_loaders = array();
	foreach ($autoloaders as $autoloader) {
		if (is_array($autoloader) && is_object($autoloader[0]) && $autoloader[0] instanceof Composer\Autoload\ClassLoader) {
			spl_autoload_unregister($autoloader);
			$composer_loaders[] = $autoloader;
		}
	}

	try {
		$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
		$clock = new ORAS_AI_Test_Fixed_Clock($instant);
		$authorized = oras_ai_test_authorized_request(902, 'Where are Jupiter and the Moon tonight?');
		$moon_request = ORAS_AI_Current_Data_Request::from_authorized_request(
			$authorized,
			$clock,
			array(ORAS_AI_Current_Data_Request::MOON_STATE)
		);
		$local = new ORAS_AI_Local_Sun_Moon_Provider($clock);
		$local_result = $local->fetch($moon_request);

		oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $local_result->status(), 'Missing SunCalc did not become bounded unavailable.');
		oras_ai_assert_same('local_calculation_failed', $local_result->reason(), 'Missing SunCalc exposed an unstable failure reason.');
		oras_ai_assert_same(array(), $local_result->values(), 'Missing SunCalc fabricated local astronomy facts.');
		oras_ai_assert_true(class_exists('ORAS_AI_Assistant', false), 'Plugin did not remain loaded without resolving SunCalc.');

		$unused = oras_ai_test_astronomy_provider('unused_test', static function (): ORAS_AI_Current_Data_Result {
			return ORAS_AI_Current_Data_Result::unknown('unused_test', 'unsupported_fact_type');
		});
		$planet = oras_ai_test_astronomy_provider('planet_test', static function (ORAS_AI_Current_Data_Request $request) use ($clock): ORAS_AI_Current_Data_Result {
			return ORAS_AI_Current_Data_Result::success('planet_test', array(
				new ORAS_AI_Astronomy_Fact('planet_test', ORAS_AI_Current_Data_Request::PLANET_POSITION, 24.0, 'degrees', $clock->now(), $request->requested_at(), 'planet:jupiter', 'astronomy:planet:jupiter:altitude', 'fixture-v1'),
			));
		});
		$result = (new ORAS_AI_Current_Astronomy_Service($local, $unused, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock))
			->query($authorized);
		$serialized = wp_json_encode($result->evidence_packet()->to_array());
		oras_ai_assert_contains('astronomy:planet:jupiter:altitude', $serialized, 'Missing SunCalc disabled an independent planet provider.');
		oras_ai_assert_contains('local_calculation_failed', $serialized, 'Bounded SunCalc failure was not retained beside sibling facts.');
	} finally {
		foreach ($composer_loaders as $autoloader) {
			spl_autoload_register($autoloader);
		}
	}
});

oras_ai_test('M6 local Sun and Moon calculations meet locked USNO reference tolerances', function (): void {
	// Independent U.S. Naval Observatory references captured for 2026-09-09
	// from aa.usno.navy.mil/data/api (rstt/oneday and calculated/rstt/year,
	// task=4) and aa.usno.navy.mil/calculated/altaz at the exact ORAS site.
	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($instant);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(903, 'What are sunset, darkness, and Moon conditions tonight?'),
		$clock,
		array(
			ORAS_AI_Current_Data_Request::SUNSET,
			ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS,
			ORAS_AI_Current_Data_Request::MOON_STATE,
		)
	);
	$result = (new ORAS_AI_Local_Sun_Moon_Provider($clock))->fetch($request);
	$facts = oras_ai_test_fact_map($result);

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Qualified local calculation did not succeed.');
	oras_ai_assert_same('1.0.1', $facts['astronomy:sun:sunset']['provider_version'], 'Pinned SunCalc provenance version missing.');
	oras_ai_test_assert_minutes_near('2026-09-09T23:38:00+00:00', $facts['astronomy:sun:sunset']['value'], 5, 'Sunset exceeded the locked +/-5 minute tolerance.');
	oras_ai_test_assert_minutes_near('2026-09-10T01:13:00+00:00', $facts['astronomy:sun:astronomical-dusk']['value'], 5, 'Astronomical dusk exceeded the locked +/-5 minute tolerance.');
	oras_ai_test_assert_minutes_near('2026-09-09T09:17:00+00:00', $facts['astronomy:sun:astronomical-dawn']['value'], 5, 'Astronomical dawn exceeded the locked +/-5 minute tolerance.');
	oras_ai_test_assert_minutes_near('2026-09-09T09:00:00+00:00', $facts['astronomy:moon:rise']['value'], 10, 'Moonrise exceeded the locked +/-10 minute tolerance.');
	oras_ai_test_assert_minutes_near('2026-09-09T22:55:00+00:00', $facts['astronomy:moon:set']['value'], 10, 'Moonset exceeded the locked +/-10 minute tolerance.');
	oras_ai_test_assert_float_near(31.1, (float) $facts['astronomy:moon:altitude']['value'], 1.0, 'Moon altitude exceeded the locked +/-1 degree tolerance.');
	oras_ai_test_assert_float_near(100.7, (float) $facts['astronomy:moon:azimuth']['value'], 1.0, 'Moon azimuth exceeded the locked +/-1 degree tolerance.');
	oras_ai_test_assert_float_near(0.03, (float) $facts['astronomy:moon:illumination']['value'], 0.02, 'Moon illumination exceeded the locked absolute 0.02 tolerance.');
	oras_ai_assert_same('above', $facts['astronomy:moon:geometric_horizon']['value'], 'Positive Moon altitude was not labeled above the geometric horizon.');
	oras_ai_assert_not_contains('visible', wp_json_encode($result->to_array()), 'Local calculation must not turn geometric horizon into generic visibility.');
});

oras_ai_test('M6 local Moon calculation preserves below geometric horizon state', function (): void {
	$instant = new DateTimeImmutable('2026-09-09T08:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($instant);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(904, 'Where is the Moon now?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::MOON_STATE)
	);
	$facts = oras_ai_test_fact_map((new ORAS_AI_Local_Sun_Moon_Provider($clock))->fetch($request));

	oras_ai_test_assert_float_near(-10.5, (float) $facts['astronomy:moon:altitude']['value'], 1.0, 'Below-horizon Moon altitude exceeded the locked tolerance.');
	oras_ai_test_assert_float_near(61.1, (float) $facts['astronomy:moon:azimuth']['value'], 1.0, 'Below-horizon Moon azimuth exceeded the locked tolerance.');
	oras_ai_assert_same('below', $facts['astronomy:moon:geometric_horizon']['value'], 'Negative Moon altitude was not labeled below the geometric horizon.');
});
