<?php
declare(strict_types=1);

final class ORAS_AI_Test_Confirmation_Adapter {
	public array $calls = array();
	public string $ticket_status = 'ticket_created';
	public string $ticket_reason = '';
	public string $provider_state = 'available';
	public bool $route_ok = true;
	public bool $customer_ok = true;
	public $during_create = null;
	public function __construct() {}
	public function status() { $this->calls[] = 'status'; return new ORAS_AI_Fluent_Support_Result($this->provider_state); }
	public function validate_provider_route($mailbox_id, array $tag_ids) {
		$this->calls[] = 'route';
		if (!$this->route_ok) { return new ORAS_AI_Fluent_Support_Result('route_invalid', 'tag_missing'); }
		return new ORAS_AI_Fluent_Support_Result('route_valid', '', null, ORAS_AI_Fluent_Support_Route::validated($mailbox_id, $tag_ids));
	}
	public function resolve_confirmed_customer($user_id, $allow_create = false) {
		$this->calls[] = 'customer';
		return $this->customer_ok ? new ORAS_AI_Fluent_Support_Result('customer_resolved', '', 7) : new ORAS_AI_Fluent_Support_Result('customer_failure', 'conflicting_customer');
	}
	public function create_confirmed_ticket($customer_id, $route, $subject, $content) {
		$this->calls[] = array('ticket', $customer_id, $route->mailbox_id(), $route->tag_ids(), $subject, $content);
		if ( is_callable( $this->during_create ) ) { call_user_func( $this->during_create ); }
		return new ORAS_AI_Fluent_Support_Result($this->ticket_status, $this->ticket_reason, 'ticket_created' === $this->ticket_status ? 123 : null);
	}
}

function oras_ai_confirmation_fixture(): array {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Confirmation_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, array('primary_mailbox_id' => 1, 'destination_name' => 'ORAS Support', 'general_tag_ids' => array(2), 'topic_routes' => array()), false);
	$conversations = new ORAS_AI_Conversations();
	$conversation_id = $conversations->create_conversation();
	$pending = new ORAS_AI_Pending_Escalations();
	$proposal = new ORAS_AI_Escalation_Proposal(get_current_user_id(), $conversation_id, ORAS_AI_Support_Topic::GENERAL, 'Stored subject', 'Stored summary', 'Stored original question', 'ORAS Support', ORAS_AI_Fluent_Support_Route::validated(1, array(2)));
	$created = $pending->create($proposal);
	$service = new ORAS_AI_Escalation_Confirmation_Service($pending, $routing, $conversations, $adapter);
	return array($service, $pending, $adapter, $conversations, $conversation_id, $created);
}

oras_ai_test('M7 Task 3 pending is durable, private, token-only and side-effect free', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	oras_ai_assert_same('awaiting_confirmation', $created['status'], 'Pending state missing.');
	oras_ai_assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $created['token']), 'Token is not opaque.');
	oras_ai_assert_same('awaiting_confirmation', $service->status($created['token'], $conversation_id)['status'], 'Status did not load.');
	oras_ai_assert_same(array(), $adapter->calls, 'Proposal/status touched provider.');
	oras_ai_assert_true(!isset($created['mailbox_id']) && !isset($created['customer_id']), 'Provider IDs leaked.');
});

oras_ai_test('M7 Task 3 confirmation creates one ticket from stored content and replays result', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$first = $service->confirm($created['token'], $conversation_id);
	oras_ai_assert_same('created', $first['status'], 'Ticket was not created.');
	oras_ai_assert_same(123, $first['ticket_id'], 'Ticket ID missing.');
	$again = $service->confirm($created['token'], $conversation_id);
	oras_ai_assert_same($first, $again, 'Replay did not return persisted result.');
	$tickets = array_values(array_filter($adapter->calls, 'is_array'));
	oras_ai_assert_same(1, count($tickets), 'Replay created another ticket.');
	oras_ai_assert_true(str_contains($tickets[0][5], 'Stored original question') && str_contains($tickets[0][5], 'Stored summary'), 'Stored content missing.');
	oras_ai_assert_true(!str_contains($tickets[0][5], 'unrelated turn'), 'Unrelated content included.');
});

