<?php
declare(strict_types=1);

final class ORAS_AI_Test_Fluent_Support_Gateway {
	public array $versions = array('core' => '2.4.0', 'pro' => '2.4.0', 'capable' => true);
	public array $customers = array();
	public array $mailboxes = array(1 => true);
	public array $tags = array(2 => true);
	public array $calls = array();
	public $ticket_result;
	public bool $throw_on_create = false;
	public bool $throw_on_customer_create = false;
	public bool $throw_on_tags = false;
	public bool $throw_on_lookup = false;
	public bool $current_user_is_agent = false;
	public $customer_result = null;

	public function __construct() {
		$this->ticket_result = (object) array('id' => 41);
	}

	public function versions(): array { return $this->versions; }
	public function customers_by_user_id(int $id): array {
		if ($this->throw_on_lookup) { throw new RuntimeException('private customer detail'); }
		return array_values(array_filter($this->customers, static function ($customer) use ($id): bool { return (int) $customer->user_id === $id; }));
	}
	public function customers_by_email(string $email): array {
		if ($this->throw_on_lookup) { throw new RuntimeException('private customer detail'); }
		return array_values(array_filter($this->customers, static function ($customer) use ($email): bool { return strcasecmp((string) $customer->email, $email) === 0; }));
	}
	public function create_customer(array $data) {
		$this->calls[] = array('create_customer', $data);
		if ($this->throw_on_customer_create) { throw new RuntimeException('private customer create detail'); }
		if (null !== $this->customer_result) { return $this->customer_result; }
		$customer = (object) array('id' => 77, 'user_id' => $data['user_id'], 'email' => $data['email']);
		$this->customers[] = $customer;
		return $customer;
	}
	public function mailbox(int $id): bool { return !empty($this->mailboxes[$id]); }
	public function tag(int $id): bool { return !empty($this->tags[$id]); }
	public function current_user_is_agent(): bool { return $this->current_user_is_agent; }
	public function create_ticket(array $data) {
		$this->calls[] = array('create_ticket', $data);
		if ($this->throw_on_create) { throw new RuntimeException('private ticket detail'); }
		return $this->ticket_result;
	}
	public function apply_tags($ticket, array $ids): bool {
		$this->calls[] = array('apply_tags', $ids);
		if ($this->throw_on_tags) { throw new RuntimeException('private tag detail'); }
		return true;
	}
}

function oras_ai_fs_adapter(?ORAS_AI_Test_Fluent_Support_Gateway $gateway = null): ORAS_AI_Fluent_Support_Adapter {
	return new ORAS_AI_Fluent_Support_Adapter($gateway ?? new ORAS_AI_Test_Fluent_Support_Gateway());
}

function oras_ai_fs_user(int $id = 7, string $email = 'member@example.invalid'): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = $id;
	$GLOBALS['oras_ai_test_users'][$id] = (object) array('ID' => $id, 'user_email' => $email, 'first_name' => 'Test', 'last_name' => 'Member');
}

function oras_ai_fs_customer(int $id, int $user_id, string $email): object {
	return (object) array('id' => $id, 'user_id' => $user_id, 'email' => $email);
}

function oras_ai_fs_ticket_gateway(): ORAS_AI_Test_Fluent_Support_Gateway {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customers[] = oras_ai_fs_customer(21, 7, 'member@example.invalid');
	return $gateway;
}

oras_ai_test('M7 status recognizes core and optional Pro without exposing provider objects', function (): void {
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$result = oras_ai_fs_adapter($gateway)->status();
	oras_ai_assert_same('available', $result->status(), 'Qualified provider must be available.');
	oras_ai_assert_same('available', $result->pro_status(), 'Qualified Pro must be reported.');
	$gateway->versions['pro'] = null;
	$result = oras_ai_fs_adapter($gateway)->status();
	oras_ai_assert_same('available', $result->status(), 'Optional Pro absence must not break core bridge.');
	oras_ai_assert_same('missing', $result->pro_status(), 'Optional Pro absence was hidden.');
	oras_ai_assert_false(is_array($result), 'Status leaked a raw array.');
});

