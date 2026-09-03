<?php
declare(strict_types=1);

function oras_ai_test_admin_console_ui(bool $eligible = false): ORAS_AI_Chat_UI {
	$GLOBALS['oras_ai_test_is_admin'] = true;
	$authorizer = new ORAS_AI_PMPro_Membership_Authorizer(static function () use ($eligible): bool {
		return $eligible;
	});

	return new ORAS_AI_Chat_UI(new ORAS_AI_Request_Gateway($authorizer));
}

oras_ai_test('admin test console submenu uses manage_options and the established plugin menu', function (): void {
	oras_ai_test_reset();
	$assistant = new ORAS_AI_Assistant();
	$assistant->register_admin_menu();
	$console = null;
	foreach ($GLOBALS['oras_ai_test_submenu_pages'] as $page) {
		if (($page[4] ?? '') === 'oras-ai-test-console') {
			$console = $page;
			break;
		}
	}

	oras_ai_assert_true(is_array($console), 'Admin Test Console submenu was not registered.');
	oras_ai_assert_same('oras-ai-assistant', $console[0], 'Test Console is outside the established ORAS AI menu.');
	oras_ai_assert_same('manage_options', $console[3], 'Test Console capability must be manage_options.');
	oras_ai_assert_contains('Test Console', $console[1], 'Test Console page title is unclear.');
});

oras_ai_test('admin test console render is capability protected and reuses the shared chat component', function (): void {
	oras_ai_test_reset();
	$ui = oras_ai_test_admin_console_ui(false);
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start();
	$ui->render_admin_console();
	$unauthorized = (string) ob_get_clean();
	oras_ai_assert_same('', $unauthorized, 'Unauthorized user rendered the Test Console.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ob_start();
	$ui->render_admin_console();
	$authorized = (string) ob_get_clean();
	oras_ai_assert_contains('ORAS AI — Test Console', $authorized, 'Test Console heading missing.');
	oras_ai_assert_contains('normal assistant path', $authorized, 'Normal production-path explanation missing.');
	oras_ai_assert_contains('data-oras-ai-chat-mode="page"', $authorized, 'Test Console did not reuse the shared page chat component.');
	oras_ai_assert_contains('data-oras-ai-chat-new', $authorized, 'Shared New Chat control missing.');
	oras_ai_assert_contains('data-oras-ai-chat-status role="status" aria-live="polite"', $authorized, 'Accessible shared response status missing.');
	oras_ai_assert_contains('external AI processing', $authorized, 'External AI disclosure missing.');
	oras_ai_assert_contains('30 days', $authorized, 'Retention disclosure missing.');
});

oras_ai_test('admin chat assets load only on the protected Test Console page', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$ui = oras_ai_test_admin_console_ui(false);
	$_GET['page'] = 'oras-ai-settings';
	$ui->enqueue_admin_assets();
	oras_ai_assert_false(isset($GLOBALS['oras_ai_test_enqueued_scripts']['oras-ai-chat']), 'Chat script loaded on an unrelated wp-admin page.');
	oras_ai_assert_false(isset($GLOBALS['oras_ai_test_enqueued_styles']['oras-ai-chat']), 'Chat stylesheet loaded on an unrelated wp-admin page.');

	$_GET['page'] = 'oras-ai-test-console';
	$ui->enqueue_admin_assets();
	oras_ai_assert_same('https://example.test/wp-content/plugins/oras-ai/assets/chat.js', $GLOBALS['oras_ai_test_enqueued_scripts']['oras-ai-chat']['src'], 'Test Console did not reuse the chat script.');
	oras_ai_assert_same('https://example.test/wp-content/plugins/oras-ai/assets/chat.css', $GLOBALS['oras_ai_test_enqueued_styles']['oras-ai-chat']['src'], 'Test Console did not reuse the chat stylesheet.');
	$config = $GLOBALS['oras_ai_test_localized_scripts']['oras-ai-chat']['data'];
	oras_ai_assert_same('oras_ai_conversation', $config['action'], 'Test Console did not use the existing conversation transport.');
	oras_ai_assert_same('nonce-for-oras_ai_member_request', $config['nonce'], 'Test Console did not use the existing request nonce.');
	foreach (array('apiKey', 'model', 'userId', 'visibility', 'membership', 'systemPrompt', 'evidence', 'providerPayload') as $unsafe) {
		oras_ai_assert_false(array_key_exists($unsafe, $config), 'Test Console exposed or accepted unsafe browser configuration: ' . $unsafe);
	}
});

