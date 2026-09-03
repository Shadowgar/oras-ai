<?php
declare(strict_types=1);

function oras_ai_test_transport_fixture(array $providerSources = array(), string $answer = 'Transport answer.', bool $eligible = true) {
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet($providerSources),
		oras_ai_test_provider_success($answer)
	);
	$authorizer = new ORAS_AI_PMPro_Membership_Authorizer(static function () use ($eligible) {
		return $eligible;
	});
	$store = new ORAS_AI_Conversations(new ORAS_AI_Sensitive_Input_Guard(), static function (): int {
		return strtotime('2026-09-03 12:00:00 UTC');
	});
	$gateway = new ORAS_AI_Request_Gateway($authorizer, $orchestrator);
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $store);

	return array($transport, $store, $provider);
}

function oras_ai_test_transport_request(string $operation, array $extra = array()): array {
	return array_merge(
		array(
			'operation' => $operation,
			'nonce' => 'valid-member-request-nonce',
		),
		$extra
	);
}

function oras_ai_test_transport_source(array $overrides = array()): array {
	return array_merge(
		array(
			'artifact_id' => 501,
			'source_id' => 301,
			'source_title' => 'ORAS Observatory Guide',
			'canonical_url' => 'https://oras.org/observatory-guide/',
		),
		$overrides
	);
}

oras_ai_test('conversation transport registers only an authenticated operation endpoint', function (): void {
	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();

	oras_ai_assert_true(oras_ai_hook_registered('wp_ajax_oras_ai_conversation'), 'Conversation transport AJAX hook missing.');
	oras_ai_assert_false(oras_ai_hook_registered('wp_ajax_nopriv_oras_ai_conversation'), 'Conversation transport must not register an anonymous hook.');
});

oras_ai_test('current operation creates or restores only the authenticated member latest conversation', function (): void {
	oras_ai_test_reset();
	list($transport, $store) = oras_ai_test_transport_fixture();
	$GLOBALS['oras_ai_test_current_user_id'] = 17;

	$created = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_true(is_array($created) && $created['conversation_id'] > 0, 'Current operation should safely create an initial conversation.');
	$first_id = $created['conversation_id'];
	$store->append_message($first_id, 'member', 'First question');
	$restored = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_same($first_id, $restored['conversation_id'], 'Current operation did not restore the latest owned conversation.');
	oras_ai_assert_same('First question', $restored['messages'][0]['content'], 'Restored transcript content changed.');

	$GLOBALS['oras_ai_test_current_user_id'] = 18;
	$other = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_true($other['conversation_id'] !== $first_id, 'User B restored User A conversation.');
});

oras_ai_test('new chat creates a current owned conversation and retains the prior conversation', function (): void {
	oras_ai_test_reset();
	list($transport, $store) = oras_ai_test_transport_fixture();
	$old = $transport->dispatch(oras_ai_test_transport_request('current'));
	$store->append_message($old['conversation_id'], 'member', 'Retained question');

	$new = $transport->dispatch(oras_ai_test_transport_request('new_chat', array('user_id' => 999, 'owner_id' => 999)));
	oras_ai_assert_true($new['conversation_id'] !== $old['conversation_id'], 'New Chat reused the old conversation.');
	oras_ai_assert_same(array(), $new['messages'], 'New Chat should begin with an empty transcript.');
	$old_loaded = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $old['conversation_id'])));
	oras_ai_assert_same('Retained question', $old_loaded['messages'][0]['content'], 'New Chat deleted a retained prior conversation.');
	$current = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_same($new['conversation_id'], $current['conversation_id'], 'New Chat did not become current.');
});

oras_ai_test('expired conversations are pruned before current restoration and malformed loads fail safely', function (): void {
	oras_ai_test_reset();
	list($transport, $store) = oras_ai_test_transport_fixture();
	$current = $transport->dispatch(oras_ai_test_transport_request('current'));
	$message = $store->append_message($current['conversation_id'], 'member', 'Expired question');
	$expired = strtotime('2026-09-03 12:00:00 UTC') - (30 * DAY_IN_SECONDS) - 1;
	update_post_meta($message, ORAS_AI_Conversations::META_CREATED_AT, $expired);
	update_post_meta($current['conversation_id'], ORAS_AI_Conversations::META_UPDATED_AT, $expired);

	$restored = $transport->dispatch(oras_ai_test_transport_request('current'));
	oras_ai_assert_true($restored['conversation_id'] !== $current['conversation_id'], 'Expired conversation was restored.');
	foreach (array('', 'abc', 0, -1, array('id' => 1)) as $id) {
		$result = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $id)));
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_conversation', 'Malformed conversation ID must fail safely.');
	}
});

