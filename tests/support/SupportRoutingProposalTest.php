<?php
declare(strict_types=1);

final class ORAS_AI_Test_Read_Only_Support_Adapter {
	public string $state = 'available';
	public array $mailboxes = array(1 => true, 4 => true);
	public array $tags = array(2 => true, 3 => true);
	public array $calls = array();

	public function status(): ORAS_AI_Fluent_Support_Result {
		$this->calls[] = array('status');
		return new ORAS_AI_Fluent_Support_Result($this->state);
	}

	public function validate_provider_route($mailbox, array $tags): ORAS_AI_Fluent_Support_Result {
		$this->calls[] = array('validate_provider_route', $mailbox, $tags);
		if ($this->state !== 'available' || !is_int($mailbox) || empty($this->mailboxes[$mailbox])) {
			return new ORAS_AI_Fluent_Support_Result('route_invalid', 'mailbox_missing');
		}
		foreach ($tags as $tag) {
			if (!is_int($tag) || empty($this->tags[$tag])) {
				return new ORAS_AI_Fluent_Support_Result('route_invalid', 'tag_missing');
			}
		}
		return new ORAS_AI_Fluent_Support_Result('route_valid', '', null, ORAS_AI_Fluent_Support_Route::validated($mailbox, $tags));
	}
}

function oras_ai_test_support_config(): array {
	return array(
		'primary_mailbox_id' => 1,
		'destination_name' => 'ORAS Support',
		'general_tag_ids' => array(2),
		'topic_routes' => array(
			'membership' => array('mailbox_id' => 1, 'tag_ids' => array(3)),
			'website' => array('mailbox_id' => 1, 'tag_ids' => array(3)),
			'facilities' => array('mailbox_id' => 1, 'tag_ids' => array(3)),
		),
	);
}

function oras_ai_test_support_service(?ORAS_AI_Test_Read_Only_Support_Adapter $adapter = null): array {
	oras_ai_test_reset();
	$adapter = $adapter ?? new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	oras_ai_assert_true($routing->save(oras_ai_test_support_config()), 'Test routing fixture failed to save.');
	$store = new ORAS_AI_Conversations();
	$conversation_id = $store->create_conversation();
	return array(new ORAS_AI_Escalation_Proposal_Service($routing, $store), $routing, $adapter, $conversation_id);
}

function oras_ai_test_support_request(string $question): ORAS_AI_Authorized_Request {
	return new ORAS_AI_Authorized_Request(get_current_user_id(), $question, array('public', 'member'), false);
}

oras_ai_test('M7 Task 2 routing requires explicit primary mailbox and validates provider records read-only', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	oras_ai_assert_wp_error($routing->save(array('primary_mailbox_id' => '', 'destination_name' => 'ORAS Support')), 'oras_ai_invalid_support_routing', 'Missing primary mailbox accepted.');
	$invalid = oras_ai_test_support_config();
	$invalid['primary_mailbox_id'] = 99;
	oras_ai_assert_wp_error($routing->save($invalid), 'oras_ai_invalid_support_routing', 'Nonexistent mailbox accepted.');
	oras_ai_assert_same(false, get_option(ORAS_AI_Support_Routing::OPTION_ROUTING, false), 'Invalid config was partially saved.');
	foreach ($adapter->calls as $call) {
		oras_ai_assert_true(in_array($call[0], array('status', 'validate_provider_route'), true), 'Config validation called a provider write.');
	}
});

oras_ai_test('M7 Task 2 topic routes use General fallback for unknown missing or stale mappings', function (): void {
	list($service, $routing, $adapter) = oras_ai_test_support_service();
	$topic = $routing->route_for('membership');
	oras_ai_assert_same('route_valid', $topic['status'], 'Valid topic route missing.');
	oras_ai_assert_same(array(3), $topic['route']->tag_ids(), 'Topic tag mapping missing.');
	oras_ai_assert_same(false, $topic['fallback'], 'Valid route incorrectly used fallback.');
	foreach (array('not_a_topic', 'events') as $unmapped) {
		$fallback = $routing->route_for($unmapped);
		oras_ai_assert_same('route_valid', $fallback['status'], 'General fallback missing.');
		oras_ai_assert_same('general_support', $fallback['topic'], 'Unknown or missing topic escaped allowlist.');
		oras_ai_assert_same(array(2), $fallback['route']->tag_ids(), 'General tag missing.');
	}
	unset($adapter->tags[3]);
	$stale = $routing->route_for('membership');
	oras_ai_assert_same('general_support', $stale['topic'], 'Stale topic route did not fall back.');
	oras_ai_assert_same(true, $stale['fallback'], 'Stale route fallback not marked.');
});

