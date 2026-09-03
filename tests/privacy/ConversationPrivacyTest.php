<?php
declare(strict_types=1);

function oras_ai_test_conversation_store(int &$now) {
	return new ORAS_AI_Conversations(
		new ORAS_AI_Sensitive_Input_Guard(),
		static function () use (&$now): int {
			return $now;
		}
	);
}

oras_ai_test('M4 conversation and message records are private WordPress-native entities', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$store->register_post_types();

	foreach (array(ORAS_AI_Conversations::CONVERSATION_POST_TYPE, ORAS_AI_Conversations::MESSAGE_POST_TYPE) as $post_type) {
		$args = $GLOBALS['oras_ai_test_registered_post_types'][$post_type] ?? array();
		oras_ai_assert_same(false, $args['public'] ?? null, $post_type . ' must not be public.');
		oras_ai_assert_same(false, $args['publicly_queryable'] ?? null, $post_type . ' must not be publicly queryable.');
		oras_ai_assert_same(false, $args['show_ui'] ?? null, $post_type . ' must not expose a WordPress admin UI.');
		oras_ai_assert_same(false, $args['show_in_rest'] ?? null, $post_type . ' must not expose REST records.');
	}

	oras_ai_assert_same(30, ORAS_AI_Conversations::RETENTION_DAYS, 'Displayable conversation retention changed.');
	oras_ai_assert_true(oras_ai_hook_registered(ORAS_AI_Conversations::CLEANUP_HOOK), 'Daily cleanup hook missing.');
});

oras_ai_test('conversation ownership is server-derived and enforced for create read and append', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);

	$GLOBALS['oras_ai_test_current_user_id'] = 17;
	$conversation_id = $store->create_conversation(array('user_id' => 99, 'owner_id' => 99, 'email' => 'other@example.test'));
	oras_ai_assert_true(is_int($conversation_id) && $conversation_id > 0, 'Authenticated member should create a conversation.');
	oras_ai_assert_same(17, (int) get_post($conversation_id)->post_author, 'Conversation owner must come from the WordPress session.');

	$GLOBALS['oras_ai_test_current_user_id'] = 18;
	oras_ai_assert_wp_error($store->get_conversation($conversation_id), 'oras_ai_conversation_denied', 'User B must not read User A conversation.');
	oras_ai_assert_wp_error($store->append_message($conversation_id, 'member', 'Cross-owner write'), 'oras_ai_conversation_denied', 'User B must not append to User A conversation.');

	$GLOBALS['oras_ai_test_current_user_id'] = 17;
	oras_ai_assert_same($conversation_id, $store->get_conversation($conversation_id)['id'], 'Owner should read the conversation.');

	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	oras_ai_assert_wp_error($store->create_conversation(array('user_id' => 17)), 'oras_ai_conversation_denied', 'Anonymous conversation creation must fail.');
});

oras_ai_test('member and assistant messages persist as ordered sanitized plain text with UTC timestamps', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$conversation_id = $store->create_conversation();

	$member_id = $store->append_message($conversation_id, 'member', "  <b>When</b> is public night?  ");
	$now += 2;
	$assistant_id = $store->append_message($conversation_id, 'assistant', "Public night is Saturday.\nBring a coat.");
	$messages = $store->get_messages($conversation_id);

	oras_ai_assert_same(array($member_id, $assistant_id), array_column($messages, 'id'), 'Message order must be deterministic.');
	oras_ai_assert_same(array('member', 'assistant'), array_column($messages, 'role'), 'Stored roles changed.');
	oras_ai_assert_same('When is public night?', $messages[0]['content'], 'Member content must be sanitized plain text.');
	oras_ai_assert_same("Public night is Saturday.\nBring a coat.", $messages[1]['content'], 'Assistant plain text changed.');
	oras_ai_assert_same($now - 2, $messages[0]['created_at_utc'], 'Member UTC timestamp changed.');
	oras_ai_assert_same($now, $messages[1]['created_at_utc'], 'Assistant UTC timestamp changed.');
	oras_ai_assert_same((int) get_post($conversation_id)->post_author, (int) get_post($member_id)->post_author, 'Message owner must match conversation owner.');
	oras_ai_assert_same($conversation_id, (int) get_post($member_id)->post_parent, 'Message must be a child of its conversation.');
});

oras_ai_test('malformed empty and internal-role messages fail without transcript writes', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$conversation_id = $store->create_conversation();
	$before = count($GLOBALS['oras_ai_test_posts']);

	foreach (
		array(
			array('system', 'Hidden system prompt'),
			array('developer', 'Hidden developer prompt'),
			array('tool', 'Hidden tool output'),
			array('member', ''),
			array('member', array('not plain text')),
		)
		as $case
	) {
		$result = $store->append_message($conversation_id, $case[0], $case[1]);
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_message', 'Forbidden or malformed message must fail safely.');
	}

	oras_ai_assert_same($before, count($GLOBALS['oras_ai_test_posts']), 'Rejected content created a transcript record.');
});

