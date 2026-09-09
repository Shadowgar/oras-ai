<?php
declare(strict_types=1);

oras_ai_test('M6 provider configuration preserves server-only option names', function (): void {
	oras_ai_assert_same('oras_ai_astronomyapi_application_id', ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_ID, 'AstronomyAPI application-ID option changed.');
	oras_ai_assert_same('oras_ai_astronomyapi_application_secret', ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_SECRET, 'AstronomyAPI secret option changed.');
	oras_ai_assert_same('oras_ai_nws_contact', ORAS_AI_Config::OPTION_NWS_CONTACT, 'NWS contact option changed.');
});

oras_ai_test('M6 provider configuration stores fake AstronomyAPI credentials server-side', function (): void {
	oras_ai_test_reset();
	$result = ORAS_AI_Config::update_stored_astronomyapi_credentials('fixture-app-id', 'fixture-app-secret');

	oras_ai_assert_true($result, 'Valid fake credentials were not stored.');
	oras_ai_assert_true(ORAS_AI_Config::has_astronomyapi_credentials(), 'Stored credentials should report configured.');
	oras_ai_assert_same('fixture-app-id', ORAS_AI_Config::get_astronomyapi_application_id(), 'Application ID retrieval changed.');
	oras_ai_assert_same('fixture-app-secret', ORAS_AI_Config::get_astronomyapi_application_secret(), 'Application secret retrieval changed.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_ID], 'Application ID must not autoload.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_SECRET], 'Application secret must not autoload.');
});

oras_ai_test('M6 provider configuration rejects malformed credentials and NWS contact', function (): void {
	oras_ai_test_reset();
	foreach (
		array(
			array('bad id with spaces', 'fixture-secret'),
			array('fixture-id', "secret\r\nInjected: header"),
			array('', 'fixture-secret'),
		) as $credentials
	) {
		$result = ORAS_AI_Config::update_stored_astronomyapi_credentials($credentials[0], $credentials[1]);
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_provider_configuration', 'Malformed provider credentials must fail.');
	}

	foreach (array('not-contact', 'http://example.test/contact', "ops@example.test\r\nInjected: value") as $contact) {
		$result = ORAS_AI_Config::update_nws_contact($contact);
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_provider_configuration', 'Malformed NWS contact must fail.');
	}
});

oras_ai_test('M6 NWS configuration builds only an identifying bounded user agent', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_same('', ORAS_AI_Config::get_nws_user_agent(), 'Unconfigured NWS contact must not produce a misleading User-Agent.');
	oras_ai_assert_true(ORAS_AI_Config::update_nws_contact('weather-ops@oras.org'), 'Valid NWS contact was not stored.');
	oras_ai_assert_same('weather-ops@oras.org', ORAS_AI_Config::get_nws_contact(), 'NWS contact changed.');
	oras_ai_assert_same('ORAS AI Assistant/0.2.1 (weather-ops@oras.org)', ORAS_AI_Config::get_nws_user_agent(), 'NWS User-Agent changed.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Config::OPTION_NWS_CONTACT], 'NWS contact must not autoload.');
});

oras_ai_test('M6 credentials have no frontend evidence conversation or audit serialization path', function (): void {
	oras_ai_test_reset();
	$secret = 'fixture-secret-never-expose';
	ORAS_AI_Config::update_stored_astronomyapi_credentials('fixture-id', $secret);
	ORAS_AI_Audit_Log::log_m6_provider_config_changed('astronomyapi_credentials', 'set');

	$instant = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$fact = new ORAS_AI_Astronomy_Fact('astronomy_api', 'planet_position', 10.0, 'degrees', $instant, $instant, 'planet:saturn');
	$result = ORAS_AI_Current_Data_Result::success('astronomy_api', array($fact));
	$serialized = wp_json_encode(
		array(
			'audit' => ORAS_AI_Audit_Log::recent_events(),
			'evidence' => $fact->to_array(),
			'result' => $result->to_array(),
			'frontend' => $GLOBALS['oras_ai_test_localized_scripts'],
		)
	);

	oras_ai_assert_not_contains($secret, $serialized, 'AstronomyAPI secret leaked from server-only configuration.');
	oras_ai_assert_not_contains('fixture-id', $serialized, 'AstronomyAPI application ID leaked into model-facing/result state.');
});