oras_ai_test('M7 Task 2 invalid General or primary route fails closed without provider default mailbox', function (): void {
	list($service, $routing, $adapter) = oras_ai_test_support_service();
	unset($adapter->tags[2]);
	oras_ai_assert_same('routing_unavailable', $routing->route_for('membership')['status'], 'Invalid General fallback was accepted.');
	$adapter->tags[2] = true;
	unset($adapter->mailboxes[1]);
	oras_ai_assert_same('routing_unavailable', $routing->route_for('membership')['status'], 'Missing primary mailbox was defaulted.');
	$adapter->state = 'missing';
	oras_ai_assert_same('routing_unavailable', $routing->route_for('membership')['status'], 'Missing provider was accepted.');
	$adapter->state = 'incompatible';
	oras_ai_assert_same('routing_unavailable', $routing->route_for('membership')['status'], 'Incompatible provider was accepted.');
});

oras_ai_test('M7 Task 2 malformed stored topic mapping still uses valid General fallback', function (): void {
	list($service, $routing) = oras_ai_test_support_service();
	$stored = $routing->configuration();
	$stored['topic_routes']['membership']['mailbox_id'] = 'not-an-id';
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, $stored, false);
	$route = $routing->route_for('membership');
	oras_ai_assert_same('route_valid', $route['status'], 'Malformed topic route disabled valid General fallback.');
	oras_ai_assert_same('general_support', $route['topic'], 'Malformed topic route did not use General.');
});

oras_ai_test('M7 Task 2 routing rejects invalid tags malformed IDs and unknown configured topics atomically', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	foreach (array(
		array('general_tag_ids' => array(99)),
		array('primary_mailbox_id' => '1oops'),
		array('topic_routes' => array('malicious_topic' => array('mailbox_id' => 1, 'tag_ids' => array()))),
		array('topic_routes' => array('membership' => array('mailbox_id' => 4, 'tag_ids' => array(99)))),
	) as $change) {
		$config = array_replace(oras_ai_test_support_config(), $change);
		oras_ai_assert_wp_error($routing->save($config), 'oras_ai_invalid_support_routing', 'Malformed routing config accepted.');
	}
	oras_ai_assert_same(false, get_option(ORAS_AI_Support_Routing::OPTION_ROUTING, false), 'Rejected config was stored.');
});

oras_ai_test('M7 Task 2 admin route save requires manage_options and action nonce', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$admin = new ORAS_AI_Support_Routing_Admin(new ORAS_AI_Support_Routing($adapter));
	$_POST = oras_ai_test_support_config();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	try { $admin->save_settings(); throw new RuntimeException('Unauthorized save passed.'); } catch (ORAS_AI_Test_Die_Exception $exception) {}
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized request reached nonce verification.');
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	try { $admin->save_settings(); throw new RuntimeException('Invalid nonce passed.'); } catch (ORAS_AI_Test_Nonce_Exception $exception) {}
	oras_ai_assert_same(false, get_option(ORAS_AI_Support_Routing::OPTION_ROUTING, false), 'Unauthorized config saved.');
});

oras_ai_test('M7 Task 2 admin save validates config and audits only semantic change', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$admin = new ORAS_AI_Support_Routing_Admin(new ORAS_AI_Support_Routing($adapter));
	$_POST = oras_ai_test_support_config();
	$_POST['oras_ai_support_routing_nonce'] = 'valid';
	try { $admin->save_settings(); } catch (ORAS_AI_Test_Redirect_Exception $redirect) {}
	$stored = get_option(ORAS_AI_Support_Routing::OPTION_ROUTING, false);
	oras_ai_assert_true(is_array($stored), 'Valid config not stored.');
	oras_ai_assert_same(1, $stored['primary_mailbox_id'], 'Primary mailbox changed.');
	$events = wp_json_encode(ORAS_AI_Audit_Log::recent_events());
	oras_ai_assert_contains('config.m7.support_routing', $events, 'Routing config change not audited.');
	oras_ai_assert_not_contains('member@example.invalid', $events, 'Audit exposed customer data.');
	foreach ($adapter->calls as $call) { oras_ai_assert_true(in_array($call[0], array('status', 'validate_provider_route'), true), 'Admin save wrote to Fluent Support.'); }
});