oras_ai_test('M7 Task 3 uncertainty never retries and stale creating is uncertain', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->ticket_status = 'ticket_uncertain';
	$adapter->ticket_reason = 'provider_create_uncertain';
	oras_ai_assert_same('uncertain', $service->confirm($created['token'], $conversation_id)['status'], 'Ambiguous create was not uncertain.');
	oras_ai_assert_same('uncertain', $service->confirm($created['token'], $conversation_id)['status'], 'Uncertain replay changed state.');
	oras_ai_assert_same(1, count(array_filter($adapter->calls, 'is_array')), 'Uncertain create retried.');
});

oras_ai_test('M7 Task 3 cancellation, expiry and ownership block writes', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	oras_ai_assert_same('cancelled', $service->cancel($created['token'], $conversation_id)['status'], 'Cancellation failed.');
	oras_ai_assert_same('cancelled', $service->confirm($created['token'], $conversation_id)['status'], 'Cancelled proposal confirmed.');
	oras_ai_assert_same('cancelled', $service->cancel($created['token'], $conversation_id)['status'], 'Cancellation replay failed.');
	oras_ai_assert_same(array(), $adapter->calls, 'Cancellation touched provider.');
});

oras_ai_test('M7 Task 3 route change and customer conflict fail before ticket create', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$config = get_option(ORAS_AI_Support_Routing::OPTION_ROUTING);
	$config['destination_name'] = 'Changed destination';
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, $config, false);
	$changed = $service->confirm($created['token'], $conversation_id);
	oras_ai_assert_same('failed', $changed['status'], 'Changed destination accepted.');
	oras_ai_assert_same('route_changed_refresh_required', $changed['reason'], 'Refresh reason missing.');
	oras_ai_assert_true(!in_array('customer', $adapter->calls, true), 'Customer created after changed route.');
	oras_ai_assert_same(0, count(array_filter($adapter->calls, 'is_array')), 'Ticket created on changed route.');
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->customer_ok = false;
	oras_ai_assert_same('failed', $service->confirm($created['token'], $conversation_id)['status'], 'Customer conflict accepted.');
	oras_ai_assert_same(0, count(array_filter($adapter->calls, 'is_array')), 'Ticket created after customer conflict.');
});

oras_ai_test('M7 Task 3 concurrent replay observes creating and never enters provider twice', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$inside = null;
	$adapter->during_create = static function () use ($service, $created, $conversation_id, &$inside): void {
		$inside = $service->confirm($created['token'], $conversation_id);
	};
	oras_ai_assert_same('created', $service->confirm($created['token'], $conversation_id)['status'], 'First confirm failed.');
	oras_ai_assert_same('creating', $inside['status'], 'Concurrent confirm did not observe claim.');
	oras_ai_assert_same(1, count(array_filter($adapter->calls, 'is_array')), 'Concurrent provider create occurred.');
});

oras_ai_test('M7 Task 3 expired and stale claimed records do not contact provider', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$posts = get_posts(array('post_type' => ORAS_AI_Pending_Escalations::POST_TYPE, 'post_status' => 'private'));
	$record = get_post_meta($posts[0]->ID, ORAS_AI_Pending_Escalations::META_RECORD, true);
	$record['expires_at'] = time() - 1;
	update_post_meta($posts[0]->ID, ORAS_AI_Pending_Escalations::META_RECORD, $record);
	oras_ai_assert_same('expired', $service->confirm($created['token'], $conversation_id)['status'], 'Expired token accepted.');
	oras_ai_assert_same(array(), $adapter->calls, 'Expired token contacted provider.');
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$posts = get_posts(array('post_type' => ORAS_AI_Pending_Escalations::POST_TYPE, 'post_status' => 'private'));
	$record = get_post_meta($posts[0]->ID, ORAS_AI_Pending_Escalations::META_RECORD, true);
	oras_ai_assert_true($pending->claim($record, 'confirm'), 'Claim fixture failed.');
	$claim_key = 'oras_ai_escalation_claim_' . $record['token_hash'];
	update_option($claim_key, array('action' => 'confirm', 'at' => time() - ORAS_AI_Pending_Escalations::STALE_CREATING_SECONDS - 1), false);
	oras_ai_assert_same('uncertain', $service->confirm($created['token'], $conversation_id)['status'], 'Stale claim retried.');
	oras_ai_assert_same(array(), $adapter->calls, 'Stale claim contacted provider.');
});

