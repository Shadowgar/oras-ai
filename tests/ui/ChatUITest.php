<?php
declare(strict_types=1);

function oras_ai_test_chat_ui(bool $eligible = true) {
	$GLOBALS['oras_ai_test_is_admin'] = false;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$gateway = new ORAS_AI_Request_Gateway(
		new ORAS_AI_PMPro_Membership_Authorizer(static function () use ($eligible) {
			return $eligible;
		})
	);
	return new ORAS_AI_Chat_UI($gateway);
}

oras_ai_test('eligible frontend registers the shared chat shortcode and site-wide hooks', function (): void {
	oras_ai_test_reset();
	$ui = oras_ai_test_chat_ui();

	oras_ai_assert_true(isset($GLOBALS['oras_ai_test_shortcodes']['oras_ai_chat']), 'Member chat shortcode was not registered.');
	oras_ai_assert_true(oras_ai_hook_registered('wp_footer'), 'Site-wide launcher hook missing.');
	oras_ai_assert_true(oras_ai_hook_registered('wp_enqueue_scripts'), 'Frontend asset hook missing.');
	$ui->enqueue_assets();
	oras_ai_assert_same('https://example.test/wp-content/plugins/oras-ai/assets/chat.js', $GLOBALS['oras_ai_test_enqueued_scripts']['oras-ai-chat']['src'], 'Chat script URL changed.');
	oras_ai_assert_same('https://example.test/wp-content/plugins/oras-ai/assets/chat.css', $GLOBALS['oras_ai_test_enqueued_styles']['oras-ai-chat']['src'], 'Chat stylesheet URL changed.');
	$config = $GLOBALS['oras_ai_test_localized_scripts']['oras-ai-chat']['data'];
	oras_ai_assert_same('https://example.test/wp-admin/admin-ajax.php', $config['ajaxUrl'], 'Chat AJAX URL missing.');
	oras_ai_assert_same('oras_ai_conversation', $config['action'], 'Chat AJAX action changed.');
	oras_ai_assert_same('nonce-for-oras_ai_member_request', $config['nonce'], 'Chat nonce missing.');
	oras_ai_assert_false(array_key_exists('userId', $config), 'Chat config exposed user identity.');
	oras_ai_assert_false(array_key_exists('apiKey', $config), 'Chat config exposed API credentials.');
	oras_ai_assert_false(array_key_exists('model', $config), 'Chat config exposed model configuration.');
});

oras_ai_test('site-wide Support launcher and overlay are plugin-owned and use shared chat markup', function (): void {
	oras_ai_test_reset();
	$ui = oras_ai_test_chat_ui();
	$html = $ui->render_sitewide();
	ob_start();
	$ui->output_sitewide();
	$hook_html = (string) ob_get_clean();
	oras_ai_assert_contains('class="oras-ai-chat-launcher"', $hook_html, 'wp_footer callback did not output the launcher.');
	oras_ai_assert_contains('data-oras-ai-chat-mode="panel"', $hook_html, 'wp_footer callback did not output the panel.');

	oras_ai_assert_contains('Support', $html, 'Eligible site-wide launcher label missing.');
	oras_ai_assert_contains('data-oras-ai-chat', $html, 'Shared chat component marker missing.');
	oras_ai_assert_contains('role="dialog"', $html, 'Floating chat must use dialog semantics.');
	oras_ai_assert_contains('aria-modal="true"', $html, 'Floating chat modal semantics missing.');
	oras_ai_assert_contains('data-oras-ai-chat-mode="panel"', $html, 'Floating chat mode missing.');
	oras_ai_assert_contains('data-oras-ai-chat-new', $html, 'New Chat control missing.');
	oras_ai_assert_contains('data-oras-ai-chat-close', $html, 'Close control missing.');
	oras_ai_assert_contains('external AI processing', $html, 'External AI disclosure missing.');
	oras_ai_assert_contains('30 days', $html, '30-day retention disclosure missing.');
	oras_ai_assert_not_contains('functions.php', $html, 'Launcher should not require theme code.');
});

oras_ai_test('anonymous and ineligible frontend users receive no production launcher', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	$anonymous = oras_ai_test_chat_ui(true)->render_sitewide();
	oras_ai_assert_same('', $anonymous, 'Anonymous user received the member launcher.');

	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$ineligible = oras_ai_test_chat_ui(false)->render_sitewide();
	oras_ai_assert_same('', $ineligible, 'Ineligible user received the member launcher.');
});

oras_ai_test('administrator follows the existing eligible frontend boundary and kill switch hides UI', function (): void {
	oras_ai_test_reset();
	$admin_ui = oras_ai_test_chat_ui(false);
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$admin = $admin_ui->render_sitewide();
	oras_ai_assert_contains('Support', $admin, 'Administrator did not receive the existing eligible UI allowance.');

	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ORAS_AI_Config::set_member_ai_enabled(false);
	$disabled = oras_ai_test_chat_ui(false)->render_sitewide();
	oras_ai_assert_same('', $disabled, 'Kill switch did not hide the production launcher.');
});

oras_ai_test('eligible shortcode renders the same page chat component while unauthorized shortcode fails safely', function (): void {
	oras_ai_test_reset();
	$ui = oras_ai_test_chat_ui();
	$page = call_user_func($GLOBALS['oras_ai_test_shortcodes']['oras_ai_chat']);
	oras_ai_assert_contains('data-oras-ai-chat-mode="page"', $page, 'Shortcode did not render page chat mode.');
	oras_ai_assert_contains('ORAS AI Assistant', $page, 'Shortcode chat identity missing.');
	oras_ai_assert_contains('AI-powered ORAS/astronomy assistant', $page, 'AI identity disclosure missing.');

	oras_ai_test_reset();
	$blocked = oras_ai_test_chat_ui(false);
	$blocked_html = call_user_func($GLOBALS['oras_ai_test_shortcodes']['oras_ai_chat']);
	oras_ai_assert_contains('available to eligible members', $blocked_html, 'Unauthorized shortcode did not fail safely.');
	oras_ai_assert_not_contains('data-oras-ai-chat', $blocked_html, 'Unauthorized shortcode rendered the chat component.');
	unset($ui, $blocked);
});

oras_ai_test('shared chat markup exposes accessible labels status log and responsive panel structure', function (): void {
	oras_ai_test_reset();
	$html = oras_ai_test_chat_ui()->render_component('page');

	foreach (array('aria-live="polite"', 'role="log"', 'data-oras-ai-chat-status', 'data-oras-ai-chat-form', 'data-oras-ai-chat-input', 'data-oras-ai-chat-send', 'for="oras-ai-chat-') as $marker) {
		oras_ai_assert_contains($marker, $html, 'Accessible chat marker missing: ' . $marker);
	}
	oras_ai_assert_not_contains('<script', $html, 'Chat renderer emitted executable script markup.');
});