oras_ai_test('M7 Task 2 admin blank optional topic rows remain unmapped and page is protected', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	$admin = new ORAS_AI_Support_Routing_Admin($routing);
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start(); $admin->render_page(); $hidden = (string) ob_get_clean();
	oras_ai_assert_same('', $hidden, 'Unauthorized admin saw support routing IDs.');
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ob_start(); $admin->render_page(); $html = (string) ob_get_clean();
	oras_ai_assert_contains('Support Routing', $html, 'Support admin page missing.');
	$_POST = array(
		'oras_ai_support_routing_nonce' => 'valid', 'primary_mailbox_id' => '1',
		'destination_name' => 'ORAS Support', 'general_tag_ids' => '2',
		'topic_routes' => array('membership' => array('mailbox_id' => '', 'tag_ids' => '')),
	);
	try { $admin->save_settings(); } catch (ORAS_AI_Test_Redirect_Exception $redirect) {}
	$stored = $routing->configuration();
	oras_ai_assert_same(array(), $stored['topic_routes'], 'Blank optional row became an explicit route.');
	oras_ai_assert_same('general_support', $routing->route_for('membership')['topic'], 'Blank topic failed to use General.');
});

oras_ai_test('M7 Task 2 support routing submenu is manage-options protected', function (): void {
	oras_ai_test_reset();
	$assistant = new ORAS_AI_Assistant();
	$assistant->register_admin_menu();
	$pages = array_values(array_filter($GLOBALS['oras_ai_test_submenu_pages'], static function ($page): bool { return ($page[4] ?? '') === 'oras-ai-support-routing'; }));
	oras_ai_assert_same(1, count($pages), 'Support routing submenu missing.');
	oras_ai_assert_same('manage_options', $pages[0][3], 'Support routing submenu lacks admin capability.');
});

oras_ai_test('M7 Task 2 admin tag-only mapping uses the configured primary mailbox', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	$config = oras_ai_test_support_config();
	$config['topic_routes'] = array('membership' => array('mailbox_id' => '', 'tag_ids' => '3'));
	oras_ai_assert_true($routing->save($config), 'Tag-only route could not use primary mailbox.');
	$resolved = $routing->route_for('membership');
	oras_ai_assert_same('route_valid', $resolved['status'], 'Tag-only route invalid.');
	oras_ai_assert_same(1, $resolved['route']->mailbox_id(), 'Tag-only route selected provider default mailbox.');
	oras_ai_assert_same(array(3), $resolved['route']->tag_ids(), 'Configured topic tag lost.');
});

