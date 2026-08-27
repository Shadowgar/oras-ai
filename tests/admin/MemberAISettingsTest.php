<?php
declare(strict_types=1);

function oras_ai_test_submit_settings(array $post): ORAS_AI_Test_Redirect_Exception {
	$_POST = $post;
	try {
		(new ORAS_AI_Sources())->save_settings();
	} catch (ORAS_AI_Test_Redirect_Exception $redirect) {
		return $redirect;
	}

	throw new RuntimeException('Expected settings save redirect.');
}

oras_ai_test('member AI checkbox renders the current saved state', function (): void {
	oras_ai_test_reset();
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$enabledHtml = (string) ob_get_clean();
	oras_ai_assert_contains('name="oras_ai_member_ai_enabled"', $enabledHtml, 'Member AI checkbox should render.');
	oras_ai_assert_contains('checked="checked"', $enabledHtml, 'Default enabled state should render checked.');
	oras_ai_assert_contains('Administrative source scanning and knowledge management remain available.', $enabledHtml, 'Kill-switch scope explanation changed.');

	update_option('oras_ai_member_ai_enabled', '0');
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$disabledHtml = (string) ob_get_clean();
	oras_ai_assert_not_contains('checked="checked"', $disabledHtml, 'Stored disabled state should render unchecked.');
});

oras_ai_test('settings save stores checked member AI with API key and model', function (): void {
	oras_ai_test_reset();
	$redirect = oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-terra',
			'oras_ai_api_key' => 'new-api-key',
		)
	);

	oras_ai_assert_same('1', get_option('oras_ai_member_ai_enabled'), 'Checked member AI should save enabled.');
	oras_ai_assert_same('gpt-5.6-terra', get_option('oras_ai_openai_model'), 'Model should still save in the same submission.');
	oras_ai_assert_same('new-api-key', get_option('oras_ai_openai_api_key'), 'API key should still save in the same submission.');
	oras_ai_assert_same(
		'https://example.test/wp-admin/admin.php?page=oras-ai-settings&settings-updated=1',
		$redirect->location,
		'Settings redirect changed.'
	);
});

oras_ai_test('settings save stores unchecked member AI as disabled', function (): void {
	oras_ai_test_reset();
	update_option('oras_ai_member_ai_enabled', '1');
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_model' => 'gpt-5.6-luna',
		)
	);

	oras_ai_assert_same('0', get_option('oras_ai_member_ai_enabled'), 'Absent checkbox should save member AI disabled.');
	oras_ai_assert_false(ORAS_AI_Config::member_ai_enabled(), 'Unchecked submission should be disabled through Config.');
});

oras_ai_test('unchanged settings and blank API key create no audit event', function (): void {
	oras_ai_test_reset();
	$storedKey = 'sk-fixture-preserved-secret';
	update_option('oras_ai_openai_api_key', $storedKey);
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
			'oras_ai_api_key' => '',
		)
	);

	oras_ai_assert_same(array(), ORAS_AI_Audit_Log::recent_events(), 'No-op settings submission should not be audited.');
	oras_ai_assert_true($storedKey === get_option('oras_ai_openai_api_key'), 'Blank submission should preserve the stored API key.');
});

oras_ai_test('settings audit records one OpenAI model change with safe old and new values', function (): void {
	oras_ai_test_reset();
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-terra',
		)
	);
	$events = ORAS_AI_Audit_Log::recent_events();

	oras_ai_assert_same(1, count($events), 'One model change should create one audit event.');
	oras_ai_assert_same('config.openai_model', $events[0]['config_item'], 'Model audit identifier changed.');
	oras_ai_assert_same('changed', $events[0]['action'], 'Model audit action changed.');
	oras_ai_assert_same('gpt-5.6-luna', $events[0]['old_state'], 'Model audit old value changed.');
	oras_ai_assert_same('gpt-5.6-terra', $events[0]['new_state'], 'Model audit new value changed.');
});

oras_ai_test('settings audit records member AI enabled to disabled', function (): void {
	oras_ai_test_reset();
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_model' => 'gpt-5.6-luna',
		)
	);
	$event = ORAS_AI_Audit_Log::recent_events()[0];

	oras_ai_assert_same('config.member_ai_enabled', $event['config_item'], 'Member AI audit identifier changed.');
	oras_ai_assert_same('disabled', $event['action'], 'Member AI disabled action changed.');
	oras_ai_assert_same('enabled', $event['old_state'], 'Member AI old state changed.');
	oras_ai_assert_same('disabled', $event['new_state'], 'Member AI new state changed.');
});

