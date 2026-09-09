<?php
declare(strict_types=1);

function oras_ai_test_submit_m6_provider_settings(array $post): ORAS_AI_Test_Redirect_Exception {
	$_POST = $post;
	try {
		(new ORAS_AI_M6_Provider_Admin())->save_settings();
	} catch (ORAS_AI_Test_Redirect_Exception $redirect) {
		return $redirect;
	}

	throw new RuntimeException('Expected M6 provider settings redirect.');
}

function oras_ai_test_m6_provider_post(array $overrides = array()): array {
	return array_merge(
		array(
			'oras_ai_m6_provider_nonce' => 'valid',
			'astronomyapi_application_id' => 'fixture-id',
			'astronomyapi_application_secret' => 'fixture-secret',
			'nws_contact' => 'weather-ops@oras.org',
		),
		$overrides
	);
}

oras_ai_test('M6 provider admin is manage-options protected and shows immutable site plus safe configuration status', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start();
	(new ORAS_AI_M6_Provider_Admin())->render_page();
	$unauthorized = (string) ob_get_clean();
	oras_ai_assert_same('', $unauthorized, 'Unauthorized user must not see M6 provider settings.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ORAS_AI_Config::update_stored_astronomyapi_credentials('fixture-id', 'fixture-secret-never-render');
	ORAS_AI_Config::update_nws_contact('weather-ops@oras.org');
	ob_start();
	(new ORAS_AI_M6_Provider_Admin())->render_page();
	$html = (string) ob_get_clean();

	oras_ai_assert_contains('Astronomy &amp; Weather Providers', $html, 'M6 provider settings heading missing.');
	oras_ai_assert_contains('41.321903', $html, 'Trusted latitude missing.');
	oras_ai_assert_contains('-79.585394', $html, 'Trusted longitude missing.');
	oras_ai_assert_contains('432.816', $html, 'Trusted elevation missing.');
	oras_ai_assert_contains('America/New_York', $html, 'Trusted timezone missing.');
	oras_ai_assert_contains('Configured', $html, 'AstronomyAPI configuration status missing.');
	oras_ai_assert_contains('value=""', $html, 'Secret field must render blank.');
	oras_ai_assert_not_contains('fixture-secret-never-render', $html, 'Stored AstronomyAPI secret was echoed.');
	oras_ai_assert_not_contains('name="latitude"', $html, 'Trusted latitude must not be browser-editable.');
	oras_ai_assert_not_contains('name="longitude"', $html, 'Trusted longitude must not be browser-editable.');
});

oras_ai_test('M6 provider settings require manage-options before nonce verification', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$_POST = oras_ai_test_m6_provider_post();
	try {
		(new ORAS_AI_M6_Provider_Admin())->save_settings();
		throw new RuntimeException('Expected unauthorized M6 provider save to fail.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('permission', strtolower($exception->getMessage()), 'Capability denial message changed.');
	}
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized save must stop before nonce verification.');
});

oras_ai_test('M6 provider settings require an action-specific nonce', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = oras_ai_test_m6_provider_post();
	try {
		(new ORAS_AI_M6_Provider_Admin())->save_settings();
		throw new RuntimeException('Expected invalid M6 provider nonce to fail.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same(
			array(array(ORAS_AI_M6_Provider_Admin::NONCE_ACTION, 'oras_ai_m6_provider_nonce')),
			$GLOBALS['oras_ai_test_admin_nonce_checks'],
			'M6 provider nonce scope changed.'
		);
	}
});

oras_ai_test('M6 provider settings save validated credentials and NWS contact without echoing secrets', function (): void {
	oras_ai_test_reset();
	$redirect = oras_ai_test_submit_m6_provider_settings(oras_ai_test_m6_provider_post());

	oras_ai_assert_same('fixture-id', ORAS_AI_Config::get_astronomyapi_application_id(), 'Application ID did not save.');
	oras_ai_assert_same('fixture-secret', ORAS_AI_Config::get_astronomyapi_application_secret(), 'Application secret did not save.');
	oras_ai_assert_same('weather-ops@oras.org', ORAS_AI_Config::get_nws_contact(), 'NWS contact did not save.');
	oras_ai_assert_contains('settings-updated=1', $redirect->location, 'M6 settings redirect missing.');
	$events = wp_json_encode(ORAS_AI_Audit_Log::recent_events());
	oras_ai_assert_not_contains('fixture-secret', $events, 'Audit log exposed the AstronomyAPI secret.');
	oras_ai_assert_contains('config.m6.astronomyapi_credentials', $events, 'Credential configuration change was not audited safely.');
	oras_ai_assert_contains('config.m6.nws_contact', $events, 'NWS configuration change was not audited safely.');
});

oras_ai_test('M6 provider settings preserve an existing secret when the password field is blank', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::update_stored_astronomyapi_credentials('fixture-id', 'existing-secret');
	oras_ai_test_submit_m6_provider_settings(
		oras_ai_test_m6_provider_post(
			array(
				'astronomyapi_application_secret' => '',
				'nws_contact' => 'https://www.oras.org/contact/',
			)
		)
	);

	oras_ai_assert_same('existing-secret', ORAS_AI_Config::get_astronomyapi_application_secret(), 'Blank admin field erased the stored secret.');
	oras_ai_assert_same('https://www.oras.org/contact/', ORAS_AI_Config::get_nws_contact(), 'Valid HTTPS contact did not save.');
});

oras_ai_test('M6 provider settings reject malformed submissions without partial writes', function (): void {
	oras_ai_test_reset();
	$invalidPosts = array(
		oras_ai_test_m6_provider_post(array('astronomyapi_application_id' => 'bad id')),
		oras_ai_test_m6_provider_post(array('astronomyapi_application_secret' => "bad\r\nsecret")),
		oras_ai_test_m6_provider_post(array('nws_contact' => 'javascript:alert(1)')),
	);

	foreach ($invalidPosts as $post) {
		$_POST = $post;
		try {
			(new ORAS_AI_M6_Provider_Admin())->save_settings();
			throw new RuntimeException('Expected malformed M6 provider settings to fail.');
		} catch (ORAS_AI_Test_Die_Exception $exception) {
			oras_ai_assert_contains('invalid', strtolower($exception->getMessage()), 'Malformed settings must fail generically.');
		}
		oras_ai_assert_false(ORAS_AI_Config::has_astronomyapi_credentials(), 'Invalid settings partially stored credentials.');
		oras_ai_assert_same('', ORAS_AI_Config::get_nws_contact(), 'Invalid settings partially stored NWS contact.');
	}
});

oras_ai_test('M6 provider settings submenu remains protected inside ORAS AI', function (): void {
	oras_ai_test_reset();
	$assistant = new ORAS_AI_Assistant();
	$assistant->register_admin_menu();
	$matches = array_values(
		array_filter(
			$GLOBALS['oras_ai_test_submenu_pages'],
			static function (array $menu): bool {
				return 'oras-ai-m6-providers' === ($menu[4] ?? '');
			}
		)
	);

	oras_ai_assert_same(1, count($matches), 'M6 provider settings submenu missing.');
	oras_ai_assert_same('oras-ai-assistant', $matches[0][0], 'M6 provider settings escaped the plugin menu.');
	oras_ai_assert_same('manage_options', $matches[0][3], 'M6 provider settings capability changed.');
});