oras_ai_test('M7 Task 2 support no-evidence path proposes but astronomy no-evidence does not', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$issue = $service->propose(oras_ai_test_support_request('What are the ORAS membership renewal rules?'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'));
	oras_ai_assert_same('proposed', $issue->status(), 'Unanswered support issue lacked proposal.');
	oras_ai_assert_same('membership', $issue->proposal()->topic(), 'Membership topic changed.');
	$astronomy = $service->propose(oras_ai_test_support_request('What is the current position of Mars?'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unavailable.'));
	oras_ai_assert_same('none', $astronomy->status(), 'Astronomy no-evidence created support proposal.');
	$oras_current_astronomy = $service->propose(oras_ai_test_support_request('What is the current position of Mars at ORAS?'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unavailable.'));
	oras_ai_assert_same('none', $oras_current_astronomy->status(), 'ORAS site mention turned astronomy data failure into support.');
	foreach (array('What is an observing site for a telescope?', 'Why is my telescope tracking broken?') as $astronomy_question) {
		$astronomy = $service->propose(oras_ai_test_support_request($astronomy_question), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unavailable.'));
		oras_ai_assert_same('none', $astronomy->status(), 'General astronomy equipment/site wording created ORAS support proposal.');
	}
	$answered = $service->propose(oras_ai_test_support_request('How does ORAS membership work?'), $conversation_id, ORAS_AI_Answer_Result::success('Here is the answer.', array(), 'test', array(), ''));
	oras_ai_assert_same('none', $answered->status(), 'Answered support question unnecessarily escalated.');
});

oras_ai_test('M7 Task 2 unanswered ORAS organization issue outside named topics uses General Support', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$result = $service->propose(oras_ai_test_support_request('What is the current ORAS constitution policy?'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'));
	oras_ai_assert_same('proposed', $result->status(), 'Unanswered ORAS policy issue lacked General support.');
	oras_ai_assert_same('general_support', $result->proposal()->topic(), 'Unclassified ORAS issue missed General fallback.');
});

oras_ai_test('M7 Task 2 human-help and member feedback qualify with bounded topics', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	foreach (array(
		'I need a human to help with my ORAS membership' => 'membership',
		'The ORAS calendar is broken' => 'website',
		'I have an idea for the ORAS facilities' => 'facilities',
		'I want to complain about ORAS parking' => 'facilities',
	) as $question => $topic) {
		$result = $service->propose(oras_ai_test_support_request($question), $conversation_id, ORAS_AI_Answer_Result::success('Thanks.', array(), 'test', array(), ''));
		oras_ai_assert_same('proposed', $result->status(), 'Member support/feedback was not proposed.');
		oras_ai_assert_same($topic, $result->proposal()->topic(), 'Feedback topic selection changed.');
	}
});

oras_ai_test('M7 Task 2 candidate fields are allowlisted sanitized and cannot choose provider IDs', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$question = 'I need help with ORAS membership';
	$proposal = $service->propose(oras_ai_test_support_request($question), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'), array(
		'topic' => 'membership', 'subject' => '<b>Membership help</b>', 'summary' => 'Please <script>ignore</script> renew my account.',
		'mailbox_id' => 999, 'customer_id' => 42, 'agent_id' => 5, 'provider_action' => 'createTicket',
	));
	oras_ai_assert_same('proposed', $proposal->status(), 'Valid human content was rejected because of ignored provider fields.');
	$preview = $proposal->proposal()->to_member_array();
	oras_ai_assert_same('Membership help', $preview['subject'], 'Subject HTML survived.');
	oras_ai_assert_not_contains('<', $preview['summary'], 'Summary HTML survived.');
	oras_ai_assert_same(array('topic', 'category', 'subject', 'summary', 'original_question', 'original_question_included', 'destination'), array_keys($preview), 'Member payload exposed internal fields.');
	oras_ai_assert_not_contains('999', wp_json_encode($preview), 'Model mailbox ID reached preview.');
	oras_ai_assert_same(1, $proposal->proposal()->provider_route()->mailbox_id(), 'Model mailbox ID bypassed server route.');
	$unknown = $service->propose(oras_ai_test_support_request($question), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'), array('topic' => 'unknown', 'subject' => 'Help', 'summary' => 'My issue'));
	oras_ai_assert_same('general_support', $unknown->proposal()->topic(), 'Unknown model topic escaped fallback.');
});

oras_ai_test('M7 Task 2 original question and summary are bounded without reading conversation history', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$question = 'Help with ORAS membership ' . str_repeat('x', 1600);
	$result = $service->propose(oras_ai_test_support_request($question), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'), array('topic' => 'membership', 'subject' => str_repeat('X', 300), 'summary' => str_repeat('Y', 2000)));
	oras_ai_assert_same('proposed', $result->status(), 'Long model output prevented bounded fallback proposal.');
	$preview = $result->proposal()->to_member_array();
	oras_ai_assert_true(strlen($preview['subject']) <= 192, 'Subject exceeded provider title width.');
	oras_ai_assert_true(strlen($preview['summary']) <= 600, 'Summary exceeded preview bound.');
	oras_ai_assert_true(strlen($preview['original_question']) <= 1000, 'Original question exceeded bound.');
	oras_ai_assert_contains('Help with ORAS membership', $preview['original_question'], 'Member issue missing.');
	oras_ai_assert_same(true, $preview['original_question_included'], 'Preview hid question inclusion.');
	oras_ai_assert_same('ORAS Support', $preview['destination'], 'Friendly destination missing.');
	oras_ai_assert_not_contains('model prompt', wp_json_encode($preview), 'Unrelated context leaked.');
});

oras_ai_test('M7 Task 2 missing provider gives routing unavailable only for appropriate support issue', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$adapter->state = 'missing';
	$result = $service->propose(oras_ai_test_support_request('I need ORAS membership support'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'));
	oras_ai_assert_same('routing_unavailable', $result->status(), 'Missing provider was not bounded.');
	$events = ORAS_AI_Audit_Log::recent_events();
	oras_ai_assert_same('route_unavailable', $events[0]['action'], 'Routing failure audit action missing.');
	oras_ai_assert_same('unavailable', $events[0]['outcome'], 'Routing failure was audited as successful routing.');
	$normal = $service->propose(oras_ai_test_support_request('What is Mars?'), $conversation_id, ORAS_AI_Answer_Result::success('Planet.', array(), 'test', array(), ''));
	oras_ai_assert_same('none', $normal->status(), 'Normal astronomy answer was blocked by support provider state.');
});

oras_ai_test('M7 Task 2 rejects cross-user proposal and logs only topic metadata', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$other = new ORAS_AI_Authorized_Request(99, 'ORAS membership support', array('public'), false);
	oras_ai_assert_same('none', $service->propose($other, $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'))->status(), 'Other user made a proposal.');
	$question = 'Please help with ORAS membership private-value-123';
	$result = $service->propose(oras_ai_test_support_request($question), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'));
	oras_ai_assert_same('proposed', $result->status(), 'Owner proposal failed.');
	$audit = wp_json_encode(ORAS_AI_Audit_Log::recent_events());
	oras_ai_assert_contains('support.escalation', $audit, 'Proposal event missing.');
	oras_ai_assert_not_contains('private-value-123', $audit, 'Question leaked into audit.');
	oras_ai_assert_not_contains('ORAS Support', $audit, 'Destination leaked into audit.');
});

oras_ai_test('M7 Task 2 proposal construction performs no Fluent Support writes', function (): void {
	list($service, $routing, $adapter, $conversation_id) = oras_ai_test_support_service();
	$result = $service->propose(oras_ai_test_support_request('ORAS membership help'), $conversation_id, ORAS_AI_Answer_Result::no_evidence('Unknown.'));
	oras_ai_assert_same('proposed', $result->status(), 'Fixture proposal missing.');
	foreach ($adapter->calls as $call) {
		oras_ai_assert_true(in_array($call[0], array('status', 'validate_provider_route'), true), 'Proposal attempted a provider write.');
	}
});

oras_ai_test('M7 Task 2 authenticated chat result exposes only a bounded ephemeral proposal', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	oras_ai_assert_true($routing->save(oras_ai_test_support_config()), 'Routing fixture failed.');
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Answer.'));
	$store = new ORAS_AI_Conversations();
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }), $orchestrator);
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $store, new ORAS_AI_Escalation_Proposal_Service($routing, $store));
	$current = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$response = $transport->dispatch(oras_ai_test_transport_request('send', array(
		'conversation_id' => $current['conversation_id'],
		'question' => 'What are the ORAS membership renewal rules?',
		'mailbox_id' => 999, 'tag_ids' => array(999), 'customer_id' => 999, 'topic' => 'payment',
	)));
	oras_ai_assert_same('no_evidence', $response['result']['status'], 'Normal answer result changed.');
	oras_ai_assert_same('proposed', $response['result']['escalation']['status'], 'Support proposal absent from structured result.');
	oras_ai_assert_same('membership', $response['result']['escalation']['preview']['topic'], 'Browser topic replaced server classification.');
	oras_ai_assert_not_contains('999', wp_json_encode($response['result']), 'Browser provider IDs reached result.');
	$loaded = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $current['conversation_id'])));
	oras_ai_assert_same(array('conversation_id', 'conversation', 'messages'), array_keys($loaded), 'Task 2 persisted confirmation state.');
	oras_ai_assert_same(2, count($loaded['messages']), 'M4 transcript changed.');
});

oras_ai_test('M7 Task 2 unavailable routing is structured while ordinary answer remains normal', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$adapter->state = 'missing';
	$routing = new ORAS_AI_Support_Routing($adapter);
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Answer.'));
	$store = new ORAS_AI_Conversations();
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }), $orchestrator);
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $store, new ORAS_AI_Escalation_Proposal_Service($routing, $store));
	$current = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$support = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $current['conversation_id'], 'question' => 'What are ORAS membership rules?')));
	oras_ai_assert_same('routing_unavailable', $support['result']['escalation']['status'], 'Provider absence was not structured.');
	$astronomy = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $current['conversation_id'], 'question' => 'What is a light year in astronomy?')));
	oras_ai_assert_false(isset($astronomy['result']['escalation']), 'Ordinary astronomy answer gained an escalation.');
});
