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