oras_ai_test('M7 Task 3 owner and conversation binding reject token reuse', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	oras_ai_assert_wp_error($service->confirm('bad', $conversation_id), 'oras_ai_escalation_denied', 'Malformed token accepted.');
	$other = $conversations->create_conversation();
	oras_ai_assert_wp_error($service->confirm($created['token'], $other), 'oras_ai_escalation_denied', 'Cross-conversation token accepted.');
	$GLOBALS['oras_ai_test_current_user_id'] = 8;
	oras_ai_assert_wp_error($service->confirm($created['token'], $conversation_id), 'oras_ai_escalation_denied', 'Cross-user token accepted.');
	oras_ai_assert_same(array(), $adapter->calls, 'Unauthorized token contacted provider.');
});

oras_ai_test('M7 Task 3 transport requires nonce, membership and enabled AI before confirmation', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Answer.'));
	$eligible = true;
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () use (&$eligible) { return $eligible; }), $orchestrator);
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $conversations, null, $service);
	$request = oras_ai_test_transport_request('confirm_escalation', array('token' => $created['token'], 'customer_id' => 999, 'mailbox_id' => 999, 'content' => 'injected'));
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	oras_ai_assert_wp_error($transport->dispatch($request), 'oras_ai_request_denied', 'Bad nonce confirmed.');
	$GLOBALS['oras_ai_test_nonce_valid'] = true;
	$eligible = false;
	oras_ai_assert_wp_error($transport->dispatch($request), 'oras_ai_request_denied', 'Ineligible member confirmed.');
	$eligible = true;
	ORAS_AI_Config::set_member_ai_enabled(false);
	oras_ai_assert_wp_error($transport->dispatch($request), 'oras_ai_request_denied', 'Kill switch allowed confirmation.');
	ORAS_AI_Config::set_member_ai_enabled(true);
	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	oras_ai_assert_wp_error($transport->dispatch($request), 'oras_ai_request_denied', 'Anonymous user confirmed.');
	$GLOBALS['oras_ai_test_current_user_id'] = 7;
	$pending_status = $transport->dispatch(oras_ai_test_transport_request('escalation_status', array('token' => $created['token'])));
	oras_ai_assert_same('awaiting_confirmation', $pending_status['status'], 'Token-only status failed.');
	oras_ai_assert_same('created', $transport->dispatch($request)['status'], 'Authorized transport confirmation failed.');
	oras_ai_assert_same('created', $transport->dispatch(oras_ai_test_transport_request('escalation_status', array('token' => $created['token'])))['status'], 'Token-only result load failed.');
	$tickets = array_values(array_filter($adapter->calls, 'is_array'));
	oras_ai_assert_same(1, count($tickets), 'Transport created multiple tickets.');
	oras_ai_assert_same('Stored subject', $tickets[0][4], 'Browser replaced stored subject.');
	oras_ai_assert_true(!str_contains($tickets[0][5], 'injected'), 'Browser replaced stored body.');
	oras_ai_assert_same(1, $tickets[0][2], 'Browser replaced mailbox.');
});

oras_ai_test('M7 Task 3 audit records states without private ticket content', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$service->confirm($created['token'], $conversation_id);
	$service->confirm($created['token'], $conversation_id);
	$audit = wp_json_encode(ORAS_AI_Audit_Log::recent_events());
	oras_ai_assert_contains('support_ticket_created', $audit, 'Created event absent.');
	oras_ai_assert_contains('duplicate_confirmation_replayed', $audit, 'Replay event absent.');
	foreach (array('Stored original question', 'Stored summary', 'Stored subject', 'ORAS Support', 'injected') as $private) {
		oras_ai_assert_not_contains($private, $audit, 'Private content in audit.');
	}
});