oras_ai_test('M7 status fails closed for missing or incompatible core and missing capabilities', function (): void {
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->versions['core'] = null;
	oras_ai_assert_same('missing', oras_ai_fs_adapter($gateway)->status()->status(), 'Missing core was accepted.');
	$gateway->versions['core'] = '2.5.0';
	oras_ai_assert_same('incompatible', oras_ai_fs_adapter($gateway)->status()->status(), 'Untested core was accepted.');
	$gateway->versions['core'] = '2.4.0';
	$gateway->versions['capable'] = false;
	oras_ai_assert_same('incompatible', oras_ai_fs_adapter($gateway)->status()->status(), 'Missing API capability was accepted.');
	$gateway->versions['capable'] = true;
	$gateway->versions['pro'] = '2.5.0';
	oras_ai_assert_same('incompatible', oras_ai_fs_adapter($gateway)->status()->status(), 'Untested active Pro was accepted.');
});

oras_ai_test('M7 customer lookup prefers authorized user linkage and does not create during read-only lookup', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customers[] = oras_ai_fs_customer(21, 7, 'old@example.invalid');
	$adapter = oras_ai_fs_adapter($gateway);
	$result = $adapter->resolve_confirmed_customer(7);
	oras_ai_assert_same('customer_resolved', $result->status(), 'Linked customer was not reused.');
	oras_ai_assert_same(21, $result->id(), 'Wrong customer resolved.');
	oras_ai_assert_same(array(), $gateway->calls, 'Read-only customer lookup mutated provider state.');
});

oras_ai_test('M7 customer identity rejects a caller-selected different user and invalid email', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	oras_ai_assert_same('customer_failure', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(8, true)->status(), 'Caller selected another user.');
	$GLOBALS['oras_ai_test_users'][7]->user_email = 'invalid';
	oras_ai_assert_same('invalid_email', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Invalid email was accepted.');
	oras_ai_assert_same(array(), $gateway->calls, 'Invalid identity caused provider mutation.');
});

oras_ai_test('M7 customer email fallback cannot claim an unlinked row by editable WordPress email', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customers[] = oras_ai_fs_customer(22, 0, 'member@example.invalid');
	oras_ai_assert_same('customer_failure', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7)->status(), 'Read-only lookup linked an unverified email row.');
	$result = oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true);
	oras_ai_assert_same('customer_failure', $result->status(), 'Confirmed flow claimed an unlinked email row without verification.');
	oras_ai_assert_same('unlinked_customer', $result->reason(), 'Unlinked customer was not identified safely.');
	oras_ai_assert_same(0, $gateway->customers[0]->user_id, 'Email fallback modified the customer linkage.');
	oras_ai_assert_same(array(), $gateway->calls, 'Email fallback attempted a provider write.');
});

oras_ai_test('M7 email lookup may corroborate the same already-linked customer', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customers[] = oras_ai_fs_customer(22, 7, 'member@example.invalid');
	$result = oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true);
	oras_ai_assert_same('customer_resolved', $result->status(), 'Matching email rejected the authorized linked customer.');
	oras_ai_assert_same(22, $result->id(), 'Wrong linked customer returned.');
	oras_ai_assert_same(array(), $gateway->calls, 'Matching email caused a provider write.');
});

oras_ai_test('M7 customer lookup rejects duplicate and conflicting identity rows', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customers = array(oras_ai_fs_customer(22, 0, 'member@example.invalid'), oras_ai_fs_customer(23, 0, 'member@example.invalid'));
	oras_ai_assert_same('ambiguous_customer', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Duplicate email rows were accepted.');
	$gateway->customers = array(oras_ai_fs_customer(24, 8, 'member@example.invalid'));
	oras_ai_assert_same('conflicting_customer', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Another user linkage was accepted.');
	$gateway->customers = array(oras_ai_fs_customer(25, 7, 'other@example.invalid'), oras_ai_fs_customer(26, 8, 'member@example.invalid'));
	oras_ai_assert_same('conflicting_customer', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Conflicting current email was accepted.');
	$gateway->customers = array(oras_ai_fs_customer(25, 7, 'other@example.invalid'), oras_ai_fs_customer(27, 7, 'previous@example.invalid'));
	oras_ai_assert_same('ambiguous_customer', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Duplicate user links were accepted.');
});

oras_ai_test('M7 confirmed customer creation disables WordPress user creation', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	oras_ai_assert_same('customer_failure', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7)->status(), 'Read-only lookup created customer.');
	$result = oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true);
	oras_ai_assert_same('customer_created', $result->status(), 'Confirmed flow did not create customer.');
	oras_ai_assert_same(77, $result->id(), 'Created customer ID was not normalized.');
	oras_ai_assert_same(false, $gateway->calls[0][1]['create_wp_user'], 'WP-user creation was not explicitly disabled.');
	$gateway->throw_on_lookup = true;
	oras_ai_assert_same('provider_error', oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true)->reason(), 'Provider lookup exception leaked.');
});