oras_ai_test('owner load returns only ordered safe transcript fields and cross-user access is denied', function (): void {
	oras_ai_test_reset();
	list($transport, $store) = oras_ai_test_transport_fixture();
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$store->append_message($conversation['conversation_id'], 'member', 'Question');
	$store->append_message($conversation['conversation_id'], 'assistant', 'Answer');
	$loaded = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $conversation['conversation_id'])));

	oras_ai_assert_same(array('conversation_id', 'conversation', 'messages'), array_keys($loaded), 'Load response schema changed.');
	oras_ai_assert_same(array('member', 'assistant'), array_column($loaded['messages'], 'role'), 'Load message ordering changed.');
	foreach ($loaded['messages'] as $message) {
		oras_ai_assert_same(array('id', 'conversation_id', 'role', 'content', 'created_at_utc'), array_keys($message), 'Load exposed unsafe transcript fields.');
	}

	$GLOBALS['oras_ai_test_current_user_id'] = 18;
	$denied = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $conversation['conversation_id'])));
	oras_ai_assert_wp_error($denied, 'oras_ai_conversation_denied', 'User B loaded User A conversation.');
});

oras_ai_test('send operation stores member and assistant messages and returns trusted structured sources', function (): void {
	oras_ai_test_reset();
	$source = oras_ai_test_answer_evidence();
	list($transport, $store, $provider) = oras_ai_test_transport_fixture(array($source), 'Grounded transport answer.');
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$response = $transport->dispatch(
		oras_ai_test_transport_request(
			'send',
			array(
				'conversation_id' => $conversation['conversation_id'],
				'question' => 'How does observatory access work?',
			)
		)
	);

	oras_ai_assert_same($conversation['conversation_id'], $response['conversation_id'], 'Send changed conversation identity.');
	oras_ai_assert_same('member', $response['member_message']['role'], 'Member message response role changed.');
	oras_ai_assert_same('assistant', $response['assistant_message']['role'], 'Assistant message response role changed.');
	oras_ai_assert_same('success', $response['result']['status'], 'Send result status changed.');
	oras_ai_assert_same('Grounded transport answer.', $response['result']['answer'], 'Send answer changed.');
	oras_ai_assert_same(array('status', 'answer', 'sources', 'error_code'), array_keys($response['result']), 'Send exposed model, usage, or internal fields.');
	oras_ai_assert_same('ORAS Observatory Guide', $response['result']['sources'][0]['source_title'], 'Trusted source title missing.');
	oras_ai_assert_same('https://oras.org/observatory-guide/', $response['result']['sources'][0]['canonical_url'], 'Trusted canonical URL missing.');
	oras_ai_assert_same($response['result']['sources'], $response['assistant_message']['sources'], 'Assistant source restoration payload changed.');
	oras_ai_assert_same(1, count($provider->calls), 'Valid send did not reach the existing orchestrator once.');

	$loaded = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $conversation['conversation_id'])));
	oras_ai_assert_same(2, count($loaded['messages']), 'Send did not persist both transcript messages.');
	oras_ai_assert_same($response['result']['sources'], $loaded['messages'][1]['sources'], 'Restored assistant sources changed.');
	$stored_meta = get_post_meta($response['assistant_message']['id']);
	oras_ai_assert_not_contains('relevant_text', serialize($stored_meta), 'Evidence body was persisted.');
});

oras_ai_test('send stores safe refusal and failure answers without raw provider payloads', function (): void {
	oras_ai_test_reset();
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), static function () {
		return ORAS_AI_Provider_Answer::failure('provider_unavailable', false);
	});
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }), $orchestrator);
	$store = new ORAS_AI_Conversations();
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $store);
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$response = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $conversation['conversation_id'], 'question' => 'What is a light year in astronomy?')));

	oras_ai_assert_same('failure', $response['result']['status'], 'Safe provider failure status changed.');
	oras_ai_assert_same('ORAS AI could not complete the request.', $response['assistant_message']['content'], 'Raw provider failure escaped into transcript.');
	oras_ai_assert_not_contains('provider_unavailable', $response['assistant_message']['content'], 'Internal provider error leaked into transcript.');
	oras_ai_assert_same(1, count($provider->calls), 'Failure fixture provider call count changed.');
});