oras_ai_test('settings audit records member AI disabled to enabled', function (): void {
	oras_ai_test_reset();
	update_option('oras_ai_member_ai_enabled', '0');
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
		)
	);
	$event = ORAS_AI_Audit_Log::recent_events()[0];

	oras_ai_assert_same('enabled', $event['action'], 'Member AI enabled action changed.');
	oras_ai_assert_same('disabled', $event['old_state'], 'Member AI old state changed.');
	oras_ai_assert_same('enabled', $event['new_state'], 'Member AI new state changed.');
});

oras_ai_test('settings audit records first stored API key as set without key material', function (): void {
	oras_ai_test_reset();
	$submittedKey = 'sk-fixture-first-secret';
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
			'oras_ai_api_key' => $submittedKey,
		)
	);
	$events = ORAS_AI_Audit_Log::recent_events();
	$serialized = wp_json_encode($events);
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$settingsHtml = (string) ob_get_clean();

	oras_ai_assert_same(1, count($events), 'First stored key should create one audit event.');
	oras_ai_assert_same('set', $events[0]['action'], 'First stored key should audit set.');
	oras_ai_assert_true(false === strpos($serialized, $submittedKey), 'Audit storage exposed submitted API-key material.');
	oras_ai_assert_true(false === strpos($settingsHtml, $submittedKey), 'Settings history exposed submitted API-key material.');
});

oras_ai_test('submitting the existing stored API key creates no audit event', function (): void {
	oras_ai_test_reset();
	$storedKey = 'sk-fixture-unchanged-secret';
	update_option('oras_ai_openai_api_key', $storedKey);
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
			'oras_ai_api_key' => $storedKey,
		)
	);

	oras_ai_assert_same(array(), ORAS_AI_Audit_Log::recent_events(), 'Unchanged stored key should not be audited.');
});

oras_ai_test('settings audit records stored API key replacement without either key', function (): void {
	oras_ai_test_reset();
	$oldKey = 'sk-fixture-old-secret';
	$newKey = 'sk-fixture-new-secret';
	update_option('oras_ai_openai_api_key', $oldKey);
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
			'oras_ai_api_key' => $newKey,
		)
	);
	$events = ORAS_AI_Audit_Log::recent_events();
	$serialized = wp_json_encode($events);

	oras_ai_assert_same(1, count($events), 'Replacing a stored key should create one audit event.');
	oras_ai_assert_same('replaced', $events[0]['action'], 'Changed stored key should audit replaced.');
	oras_ai_assert_true(false === strpos($serialized, $oldKey), 'Audit storage exposed previous API-key material.');
	oras_ai_assert_true(false === strpos($serialized, $newKey), 'Audit storage exposed replacement API-key material.');
});

oras_ai_test('settings audit records explicit stored API key removal', function (): void {
	oras_ai_test_reset();
	update_option('oras_ai_openai_api_key', 'sk-fixture-remove-secret');
	oras_ai_test_submit_settings(
		array(
			'oras_ai_settings_nonce' => 'valid',
			'oras_ai_member_ai_enabled' => '1',
			'oras_ai_model' => 'gpt-5.6-luna',
			'oras_ai_remove_key' => '1',
		)
	);
	$events = ORAS_AI_Audit_Log::recent_events();

	oras_ai_assert_same(1, count($events), 'Removing a stored key should create one audit event.');
	oras_ai_assert_same('removed', $events[0]['action'], 'Removed stored key should audit removed.');
	oras_ai_assert_false(ORAS_AI_Config::has_openai_api_key(), 'Explicit removal should preserve existing removal behavior.');
});

oras_ai_test('recent audit history is capability protected and handles a missing user safely', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 77;
	$GLOBALS['oras_ai_test_users'] = array();
	ORAS_AI_Audit_Log::log_openai_model_changed('gpt-5.6-luna', 'gpt-5.6-sol');
	ORAS_AI_Audit_Log::log_openai_api_key_changed('set');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$unauthorizedHtml = (string) ob_get_clean();
	oras_ai_assert_same('', $unauthorizedHtml, 'Audit history must not render without manage_options.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ob_start();
	(new ORAS_AI_Sources())->render_settings_page();
	$authorizedHtml = (string) ob_get_clean();
	oras_ai_assert_contains('Recent Configuration Changes', $authorizedHtml, 'Protected settings should show recent audit history.');
	oras_ai_assert_contains('User #77', $authorizedHtml, 'Deleted user should fall back to user ID.');
	oras_ai_assert_contains('OpenAI model', $authorizedHtml, 'Model audit label should be readable.');
	oras_ai_assert_contains('Stored OpenAI API key', $authorizedHtml, 'API-key audit label should be readable.');
});