oras_ai_test('M7 customer create false and exception stay bounded and are never retried', function (): void {
	oras_ai_fs_user();
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$gateway->customer_result = false;
	$result = oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true);
	oras_ai_assert_same('customer_creation_uncertain', $result->reason(), 'False customer create was treated as success.');
	oras_ai_assert_same(1, count($gateway->calls), 'Customer create was retried.');
	$gateway->throw_on_customer_create = true;
	$result = oras_ai_fs_adapter($gateway)->resolve_confirmed_customer(7, true);
	oras_ai_assert_same('provider_error', $result->reason(), 'Customer create exception escaped.');
	oras_ai_assert_not_contains('private customer create detail', $result->reason(), 'Provider exception leaked.');
	oras_ai_assert_same(2, count($gateway->calls), 'Exception retried customer create.');
});

oras_ai_test('M7 route requires explicit existing mailbox and bounded existing tags', function (): void {
	$gateway = new ORAS_AI_Test_Fluent_Support_Gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	oras_ai_assert_same('route_invalid', $adapter->validate_provider_route(null, array())->status(), 'Missing mailbox silently defaulted.');
	oras_ai_assert_same('route_invalid', $adapter->validate_provider_route('1oops', array())->status(), 'Malformed mailbox accepted.');
	oras_ai_assert_same('route_invalid', $adapter->validate_provider_route(9, array())->status(), 'Missing mailbox accepted.');
	oras_ai_assert_same('route_invalid', $adapter->validate_provider_route(1, array(4))->status(), 'Missing tag accepted.');
	$result = $adapter->validate_provider_route('1', array('2', 2));
	oras_ai_assert_same('route_valid', $result->status(), 'Existing route was rejected.');
	oras_ai_assert_same(1, $result->route()->mailbox_id(), 'Mailbox ID was not normalized.');
	oras_ai_assert_same(array(2), $result->route()->tag_ids(), 'Tag IDs were not deduplicated.');
});

oras_ai_test('M7 stale route fails before provider create attempt', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array(2))->route();
	$gateway->mailboxes = array();
	oras_ai_assert_same('ticket_failed', $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body')->status(), 'Stale mailbox reached provider create.');
	oras_ai_assert_same(array(), $gateway->calls, 'Stale route called provider create.');
});

oras_ai_test('M7 create uses one core ticket call with safe content and no agent or mail call', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array(2))->route();
	$result = $adapter->create_confirmed_ticket(21, $route, '<b>Help</b>', '<script>bad()</script>Safe <b>text</b>');
	oras_ai_assert_same('ticket_created', $result->status(), 'Confirmed ticket was not created.');
	oras_ai_assert_same(41, $result->id(), 'Ticket ID was not normalized.');
	oras_ai_assert_same(1, count(array_filter($gateway->calls, static function ($call): bool { return $call[0] === 'create_ticket'; })), 'Ticket was created more than once.');
	$payload = $gateway->calls[0][1];
	oras_ai_assert_same(21, $payload['customer_id'], 'Verified customer ID absent.');
	oras_ai_assert_same(1, $payload['mailbox_id'], 'Explicit mailbox absent.');
	oras_ai_assert_same('Help', $payload['title'], 'Subject not sanitized.');
	oras_ai_assert_not_contains('<', $payload['content'], 'HTML reached provider payload.');
	oras_ai_assert_contains('Submitted through ORAS AI Assistant', $payload['content'], 'Truthful provenance missing.');
	oras_ai_assert_false(array_key_exists('agent_id', $payload) || array_key_exists('created_by', $payload), 'Fake agent field set.');
	oras_ai_assert_same('apply_tags', $gateway->calls[1][0], 'Tags were not attached after creation.');
});

