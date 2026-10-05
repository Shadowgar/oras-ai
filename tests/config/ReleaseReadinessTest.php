<?php
declare(strict_types=1);

oras_ai_test('M9 readiness reports missing and invalid local prerequisites without claiming live verification', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_true(method_exists('ORAS_AI_Config', 'local_readiness'), 'Local configuration readiness is missing.');
	$status = ORAS_AI_Config::local_readiness();
	oras_ai_assert_same('missing', $status['openai_key'], 'Absent key reported configured.');
	oras_ai_assert_same('configured', $status['openai_model'], 'Existing default model invalid.');
	oras_ai_assert_same('missing', $status['model_pricing'], 'Absent prices reported configured.');
	oras_ai_assert_same('missing', $status['astronomy_credentials'], 'Absent pair reported configured.');
	oras_ai_assert_same('missing', $status['nws_contact'], 'Absent NWS identity reported configured.');
	oras_ai_assert_same('missing', $status['support_general'], 'Absent support route reported configured.');
	oras_ai_assert_same('not_live_verified', $status['verification'], 'Local status claimed live qualification.');
	update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, 'private-invalid-model');
	update_option(ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_ID, 'private-incomplete-app');
	update_option(ORAS_AI_Config::OPTION_NWS_CONTACT, 'invalid private contact');
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, array('primary_mailbox_id' => 'invalid', 'destination_name' => 'private routing'));
	$status = ORAS_AI_Config::local_readiness();
	oras_ai_assert_same('invalid', $status['openai_model'], 'Raw invalid model was normalized into readiness.');
	oras_ai_assert_same('invalid', $status['astronomy_credentials'], 'Incomplete pair hidden.');
	oras_ai_assert_same('invalid', $status['nws_contact'], 'Invalid contact hidden.');
	oras_ai_assert_same('invalid', $status['support_general'], 'Invalid mailbox hidden.');
	oras_ai_assert_not_contains('private', wp_json_encode($status), 'Private configuration leaked.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Readiness fetched a provider.');
});

oras_ai_test('M9 readiness shows configured prerequisites and General fallback using existing local validation', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_true(method_exists('ORAS_AI_Config', 'local_readiness'), 'Local configuration readiness is missing.');
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'private-openai-secret');
	update_option(ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_ID, 'private-astro-id');
	update_option(ORAS_AI_Config::OPTION_ASTRONOMYAPI_APPLICATION_SECRET, 'private-astro-secret');
	update_option(ORAS_AI_Config::OPTION_NWS_CONTACT, 'private-contact@example.test');
	update_option(ORAS_AI_Cost_Config::OPTION, oras_ai_test_execution_config());
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, array('primary_mailbox_id' => 42, 'destination_name' => 'private destination', 'general_tag_ids' => array(56), 'topic_routes' => array()));
	$status = ORAS_AI_Config::local_readiness();
	foreach (array('openai_key', 'openai_model', 'model_pricing', 'astronomy_credentials', 'nws_contact', 'observing_site', 'support_general', 'contact_fallback') as $key) { oras_ai_assert_same('configured', $status[$key], 'Valid prerequisite hidden: ' . $key); }
	oras_ai_assert_same('general_fallback', $status['support_topics'], 'Empty topics lost General fallback.');
	$config = get_option(ORAS_AI_Support_Routing::OPTION_ROUTING); $config['topic_routes'] = array('membership' => array('mailbox_id' => 'invalid'));
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, $config);
	$status = ORAS_AI_Config::local_readiness();
	oras_ai_assert_same('configured', $status['support_general'], 'Invalid topic hid usable General.');
	oras_ai_assert_same('invalid_general_fallback', $status['support_topics'], 'Invalid topic status missing.');
	$cost = oras_ai_test_execution_config(); $cost['pricing']['gpt-5.6-luna']['input_microdollars_per_million_tokens'] = 0;
	update_option(ORAS_AI_Cost_Config::OPTION, $cost);
	oras_ai_assert_same('invalid', ORAS_AI_Config::local_readiness()['model_pricing'], 'Readiness reinterpreted existing zero-rate rules.');
	update_option(ORAS_AI_Usage_Ledger::FAULT_OPTION, array('reservation_id' => 'private-reference')); update_option(ORAS_AI_Config::OPTION_MEMBER_AI_ENABLED, '0');
	$status = ORAS_AI_Config::local_readiness();
	oras_ai_assert_same('incomplete', $status['accounting'], 'Accounting failure hidden.');
	oras_ai_assert_same('off', $status['member_ai'], 'Kill switch state wrong.');
	oras_ai_assert_not_contains('private', wp_json_encode($status), 'Readiness leaked secrets/routing.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Local checks fetched providers.');
});

oras_ai_test('M9 readiness settings and maintenance visibility stay protected and safe', function (): void {
	oras_ai_test_reset(); update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'private-secret');
	ob_start(); (new ORAS_AI_Sources())->render_settings_page(); $html = (string) ob_get_clean();
	oras_ai_assert_contains('Local configuration checks', $html, 'Settings omit readiness.');
	oras_ai_assert_contains('not live-verified', $html, 'Configuration appears live-verified.');
	oras_ai_assert_not_contains('private-secret', $html, 'Secret rendered.');
	ob_start(); (new ORAS_AI_Cost_Admin())->render_page(); $html = (string) ob_get_clean();
	oras_ai_assert_contains('Usage metadata maintenance', $html, 'Usage page omits maintenance.');
	oras_ai_assert_contains('external scheduler', $html, 'Scheduled event confused with host scheduler proof.');
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start(); (new ORAS_AI_Sources())->render_settings_page(); (new ORAS_AI_Cost_Admin())->render_page();
	oras_ai_assert_same('', (string) ob_get_clean(), 'Readiness leaked to unauthorized viewer.');
});

oras_ai_test('M9 privacy disclosure distinguishes chat escalation usage and independent tickets', function (): void {
	oras_ai_test_reset(); $html = oras_ai_test_chat_ui()->render_sitewide();
	foreach (array('external AI processing', '30 days', 'local support escalation records', 'twelve calendar months', 'without conversation text', 'separate support retention policy') as $required) { oras_ai_assert_contains($required, $html, 'Member retention disclosure incomplete.'); }
});

oras_ai_test('M9 seeded OFF keeps console disabled while scanner still obeys shared stop', function (): void {
	oras_ai_test_reset(); update_option(ORAS_AI_Config::OPTION_MEMBER_AI_ENABLED, '0'); ORAS_AI_Assistant::activate();
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static fn() => true));
	$denied = $gateway->authorize_member(array('nonce' => 'valid-member-request-nonce'));
	oras_ai_assert_wp_error($denied, 'oras_ai_request_denied', 'Administrator console bypassed seeded OFF.');
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'private-scanner-key'); update_option(ORAS_AI_Cost_Config::OPTION, oras_ai_test_execution_config());
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, oras_ai_test_paid_body('scanner_classification'));
	oras_ai_test_paid_family('scanner_classification');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Member switch unexpectedly disables scanner.');
	oras_ai_test_seed_spend(new ORAS_AI_Usage_Ledger(), 20000000);
	oras_ai_test_paid_family('scanner_classification');
	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Scanner bypassed shared stop with member switch OFF.');
});