oras_ai_test('30-day retention keeps younger text and removes exact-edge and older text deterministically', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$cutoff = $now - (30 * DAY_IN_SECONDS);
	$records = array();

	foreach (array('young' => $cutoff + 1, 'edge' => $cutoff, 'old' => $cutoff - 1) as $name => $created_at) {
		$conversation_id = $store->create_conversation();
		$message_id = $store->append_message($conversation_id, 'member', $name . ' private message text');
		update_post_meta($message_id, ORAS_AI_Conversations::META_CREATED_AT, $created_at);
		update_post_meta($conversation_id, ORAS_AI_Conversations::META_UPDATED_AT, $created_at);
		$records[$name] = array($conversation_id, $message_id);
	}

	$store->prune_expired();

	oras_ai_assert_true(null !== get_post($records['young'][1]), 'Message younger than 30 days must remain.');
	oras_ai_assert_same(null, get_post($records['edge'][1]), 'Message exactly 30 days old must be removed.');
	oras_ai_assert_same(null, get_post($records['old'][1]), 'Message older than 30 days must be removed.');
	oras_ai_assert_same(null, get_post($records['edge'][0]), 'Empty exact-edge conversation shell must be removed.');
	oras_ai_assert_same(null, get_post($records['old'][0]), 'Empty older conversation shell must be removed.');
	oras_ai_assert_not_contains('edge private message text', serialize($GLOBALS['oras_ai_test_posts']), 'Expired edge text survived cleanup.');
	oras_ai_assert_not_contains('old private message text', serialize($GLOBALS['oras_ai_test_posts']), 'Expired old text survived cleanup.');
});

oras_ai_test('conversation cleanup is opportunistic idempotent and isolated from the usage ledger', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$conversation_id = $store->create_conversation();
	$message_id = $store->append_message($conversation_id, 'member', 'Expired private transcript');
	$expired = $now - (30 * DAY_IN_SECONDS) - 1;
	update_post_meta($message_id, ORAS_AI_Conversations::META_CREATED_AT, $expired);
	update_post_meta($conversation_id, ORAS_AI_Conversations::META_UPDATED_AT, $expired);
	$ledger = array('retention' => '12 months', 'sentinel' => 'usage metadata');
	update_option(ORAS_AI_Usage_Ledger::OPTION, $ledger, false);

	$messages = $store->get_messages($conversation_id);
	oras_ai_assert_wp_error($messages, 'oras_ai_conversation_denied', 'Opportunistic access should remove an expired empty conversation.');
	oras_ai_assert_same($ledger, get_option(ORAS_AI_Usage_Ledger::OPTION), 'Conversation cleanup changed the 12-month usage ledger.');

	$first = $store->prune_expired();
	$second = $store->prune_expired();
	oras_ai_assert_same(array('messages' => 0, 'conversations' => 0), $first, 'First repeated cleanup should have nothing left to remove.');
	oras_ai_assert_same($first, $second, 'Cleanup must be idempotent.');
});

oras_ai_test('daily cleanup scheduling registers once without duplicate events', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);

	$store->schedule_cleanup();
	$store->schedule_cleanup();

	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_scheduled_events']), 'Cleanup cron must be scheduled only once.');
	$event = $GLOBALS['oras_ai_test_scheduled_events'][0];
	oras_ai_assert_same(ORAS_AI_Conversations::CLEANUP_HOOK, $event['hook'], 'Cleanup cron hook changed.');
	oras_ai_assert_same('daily', $event['recurrence'], 'Cleanup cron interval changed.');
});

oras_ai_test('payment-card guard rejects validated PAN patterns without echoing them and allows harmless numbers', function (): void {
	$guard = new ORAS_AI_Sensitive_Input_Guard();
	$card = '4111 1111 1111 1111';
	$blocked = $guard->validate('my card number is ' . $card);

	oras_ai_assert_wp_error($blocked, 'oras_ai_sensitive_input', 'Obvious valid payment card must be rejected.');
	oras_ai_assert_not_contains($card, $blocked->get_error_message(), 'Safe rejection must not echo the card number.');

	foreach (
		array(
			'Observer Pass costs $25',
			'NGC 4565 is visible tonight',
			'Call 814-555-0199 about order 12345678',
		)
		as $text
	) {
		oras_ai_assert_same(true, $guard->validate($text), 'Harmless numeric content was falsely rejected: ' . $text);
	}
});

oras_ai_test('conversation storage accepts only minimum transcript fields and blocks cards before writes', function (): void {
	oras_ai_test_reset();
	$now = strtotime('2026-09-03 12:00:00 UTC');
	$store = oras_ai_test_conversation_store($now);
	$conversation_id = $store->create_conversation();
	$before = count($GLOBALS['oras_ai_test_posts']);
	$card = '4111-1111-1111-1111';
	$blocked = $store->append_message($conversation_id, 'member', 'Use ' . $card . ' for payment');

	oras_ai_assert_wp_error($blocked, 'oras_ai_sensitive_input', 'Card must be rejected before conversation storage.');
	oras_ai_assert_same($before, count($GLOBALS['oras_ai_test_posts']), 'Rejected card created a message record.');
	oras_ai_assert_not_contains($card, serialize($GLOBALS['oras_ai_test_posts']), 'Card appeared in stored posts.');
	oras_ai_assert_not_contains($card, serialize($GLOBALS['oras_ai_test_post_meta']), 'Card appeared in stored metadata.');
	oras_ai_assert_not_contains($card, serialize(get_option(ORAS_AI_Audit_Log::OPTION_EVENTS, array())), 'Card appeared in audit events.');

	$message_id = $store->append_message($conversation_id, 'assistant', 'A minimal grounded answer.');
	$meta_keys = array_keys(get_post_meta($message_id));
	sort($meta_keys);
	$expected = array(ORAS_AI_Conversations::META_CREATED_AT, ORAS_AI_Conversations::META_ROLE);
	sort($expected);
	oras_ai_assert_same($expected, $meta_keys, 'Message persisted metadata beyond role and retention timestamp.');
	$serialized = serialize(array(get_post($message_id), get_post_meta($message_id)));
	foreach (array('api_key', 'system_prompt', 'raw_provider', 'evidence_blob', 'membership', 'tool_schema') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $serialized, 'Forbidden transcript metadata was persisted.');
	}
});
