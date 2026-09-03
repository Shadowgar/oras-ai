<?php
declare(strict_types=1);

function oras_ai_test_cost_admin_post(array $overrides = array()): array {
	return array_merge(
		array(
			'oras_ai_cost_nonce' => 'valid',
			'daily_quota' => '25',
			'monthly_quota' => '150',
			'burst_per_minute' => '5',
			'max_input_characters' => '4000',
			'max_output_tokens' => '800',
			'execution_timeout_seconds' => '30',
			'warning_usd' => '10.00',
			'hard_stop_usd' => '20.00',
			'pricing' => array(
				'gpt-5.6-luna' => array('input_usd_per_million_tokens' => '1.25', 'output_usd_per_million_tokens' => '3.75'),
				'gpt-5.6-terra' => array('input_usd_per_million_tokens' => '', 'output_usd_per_million_tokens' => ''),
				'gpt-5.6-sol' => array('input_usd_per_million_tokens' => '', 'output_usd_per_million_tokens' => ''),
			),
		),
		$overrides
	);
}

function oras_ai_test_submit_cost_settings(array $post): ORAS_AI_Test_Redirect_Exception {
	$_POST = $post;
	try {
		(new ORAS_AI_Cost_Admin())->save_settings();
	} catch (ORAS_AI_Test_Redirect_Exception $redirect) {
		return $redirect;
	}

	throw new RuntimeException('Expected cost settings redirect.');
}

oras_ai_test('cost admin view is manage-options protected and exposes aggregate state without content', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start();
	(new ORAS_AI_Cost_Admin())->render_page();
	$unauthorized = (string) ob_get_clean();
	oras_ai_assert_same('', $unauthorized, 'Unauthorized user must not see usage or cost settings.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ob_start();
	(new ORAS_AI_Cost_Admin())->render_page();
	$authorized = (string) ob_get_clean();
	oras_ai_assert_contains('Usage &amp; Cost Controls', $authorized, 'Protected cost page heading missing.');
	oras_ai_assert_contains('Current month accounted spend', $authorized, 'Monthly aggregate missing.');
	oras_ai_assert_contains('Allowed executions', $authorized, 'Monthly allowed usage aggregate missing.');
	oras_ai_assert_contains('Provider input tokens', $authorized, 'Monthly input-token aggregate missing.');
	oras_ai_assert_contains('Provider output tokens', $authorized, 'Monthly output-token aggregate missing.');
	oras_ai_assert_contains('25', $authorized, 'Daily quota configuration missing.');
	oras_ai_assert_contains('150', $authorized, 'Monthly quota configuration missing.');
	oras_ai_assert_contains('Model pricing', $authorized, 'Local model pricing table missing.');
	oras_ai_assert_not_contains('question', strtolower($authorized), 'Admin cost page must not expose member question content.');
});

oras_ai_test('unchanged valid cost settings remain a successful no-op without audit noise', function (): void {
	oras_ai_test_reset();
	oras_ai_test_submit_cost_settings(oras_ai_test_cost_admin_post());
	update_option(ORAS_AI_Audit_Log::OPTION_EVENTS, array(), false);

	$redirect = oras_ai_test_submit_cost_settings(oras_ai_test_cost_admin_post());

	oras_ai_assert_contains('settings-updated=1', $redirect->location, 'No-op settings save should still succeed.');
	oras_ai_assert_same(array(), ORAS_AI_Audit_Log::recent_events(), 'No-op cost save should not add audit noise.');
});

oras_ai_test('cost settings require manage-options and action-specific nonce', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$_POST = oras_ai_test_cost_admin_post();
	try {
		(new ORAS_AI_Cost_Admin())->save_settings();
		throw new RuntimeException('Expected unauthorized cost save to fail.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('permission', strtolower($exception->getMessage()), 'Capability denial message changed.');
	}
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized save must stop before nonce handling.');

	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = oras_ai_test_cost_admin_post();
	try {
		(new ORAS_AI_Cost_Admin())->save_settings();
		throw new RuntimeException('Expected bad nonce to fail.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same(
			array(array(ORAS_AI_Cost_Admin::NONCE_ACTION, 'oras_ai_cost_nonce')),
			$GLOBALS['oras_ai_test_admin_nonce_checks'],
			'Cost save nonce scope changed.'
		);
	}
});

