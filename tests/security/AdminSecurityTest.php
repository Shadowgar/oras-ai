<?php
declare(strict_types=1);

oras_ai_test('scanner AJAX rejects users without manage_options before nonce verification', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	try {
		(new ORAS_AI_Sources())->ajax_discover_sources();
		throw new RuntimeException('Expected scanner AJAX permission rejection.');
	} catch (ORAS_AI_Test_Json_Response $response) {
		oras_ai_assert_false($response->success, 'Permission rejection should be a JSON error.');
		oras_ai_assert_same(403, $response->status, 'Permission rejection HTTP status changed.');
		oras_ai_assert_same('Permission denied.', $response->data['message'], 'Permission rejection message changed.');
	}
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_ajax_nonce_checks'], 'Unauthorized scanner request should stop before nonce verification.');
});

oras_ai_test('scanner AJAX requires the oras_ai_scan nonce', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	try {
		(new ORAS_AI_Sources())->ajax_discover_sources();
		throw new RuntimeException('Expected scanner AJAX nonce rejection.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same('Invalid AJAX nonce.', $exception->getMessage(), 'Unexpected nonce failure.');
	}
	oras_ai_assert_same(array(array('oras_ai_scan', 'nonce')), $GLOBALS['oras_ai_test_ajax_nonce_checks'], 'Scanner nonce action or field changed.');
});

oras_ai_test('settings save rejects users without manage_options before nonce verification', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	try {
		(new ORAS_AI_Sources())->save_settings();
		throw new RuntimeException('Expected settings capability rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('do not have permission', $exception->getMessage(), 'Settings permission message changed.');
	}
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized settings save should stop before nonce verification.');
	oras_ai_assert_same(array(), ORAS_AI_Audit_Log::recent_events(), 'Failed capability check must not create audit events.');
});

oras_ai_test('settings save requires the oras_ai_save_settings nonce', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	try {
		(new ORAS_AI_Sources())->save_settings();
		throw new RuntimeException('Expected settings nonce rejection.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same('Invalid admin nonce.', $exception->getMessage(), 'Unexpected settings nonce failure.');
	}
	oras_ai_assert_same(
		array(array('oras_ai_save_settings', 'oras_ai_settings_nonce')),
		$GLOBALS['oras_ai_test_admin_nonce_checks'],
		'Settings nonce action or field changed.'
	);
	oras_ai_assert_same(array(), ORAS_AI_Audit_Log::recent_events(), 'Failed nonce check must not create audit events.');
});

oras_ai_test('knowledge save requires a valid entry nonce', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = array(
		'oras_ai_entry_nonce' => 'invalid',
		'oras_ai_status' => 'approved',
	);
	(new ORAS_AI_Knowledge_Base())->save_entry(55);
	oras_ai_assert_same(array(), get_post_meta(55), 'Invalid KB nonce must prevent all metadata writes.');
});

oras_ai_test('knowledge save requires edit_post capability', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['edit_post'] = false;
	$_POST = array(
		'oras_ai_entry_nonce' => 'valid',
		'oras_ai_status' => 'approved',
	);
	(new ORAS_AI_Knowledge_Base())->save_entry(56);
	oras_ai_assert_same(array(), get_post_meta(56), 'Missing edit capability must prevent all KB metadata writes.');
});

oras_ai_test('scanner JavaScript localization excludes the API key', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_screen'] = (object) array('id' => 'oras-ai_page_oras-ai-sources', 'post_type' => '');
	$_GET['page'] = 'oras-ai-sources';
	(new ORAS_AI_Assistant())->enqueue_admin_assets();

	$localized = $GLOBALS['oras_ai_test_localized_scripts']['oras-ai-scanner'];
	oras_ai_assert_same('ORAS_AI_SCAN', $localized['object_name'], 'Scanner JavaScript object name changed.');
	$serialized = wp_json_encode($localized['data']);
	oras_ai_assert_not_contains('constant-key', $serialized, 'API key must not be exposed in scanner localization.');
	oras_ai_assert_not_contains('apiKey', $serialized, 'Scanner localization should not contain an API-key field.');
	oras_ai_assert_same(
		array('ajaxUrl', 'nonce', 'settings', 'strings'),
		array_keys($localized['data']),
		'Scanner localization data contract changed.'
	);
});

oras_ai_test('stored API key is not rendered into the admin password field', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-option-key');
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$html = (string) ob_get_clean();

	oras_ai_assert_contains('type="password"', $html, 'API key input should remain a password field.');
	oras_ai_assert_contains('value=""', $html, 'API key password field should render blank.');
	oras_ai_assert_not_contains('stored-option-key', $html, 'Stored API key must not be rendered back to the browser.');
});