oras_ai_test('M7 Task 3 stale state writer cannot overwrite a persisted ticket ID', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$stale = $pending->load($created['token'], $conversation_id);
	$service->confirm($created['token'], $conversation_id);
	$stale['state'] = 'uncertain';
	oras_ai_assert_false($pending->save($stale), 'Stale compare-and-swap overwrote created.');
	$loaded = $service->status($created['token'], $conversation_id);
	oras_ai_assert_same('created', $loaded['status'], 'Persisted ticket state was lost.');
	oras_ai_assert_same(123, $loaded['ticket_id'], 'Persisted ticket ID was lost.');
});

oras_ai_test('M7 Task 3 thirty-day cleanup removes local text and claim', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$service->cancel($created['token'], $conversation_id);
	$posts = get_posts(array('post_type' => ORAS_AI_Pending_Escalations::POST_TYPE, 'post_status' => 'private'));
	$record = get_post_meta($posts[0]->ID, ORAS_AI_Pending_Escalations::META_RECORD, true);
	$claim_key = 'oras_ai_escalation_claim_' . $record['token_hash'];
	$record['created_at'] = time() - ORAS_AI_Pending_Escalations::RETENTION_SECONDS - 1;
	update_post_meta($posts[0]->ID, ORAS_AI_Pending_Escalations::META_RECORD, $record);
	$pending->prune_expired();
	oras_ai_assert_same(null, get_post($posts[0]->ID), 'Local text survived retention.');
	oras_ai_assert_same(false, get_option($claim_key, false), 'Claim survived retention.');
});

oras_ai_test('M7 Task 3 provider preflight and definite create failure remain terminal', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->provider_state = 'missing';
	oras_ai_assert_same('failed', $service->confirm($created['token'], $conversation_id)['status'], 'Missing provider accepted.');
	oras_ai_assert_same(0, count(array_filter($adapter->calls, 'is_array')), 'Missing provider wrote ticket.');
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->route_ok = false;
	oras_ai_assert_same('failed', $service->confirm($created['token'], $conversation_id)['status'], 'Invalid route accepted.');
	oras_ai_assert_same(0, count(array_filter($adapter->calls, 'is_array')), 'Invalid route wrote ticket.');
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->ticket_status = 'ticket_failed';
	$adapter->ticket_reason = 'provider_rejected_before_insert';
	$failed = $service->confirm($created['token'], $conversation_id);
	oras_ai_assert_same('failed', $failed['status'], 'Definite create failure uncertain.');
	oras_ai_assert_false(isset($failed['ticket_id']), 'Failed result claimed ticket ID.');
	oras_ai_assert_same('failed', $service->confirm($created['token'], $conversation_id)['status'], 'Failed token retried.');
	oras_ai_assert_same(1, count(array_filter($adapter->calls, 'is_array')), 'Definite failure retried provider.');
});

oras_ai_test('M7 Task 3 tag warning keeps known ticket and cancellation cannot erase it', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$adapter->ticket_reason = 'tag_attachment_uncertain';
	$known = $service->confirm($created['token'], $conversation_id);
	oras_ai_assert_same('created', $known['status'], 'Known ticket became uncertain.');
	oras_ai_assert_same(123, $known['ticket_id'], 'Known ticket ID lost.');
	oras_ai_assert_same('tag_attachment_uncertain', $known['reason'], 'Tag warning missing.');
	oras_ai_assert_same('created', $service->cancel($created['token'], $conversation_id)['status'], 'Cancellation erased created ticket.');
	oras_ai_assert_same(1, count(array_filter($adapter->calls, 'is_array')), 'Tag warning replayed create.');
});

oras_ai_test('M7 Task 3 pending tokens are unique and raw tokens are not stored', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$route = ORAS_AI_Fluent_Support_Route::validated(1, array(2));
	$second = $service->propose(new ORAS_AI_Escalation_Proposal(7, $conversation_id, 'general_support', 'Second', 'Summary', 'Question', 'ORAS Support', $route));
	oras_ai_assert_true($created['token'] !== $second['token'], 'Token reused.');
	oras_ai_assert_not_contains($created['token'], wp_json_encode($GLOBALS['oras_ai_test_post_meta']), 'Raw token stored.');
	oras_ai_assert_not_contains($second['token'], wp_json_encode($GLOBALS['oras_ai_test_post_meta']), 'Raw token stored.');
});