oras_ai_test('send persists a safe domain refusal without provider work', function (): void {
	oras_ai_test_reset();
	list($transport, $store, $provider) = oras_ai_test_transport_fixture();
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$response = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $conversation['conversation_id'], 'question' => 'Write code for my shopping list.')));

	oras_ai_assert_same('refusal', $response['result']['status'], 'Domain refusal status changed.');
	oras_ai_assert_same('ORAS AI supports ORAS and astronomy questions.', $response['assistant_message']['content'], 'Safe refusal was not persisted as the assistant message.');
	oras_ai_assert_same(array(), $response['assistant_message']['sources'], 'Refusal must not carry invented sources.');
	oras_ai_assert_same(0, count($provider->calls), 'Off-topic refusal reached the provider.');
	oras_ai_assert_same(2, count($store->get_messages($conversation['conversation_id'])), 'Refusal transcript was not retained.');
});

oras_ai_test('stored conversation history is not included in later model context', function (): void {
	oras_ai_test_reset();
	list($transport, $store, $provider) = oras_ai_test_transport_fixture(array(), 'Answer');
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $conversation['conversation_id'], 'question' => 'What is a light year in astronomy?')));
	$transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $conversation['conversation_id'], 'question' => 'What is Mars in astronomy?')));
	$input = wp_json_encode($provider->calls[1]['context']->provider_input());

	oras_ai_assert_contains('What is Mars in astronomy?', $input, 'Current question did not reach the provider context.');
	oras_ai_assert_not_contains('What is a light year in astronomy?', $input, 'Stored prior transcript was sent to the provider.');
});

oras_ai_test('transport rejects card input before transcript storage and provider dispatch', function (): void {
	oras_ai_test_reset();
	list($transport, $store, $provider) = oras_ai_test_transport_fixture();
	$conversation = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$result = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $conversation['conversation_id'], 'question' => 'Use 4111 1111 1111 1111')));

	oras_ai_assert_wp_error($result, 'oras_ai_sensitive_input', 'Card input must be rejected by the existing gateway guard.');
	oras_ai_assert_same(array(), $store->get_messages($conversation['conversation_id']), 'Rejected card was persisted.');
	oras_ai_assert_same(0, count($provider->calls), 'Rejected card reached the provider.');
});

oras_ai_test('transport preserves gateway authorization boundaries and safe operation errors', function (): void {
	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();
	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	oras_ai_assert_wp_error($transport->dispatch(oras_ai_test_transport_request('current')), 'oras_ai_request_denied', 'Anonymous transport request was allowed.');

	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	oras_ai_assert_wp_error($transport->dispatch(oras_ai_test_transport_request('new_chat')), 'oras_ai_request_denied', 'Invalid nonce transport request was allowed.');

	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();
	oras_ai_assert_wp_error($transport->dispatch(oras_ai_test_transport_request('unknown')), 'oras_ai_invalid_operation', 'Unknown operation did not fail safely.');

	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture(array(), 'Answer', false);
	oras_ai_assert_wp_error($transport->dispatch(oras_ai_test_transport_request('current')), 'oras_ai_request_denied', 'Inactive member transport request was allowed.');

	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();
	ORAS_AI_Config::set_member_ai_enabled(false);
	oras_ai_assert_wp_error($transport->dispatch(oras_ai_test_transport_request('current')), 'oras_ai_request_denied', 'Kill-switch transport request was allowed.');
});

oras_ai_test('transport AJAX errors contain only safe machine-readable status data', function (): void {
	oras_ai_test_reset();
	list($transport) = oras_ai_test_transport_fixture();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = oras_ai_test_transport_request('current');

	try {
		$transport->handle_ajax_request();
		throw new RuntimeException('Expected intercepted transport JSON response.');
	} catch (ORAS_AI_Test_Json_Response $response) {
		oras_ai_assert_false($response->success, 'Denied transport request must return JSON error.');
		oras_ai_assert_same(403, $response->status, 'Denied transport status changed.');
		oras_ai_assert_same(array('code', 'message'), array_keys($response->data), 'Transport error exposed unsafe fields.');
	}
});
