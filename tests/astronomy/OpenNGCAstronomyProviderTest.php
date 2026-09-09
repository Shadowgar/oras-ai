<?php
declare(strict_types=1);

oras_ai_test('M6 OpenNGC provider emits independent qualified target-position facts', function (): void {
	$instant = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($instant);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(907, 'Where is M31?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::TARGET_POSITION),
		null,
		null,
		'm31'
	);
	$result = (new ORAS_AI_OpenNGC_Astronomy_Provider(new ORAS_AI_OpenNGC_Target_Resolver(), new ORAS_AI_Horizontal_Position_Calculator(), $clock))->fetch($request);
	$facts = oras_ai_test_fact_map($result);

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::SUCCESS, $result->status(), 'Resolved OpenNGC target was not calculated.');
	oras_ai_test_assert_float_near(37.9783964, (float) $facts['astronomy:target:m31:altitude']['value'], 0.1, 'M31 altitude exceeded the locked tolerance.');
	oras_ai_test_assert_float_near(64.4617173, (float) $facts['astronomy:target:m31:azimuth']['value'], 0.1, 'M31 azimuth exceeded the locked tolerance.');
	oras_ai_assert_same('above', $facts['astronomy:target:m31:geometric_horizon']['value'], 'M31 horizon state changed.');
	oras_ai_assert_same('openngc_local', $facts['astronomy:target:m31:altitude']['provider'], 'Catalog provider identity changed.');
	oras_ai_assert_same('da90466031b0372c896588b85be6016c617e205b', $facts['astronomy:target:m31:altitude']['provider_version'], 'Catalog provenance version missing.');
	oras_ai_assert_same('oras_observatory', $facts['astronomy:target:m31:altitude']['site_identity'], 'Authoritative site provenance missing.');
	oras_ai_assert_not_contains('visible', wp_json_encode($result->to_array()), 'Geometric target position became generic visibility.');
});

oras_ai_test('M6 unresolved OpenNGC target fails boundedly without using client coordinates', function (): void {
	$instant = new DateTimeImmutable('2026-09-10T02:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($instant);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(908, 'Use RA 12 and Dec 10 for Mystery Object'),
		$clock,
		array(ORAS_AI_Current_Data_Request::TARGET_POSITION),
		null,
		null,
		'mystery_object'
	);
	$result = (new ORAS_AI_OpenNGC_Astronomy_Provider(new ORAS_AI_OpenNGC_Target_Resolver(), new ORAS_AI_Horizontal_Position_Calculator(), $clock))->fetch($request);
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $result->status(), 'Unknown target was guessed.');
	oras_ai_assert_same('target_not_resolved', $result->reason(), 'Unknown-target reason changed.');
});
