<?php
declare(strict_types=1);

oras_ai_test('M6 IAU reference transformation meets locked point-one-degree tolerance', function (): void {
	// Independent fixed expectations were generated with Astropy/ERFA's IAU
	// SOFA-backed ICRS-to-AltAz transform, pressure=0 (no refraction).
	$calculator = new ORAS_AI_Horizontal_Position_Calculator();
	$site = ORAS_AI_Observing_Site::oras_observatory();
	$instant = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$cases = array(
		array('M31', 10.6847917, 41.2690556, 37.9783964, 64.4617173),
		array('NGC 4565', 189.0865833, 25.9876667, 3.1658707, 302.1863227),
		array('M42', 83.81775, -5.3896667, -42.1674604, 51.0822102),
	);
	foreach ($cases as $case) {
		$position = $calculator->calculate($case[1], $case[2], $instant, $site);
		oras_ai_test_assert_float_near($case[3], $position['altitude'], 0.1, $case[0] . ' altitude exceeded the locked +/-0.1 degree tolerance.');
		oras_ai_test_assert_float_near($case[4], $position['azimuth'], 0.1, $case[0] . ' azimuth exceeded the locked +/-0.1 degree tolerance.');
		oras_ai_assert_same($position['altitude'] > 0.0 ? 'above' : 'below', $position['geometric_horizon'], $case[0] . ' geometric horizon state changed.');
	}
});

oras_ai_test('M6 horizontal transformation validates coordinates and normalizes angles', function (): void {
	$calculator = new ORAS_AI_Horizontal_Position_Calculator();
	$site = ORAS_AI_Observing_Site::oras_observatory();
	$instant = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$position = $calculator->calculate(370.6847917, 41.2690556, $instant, $site);
	oras_ai_assert_true($position['azimuth'] >= 0.0 && $position['azimuth'] < 360.0, 'Azimuth was not normalized.');

	foreach (array(array(NAN, 0.0), array(0.0, 91.0), array(0.0, -91.0)) as $invalid) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($calculator, $site, $instant, $invalid): void {
				$calculator->calculate($invalid[0], $invalid[1], $instant, $site);
			},
			'Invalid equatorial coordinate was accepted.'
		);
	}
});