oras_ai_test('M7 create rejects invalid input before attempting provider call', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array())->route();
	foreach (array(array(0, 'Help', 'Body'), array(21, '', 'Body'), array(21, 'Help', '<p></p>'), array(21, str_repeat('X', 193), 'Body'), array(21, 'Help', str_repeat('X', 65536))) as $input) {
		oras_ai_assert_same('ticket_failed', $adapter->create_confirmed_ticket($input[0], $route, $input[1], $input[2])->status(), 'Invalid input reached provider.');
	}
	oras_ai_assert_same(array(), $gateway->calls, 'Validation made a provider create attempt.');
});

oras_ai_test('M7 encoded markup cannot become active markup after provider entity decoding', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array())->route();
	$result = $adapter->create_confirmed_ticket(21, $route, 'Help', '&lt;script&gt;alert(1)&lt;/script&gt; Safe');
	oras_ai_assert_same('ticket_created', $result->status(), 'Entity-encoded text was unexpectedly rejected.');
	oras_ai_assert_not_contains('&lt;script', $gateway->calls[0][1]['content'], 'Encoded markup reached the provider decoder.');
	oras_ai_assert_not_contains('<script', $gateway->calls[0][1]['content'], 'Active markup reached the provider.');
});

oras_ai_test('M7 ticket false is definite failure while null and thrown create are uncertain', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array())->route();
	$gateway->ticket_result = false;
	oras_ai_assert_same('ticket_failed', $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body')->status(), 'Provider false was not bounded.');
	$gateway->ticket_result = null;
	oras_ai_assert_same('ticket_uncertain', $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body')->status(), 'Provider null treated as safe failure.');
	$gateway->throw_on_create = true;
	$result = $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body');
	oras_ai_assert_same('ticket_uncertain', $result->status(), 'Post-insert exception treated as failure.');
	oras_ai_assert_not_contains('private ticket detail', $result->reason(), 'Raw exception leaked.');
	oras_ai_assert_same(3, count($gateway->calls), 'Adapter retried an uncertain create.');
});

oras_ai_test('M7 tag failure preserves authoritative ticket ID without another create', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$gateway->throw_on_tags = true;
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array(2))->route();
	$result = $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body');
	oras_ai_assert_same('ticket_created', $result->status(), 'Tag error hid a created ticket.');
	oras_ai_assert_same(41, $result->id(), 'Tag error discarded ticket ID.');
	oras_ai_assert_same('tag_attachment_uncertain', $result->reason(), 'Partial routing failure was hidden.');
	oras_ai_assert_same(2, count($gateway->calls), 'Tag failure triggered duplicate create.');
});

oras_ai_test('M7 ticket customer must still belong to current authorized WordPress user', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array())->route();
	oras_ai_assert_same('ticket_failed', $adapter->create_confirmed_ticket(99, $route, 'Help', 'Body')->status(), 'Arbitrary customer ID reached provider.');
	$GLOBALS['oras_ai_test_current_user_id'] = 8;
	oras_ai_assert_same('ticket_failed', $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body')->status(), 'Changed current user reused another member customer.');
	oras_ai_assert_same(array(), $gateway->calls, 'Rejected identity reached createTicket.');
});

oras_ai_test('M7 member ticket path rejects an agent session before core can set created_by', function (): void {
	$gateway = oras_ai_fs_ticket_gateway();
	$gateway->current_user_is_agent = true;
	$adapter = oras_ai_fs_adapter($gateway);
	$route = $adapter->validate_provider_route(1, array())->route();
	$result = $adapter->create_confirmed_ticket(21, $route, 'Help', 'Body');
	oras_ai_assert_same('ticket_failed', $result->status(), 'Agent session created a member-context ticket.');
	oras_ai_assert_same('agent_session_unsupported', $result->reason(), 'Agent-specific failure was not bounded.');
	oras_ai_assert_same(array(), $gateway->calls, 'Agent session reached core ticket creation.');
});