oras_ai_test('valid cost settings preserve separate model prices and are non-autoloaded', function (): void {
	oras_ai_test_reset();
	$redirect = oras_ai_test_submit_cost_settings(oras_ai_test_cost_admin_post());
	$config = ORAS_AI_Cost_Config::get();

	oras_ai_assert_same(1250000, $config['pricing']['gpt-5.6-luna']['input_microdollars_per_million_tokens'], 'Input pricing conversion changed.');
	oras_ai_assert_same(3750000, $config['pricing']['gpt-5.6-luna']['output_microdollars_per_million_tokens'], 'Output pricing conversion changed.');
	oras_ai_assert_same('per_million_tokens', $config['pricing']['gpt-5.6-luna']['unit'], 'Pricing unit missing.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Cost_Config::OPTION], 'Cost configuration must not autoload.');
	oras_ai_assert_same(
		'https://example.test/wp-admin/admin.php?page=oras-ai-cost&settings-updated=1',
		$redirect->location,
		'Cost settings redirect changed.'
	);
});

oras_ai_test('invalid cost settings fail closed without partial configuration changes', function (): void {
	oras_ai_test_reset();
	$original = ORAS_AI_Cost_Config::get();
	$invalidCases = array(
		array('daily_quota' => '0'),
		array('monthly_quota' => 'not-a-number'),
		array('burst_per_minute' => '100000'),
		array('warning_usd' => '20.00', 'hard_stop_usd' => '10.00'),
		array('pricing' => array('gpt-5.6-luna' => array('input_usd_per_million_tokens' => '-1', 'output_usd_per_million_tokens' => '2'))),
		array('pricing' => array('gpt-5.6-luna' => array('input_usd_per_million_tokens' => '1', 'output_usd_per_million_tokens' => ''))),
		array('pricing' => array('unapproved-model' => array('input_usd_per_million_tokens' => '1', 'output_usd_per_million_tokens' => '2'))),
	);

	foreach ($invalidCases as $override) {
		$_POST = oras_ai_test_cost_admin_post($override);
		try {
			(new ORAS_AI_Cost_Admin())->save_settings();
			throw new RuntimeException('Expected invalid cost configuration to fail.');
		} catch (ORAS_AI_Test_Die_Exception $exception) {
			oras_ai_assert_contains('invalid', strtolower($exception->getMessage()), 'Invalid settings should fail generically.');
		}
		oras_ai_assert_same($original, ORAS_AI_Cost_Config::get(), 'Invalid settings partially changed configuration.');
	}
});

oras_ai_test('quota budget and pricing changes create safe semantic audit events', function (): void {
	oras_ai_test_reset();
	$secret = 'sk-never-audit-this';
	$post = oras_ai_test_cost_admin_post(array('daily_quota' => '30', 'unrelated_secret' => $secret));
	oras_ai_test_submit_cost_settings($post);
	$events = ORAS_AI_Audit_Log::recent_events();
	$serialized = wp_json_encode($events);

	oras_ai_assert_true(count($events) >= 2, 'Quota and pricing changes should both be audited.');
	oras_ai_assert_contains('config.cost.', $serialized, 'Cost configuration audit identifier missing.');
	oras_ai_assert_contains('config.model_pricing.gpt-5.6-luna', $serialized, 'Pricing change was not audited by model.');
	oras_ai_assert_not_contains($secret, $serialized, 'Audit stored unrelated secret material.');
	oras_ai_assert_not_contains('question', strtolower($serialized), 'Audit must not contain member question text.');
});