oras_ai_test('M7 Task 3 chat send persists a preview without provider writes', function (): void {
	oras_ai_test_reset();
	$adapter = new ORAS_AI_Test_Confirmation_Adapter();
	$routing = new ORAS_AI_Support_Routing($adapter);
	update_option(ORAS_AI_Support_Routing::OPTION_ROUTING, array('primary_mailbox_id' => 1, 'destination_name' => 'ORAS Support', 'general_tag_ids' => array(2), 'topic_routes' => array()), false);
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Answer.'));
	$conversations = new ORAS_AI_Conversations();
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }), $orchestrator);
	$pending = new ORAS_AI_Pending_Escalations();
	$confirmation = new ORAS_AI_Escalation_Confirmation_Service($pending, $routing, $conversations, $adapter);
	$transport = new ORAS_AI_Conversation_Transport($gateway, $orchestrator, $conversations, new ORAS_AI_Escalation_Proposal_Service($routing, $conversations, new ORAS_AI_Test_Support_Summary_Service()), $confirmation);
	$current = $transport->dispatch(oras_ai_test_transport_request('new_chat'));
	$sent = $transport->dispatch(oras_ai_test_transport_request('send', array('conversation_id' => $current['conversation_id'], 'question' => 'What are ORAS membership rules?')));
	oras_ai_assert_same('awaiting_confirmation', $sent['result']['escalation']['status'], 'Send did not persist proposal.');
	$token = $sent['result']['escalation']['token'];
	oras_ai_assert_same('awaiting_confirmation', $transport->dispatch(oras_ai_test_transport_request('escalation_status', array('token' => $token)))['status'], 'Persisted proposal did not reload.');
	$loaded = $transport->dispatch(oras_ai_test_transport_request('load', array('conversation_id' => $current['conversation_id'])));
	oras_ai_assert_same('awaiting_confirmation', $loaded['escalations'][0]['status'], 'Conversation restore omitted proposal.');
	oras_ai_assert_same($sent['result']['escalation']['preview'], $loaded['escalations'][0]['preview'], 'Restore changed preview.');
	oras_ai_assert_same(0, count(array_filter($adapter->calls, 'is_array')), 'Proposal created ticket.');
});

oras_ai_test('M7 Task 4 restore exposes an owner-only opaque reference and reuses stored summary', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$restored = $service->for_conversation($conversation_id);
	oras_ai_assert_same(1, count($restored), 'Pending proposal missing from restore.');
	oras_ai_assert_same('awaiting_confirmation', $restored[0]['status'], 'Pending state missing.');
	oras_ai_assert_same('Stored summary', $restored[0]['preview']['summary'], 'Summary was regenerated.');
	oras_ai_assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $restored[0]['token']), 'Restore reference is not opaque.');
	oras_ai_assert_same('awaiting_confirmation', $service->status($restored[0]['token'], $conversation_id)['status'], 'Restored reference did not load.');
	oras_ai_assert_same(array(), $adapter->calls, 'Restore touched provider.');
	oras_ai_assert_not_contains('mailbox_id', wp_json_encode($restored), 'Restore exposed routing IDs.');
	$GLOBALS['oras_ai_test_current_user_id'] = 8;
	oras_ai_assert_wp_error($service->for_conversation($conversation_id), 'oras_ai_escalation_denied', 'Cross-user restore succeeded.');
	$GLOBALS['oras_ai_test_current_user_id'] = 7;
	oras_ai_assert_same('created', $service->confirm($restored[0]['token'], $conversation_id)['status'], 'Restored reference could not confirm.');
});

oras_ai_test('M7 Task 4 restore retains terminal outcomes without replaying ticket creation', function (): void {
	list($service, $pending, $adapter, $conversations, $conversation_id, $created) = oras_ai_confirmation_fixture();
	$service->confirm($created['token'], $conversation_id);
	$restored = $service->for_conversation($conversation_id);
	oras_ai_assert_same('created', $restored[0]['status'], 'Created state did not restore.');
	oras_ai_assert_same(123, $restored[0]['ticket_id'], 'Created ticket ID did not restore.');
	oras_ai_assert_same(1, count(array_filter($adapter->calls, 'is_array')), 'Restore retried ticket creation.');
});