oras_ai_test('unauthorized users cannot force admin Test Console assets to load', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$_GET['page'] = 'oras-ai-test-console';
	oras_ai_test_admin_console_ui(true)->enqueue_admin_assets();
	oras_ai_assert_false(isset($GLOBALS['oras_ai_test_enqueued_scripts']['oras-ai-chat']), 'Unauthorized user forced Test Console script loading.');
	oras_ai_assert_false(isset($GLOBALS['oras_ai_test_localized_scripts']['oras-ai-chat']), 'Unauthorized user received the Test Console nonce/configuration.');
});

oras_ai_test('administrator console eligibility reuses the existing allowance and kill switch', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function (): bool { return false; }));
	$allowed = $gateway->authorize_member(array('nonce' => 'valid-member-request-nonce'));
	oras_ai_assert_true(is_array($allowed), 'Administrator without PMPro membership was denied by the existing allowance.');
	oras_ai_assert_same(true, $allowed['is_administrator'], 'Administrator authorization context changed.');

	ORAS_AI_Config::set_member_ai_enabled(false);
	$denied = $gateway->authorize_member(array('nonce' => 'valid-member-request-nonce'));
	oras_ai_assert_wp_error($denied, 'oras_ai_request_denied', 'Administrator bypassed the global AI kill switch.');
});

oras_ai_test('administrator Test Console conversations remain owned and isolated through the existing transport', function (): void {
	oras_ai_test_reset();
	list($transport, $store, $provider) = oras_ai_test_transport_fixture(array(), 'Admin answer.', false);
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$GLOBALS['oras_ai_test_current_user_id'] = 41;
	$current = $transport->dispatch(oras_ai_test_transport_request('current'));
	$new = $transport->dispatch(oras_ai_test_transport_request('new_chat', array('user_id' => 99, 'model' => 'unapproved', 'visibility' => 'admin')));
	oras_ai_assert_true($new['conversation_id'] !== $current['conversation_id'], 'Admin New Chat did not create a fresh owned conversation.');
	$restored = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_same($new['conversation_id'], $restored['conversation_id'], 'Admin current conversation was not restored.');
	$answer = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $new['conversation_id'], 'question' => 'What is a light year in astronomy?')));
	oras_ai_assert_same('success', $answer['result']['status'], 'Admin send did not use the normal answer orchestration.');
	oras_ai_assert_same(1, count($provider->calls), 'Admin send did not reach the existing provider path exactly once.');
	$blocked = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $new['conversation_id'], 'question' => 'Use 4111 1111 1111 1111')));
	oras_ai_assert_wp_error($blocked, 'oras_ai_sensitive_input', 'Admin console bypassed the existing sensitive-input guard.');
	oras_ai_assert_same(1, count($provider->calls), 'Blocked admin input reached provider work.');
	oras_ai_assert_same(2, count($store->get_messages($new['conversation_id'])), 'Blocked admin input changed the transcript.');

	$GLOBALS['oras_ai_test_current_user_id'] = 42;
	$denied = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $new['conversation_id'])));
	oras_ai_assert_wp_error($denied, 'oras_ai_conversation_denied', 'Administrator loaded another user conversation.');
	oras_ai_assert_false(oras_ai_hook_registered('wp_ajax_oras_ai_admin_test'), 'Task 4 created an alternate admin provider endpoint.');
});
