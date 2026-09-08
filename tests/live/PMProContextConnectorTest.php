<?php
declare(strict_types=1);

function oras_ai_test_pmpro_request(
	string $question,
	int $userId = 91,
	bool $isAdministrator = false,
	string $intent = 'current'
): ORAS_AI_Live_Request {
	return ORAS_AI_Live_Request::from_authorized_request(
		new ORAS_AI_Authorized_Request(
			$userId,
			$question,
			$isAdministrator ? array('public', 'members', 'admin') : array('public', 'members'),
			$isAdministrator
		),
		$intent
	);
}

function oras_ai_test_pmpro_connector($levels, array &$lookups) {
	return new ORAS_AI_PMPro_Context_Connector(
		static function ($userId) use ($levels, &$lookups) {
			$lookups[] = $userId;
			return $levels;
		},
		static function (): string {
			return '2026-09-08T12:00:00-04:00';
		}
	);
}

function oras_ai_test_pmpro_service(ORAS_AI_Live_Connector_Interface $connector): ORAS_AI_Live_Service {
	return new ORAS_AI_Live_Service(array($connector), new ORAS_AI_URL_Policy(array('oras.org')));
}

function oras_ai_test_pmpro_facts(ORAS_AI_Live_Result $result): array {
	$facts = array();
	foreach ($result->facts() as $fact) {
		$facts[$fact->fact_key()] = $fact;
	}
	return $facts;
}

function oras_ai_test_pmpro_level(string $name = 'Sustaining Member') {
	return (object) array(
		'id' => 44,
		'name' => $name,
		'user_id' => 91,
		'email' => 'member@example.test',
		'phone' => '555-0100',
		'billing_address' => 'Private billing address',
		'payment_transaction_id' => 'txn_private',
		'subscription_transaction_id' => 'sub_private',
		'gateway' => 'private_gateway',
		'historical_levels' => array('Former Member'),
		'arbitrary_usermeta' => array('private_note' => 'secret'),
	);
}

function oras_ai_test_with_pmpro_filter_sandbox(callable $callback): void {
	$hook = 'pmpro_disable_admin_membership_access';
	$previous = $GLOBALS['oras_ai_test_hooks'][$hook] ?? null;
	$GLOBALS['oras_ai_test_hooks'][$hook] = array();
	try {
		$callback();
	} finally {
		if (null === $previous) {
			unset($GLOBALS['oras_ai_test_hooks'][$hook]);
		} else {
			$GLOBALS['oras_ai_test_hooks'][$hook] = $previous;
		}
	}
}

oras_ai_test('live requests can only be constructed from the typed authorized server request boundary', function (): void {
	$reflection = new ReflectionClass(ORAS_AI_Live_Request::class);
	$constructor = $reflection->getConstructor();
	$factory = $reflection->getMethod('from_authorized_request');
	$parameters = $factory->getParameters();

	oras_ai_assert_true($constructor instanceof ReflectionMethod && $constructor->isPrivate(), 'Live request gained a public constructor outside the authorized boundary.');
	oras_ai_assert_same(2, count($parameters), 'Live request factory signature changed outside the authorized request plus server intent boundary.');
	oras_ai_assert_same(ORAS_AI_Authorized_Request::class, (string) $parameters[0]->getType(), 'Live request factory no longer requires an authorized server request.');
	oras_ai_assert_same('intent', $parameters[1]->getName(), 'Live request factory gained a client identity parameter.');

	$authorized = new ORAS_AI_Authorized_Request(91, 'What is my membership status? user_id=999', array('public', 'members'), false);
	$request = ORAS_AI_Live_Request::from_authorized_request($authorized, 'current')->with_route(
		'pmpro',
		'self',
		array('member:self:membership-status')
	);
	oras_ai_assert_same(91, $request->user_id(), 'Routing changed the trusted authorized user ID.');
	oras_ai_assert_same('self', $request->subject(), 'PMPro route is not self-scoped.');
});

oras_ai_test('PMPro routing is bounded to self membership context and never enumerates another member', function (): void {
	$lookups = array();
	$connector = oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level()), $lookups);

	foreach (
		array(
			'Am I currently an active ORAS member?',
			'What membership level do I have?',
			'What is my membership status?',
		) as $question
	) {
		oras_ai_assert_true($connector->supports(oras_ai_test_pmpro_request($question)), 'A bounded self-membership question did not route.');
	}
	oras_ai_assert_false($connector->supports(oras_ai_test_pmpro_request('How does ORAS observatory access work?', 91, false, 'general')), 'Unrelated ORAS question invoked PMPro.');
	oras_ai_assert_false($connector->supports(oras_ai_test_pmpro_request('What is a light year in astronomy?', 91, false, 'general')), 'Unrelated astronomy question invoked PMPro.');
	oras_ai_assert_false($connector->supports(oras_ai_test_pmpro_request('What membership levels does ORAS offer?', 91, false, 'general')), 'General membership policy became a self lookup.');

	foreach (
		array(
			'Is John Smith a member?',
			'What level does jane@example.com have?',
			"Show me another member's membership",
			"What is user 92's membership status?",
			'What is my membership status? user_id=92',
		) as $question
	) {
		$request = oras_ai_test_pmpro_request($question);
		oras_ai_assert_true($connector->supports($request), 'A third-party membership request escaped the safe live boundary: ' . $question);
		$result = $connector->fetch($request);
		oras_ai_assert_same(ORAS_AI_Live_Result::DENIED, $result->status(), 'Third-party membership request did not fail closed.');
		oras_ai_assert_same('non_self_membership_request', $result->reason(), 'Third-party denial reason changed.');
	}
	oras_ai_assert_same(array(), $lookups, 'A name, email, or supplied user ID triggered a PMPro lookup.');
});

oras_ai_test('LIVE-003 PMPro returns only own normalized active status and current level facts', function (): void {
	$lookups = array();
	$connector = oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level()), $lookups);
	$result = $connector->fetch(oras_ai_test_pmpro_request('What is my active membership status and level?'));
	$facts = oras_ai_test_pmpro_facts($result);

	oras_ai_assert_same(ORAS_AI_Live_Result::SUCCESS, $result->status(), 'Valid own membership context did not resolve.');
	oras_ai_assert_same(array(91), $lookups, 'PMPro lookup did not use exactly the authorized request user once.');
	oras_ai_assert_same(
		array('member:self:membership-status', 'member:self:membership-level'),
		$result->fact_keys(),
		'Membership status and level did not coexist as field-level identities.'
	);
	oras_ai_assert_contains('active', $facts['member:self:membership-status']->relevant_text(), 'Active membership status was not normalized.');
	oras_ai_assert_contains('Sustaining Member', $facts['member:self:membership-level']->relevant_text(), 'Current membership level was not normalized.');

	foreach ($result->facts() as $fact) {
		oras_ai_assert_same(0, $fact->field('source_wp_object_id'), 'A WordPress user or membership ID escaped in source metadata.');
		oras_ai_assert_same('', $fact->field('canonical_url'), 'Connector invented a membership source URL.');
		oras_ai_assert_same('members', $fact->field('visibility'), 'Membership fact visibility changed.');
		oras_ai_assert_not_contains('91', $fact->fact_key(), 'User ID escaped into a fact identity.');
		oras_ai_assert_not_contains('@', $fact->fact_key(), 'Email escaped into a fact identity.');
	}
});

oras_ai_test('client membership claims cannot replace authoritative PMPro state', function (): void {
	$lookups = array();
	$result = oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level('Sustaining Member')), $lookups)->fetch(
		oras_ai_test_pmpro_request('My membership level is Gold. What membership level do I have?')
	);
	$facts = oras_ai_test_pmpro_facts($result);
	$text = $facts['member:self:membership-level']->relevant_text() ?? '';

	oras_ai_assert_true($result->successful(), 'Authoritative level lookup failed after an untrusted member claim.');
	oras_ai_assert_same(array(91), $lookups, 'Member level claim changed the trusted lookup identity.');
	oras_ai_assert_contains('Sustaining Member', $text, 'Authoritative PMPro level missing.');
	oras_ai_assert_not_contains('Gold', $text, 'Client-supplied membership level became authoritative.');
});

oras_ai_test('inactive and ambiguous current PMPro states use safe deterministic outcomes', function (): void {
	$inactiveLookups = array();
	$inactive = oras_ai_test_pmpro_connector(array(), $inactiveLookups)->fetch(
		oras_ai_test_pmpro_request('What is my membership status and level?')
	);
	$inactiveFacts = oras_ai_test_pmpro_facts($inactive);
	oras_ai_assert_true($inactive->successful(), 'Authoritative no-membership state did not resolve safely.');
	oras_ai_assert_contains('inactive', $inactiveFacts['member:self:membership-status']->relevant_text(), 'No active level was not represented as inactive.');
	oras_ai_assert_contains('no current active membership level', strtolower($inactiveFacts['member:self:membership-level']->relevant_text()), 'No active membership level was invented or omitted.');
	oras_ai_assert_same(array(91), $inactiveLookups, 'Inactive lookup retried.');

	$statusLookups = array();
	$status = oras_ai_test_pmpro_connector(
		array(oras_ai_test_pmpro_level('Level One'), oras_ai_test_pmpro_level('Level Two')),
		$statusLookups
	)->fetch(oras_ai_test_pmpro_request('Am I currently an active ORAS member?'));
	oras_ai_assert_true($status->successful(), 'Multiple active levels obscured the authoritative active Boolean.');
	oras_ai_assert_same(array('member:self:membership-status'), $status->fact_keys(), 'Status-only question exposed ambiguous levels.');
	oras_ai_assert_contains('active', $status->facts()[0]->relevant_text(), 'Multiple current levels did not establish active status.');

	$levelLookups = array();
	$ambiguous = oras_ai_test_pmpro_connector(
		array(oras_ai_test_pmpro_level('Level One'), oras_ai_test_pmpro_level('Level Two')),
		$levelLookups
	)->fetch(oras_ai_test_pmpro_request('What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $ambiguous->status(), 'Ambiguous current level was guessed.');
	oras_ai_assert_same('membership_level_ambiguous', $ambiguous->reason(), 'Ambiguous-level reason changed.');
	oras_ai_assert_same(array(91), $levelLookups, 'Ambiguous lookup retried.');
});

oras_ai_test('administrator PMPro context remains self-only and does not infer membership from AI access', function (): void {
	$lookups = array();
	$connector = oras_ai_test_pmpro_connector(array(), $lookups);
	$result = $connector->fetch(oras_ai_test_pmpro_request('What is my membership status?', 7, true));

	oras_ai_assert_true($result->successful(), 'Administrator could not inspect own authoritative PMPro status.');
	oras_ai_assert_same(array(7), $lookups, 'Administrator lookup did not use the authenticated administrator identity.');
	oras_ai_assert_contains('inactive', $result->facts()[0]->relevant_text(), 'Administrator access was falsely represented as active membership.');

	$denied = $connector->fetch(oras_ai_test_pmpro_request('What is user 91 membership status?', 7, true));
	oras_ai_assert_same(ORAS_AI_Live_Result::DENIED, $denied->status(), 'Administrator gained member impersonation.');
	oras_ai_assert_same(array(7), $lookups, 'Administrator third-party request invoked another lookup.');
});

oras_ai_test('administrator virtual-membership filter is scoped to factual lookup and restored for every returned state', function (): void {
	oras_ai_test_with_pmpro_filter_sandbox(
		static function (): void {
			$memberObserved = null;
			$memberConnector = new ORAS_AI_PMPro_Context_Connector(
				static function () use (&$memberObserved): array {
					$memberObserved = apply_filters('pmpro_disable_admin_membership_access', false);
					return array(oras_ai_test_pmpro_level());
				}
			);
			$memberConnector->fetch(oras_ai_test_pmpro_request('What is my membership status?'));
			oras_ai_assert_same(false, $memberObserved, 'A normal member lookup changed PMPro administrator behavior.');

			foreach (
				array(
					array(oras_ai_test_pmpro_level()),
					array(),
					'malformed',
				) as $returnedState
			) {
				$duringLookup = null;
				$connector = new ORAS_AI_PMPro_Context_Connector(
					static function () use (&$duringLookup, $returnedState) {
						$duringLookup = apply_filters('pmpro_disable_admin_membership_access', false);
						return $returnedState;
					}
				);
				$connector->fetch(oras_ai_test_pmpro_request('What is my membership status?', 7, true));

				oras_ai_assert_same(true, $duringLookup, 'Administrator virtual membership was not disabled during factual lookup.');
				oras_ai_assert_same(false, apply_filters('pmpro_disable_admin_membership_access', false), 'Temporary PMPro filter leaked after a returned lookup state.');
				oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_hooks']['pmpro_disable_admin_membership_access'], 'Temporary PMPro callback remained registered after lookup.');
			}
		}
	);
});

oras_ai_test('administrator virtual-membership filter is restored when lookup throws', function (): void {
	oras_ai_test_with_pmpro_filter_sandbox(
		static function (): void {
			$duringLookup = null;
			$connector = new ORAS_AI_PMPro_Context_Connector(
				static function () use (&$duringLookup) {
					$duringLookup = apply_filters('pmpro_disable_admin_membership_access', false);
					throw new RuntimeException('private PMPro failure');
				}
			);
			$result = $connector->fetch(oras_ai_test_pmpro_request('What is my membership status?', 7, true));

			oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $result->status(), 'Thrown PMPro lookup did not retain bounded failure behavior.');
			oras_ai_assert_same(true, $duringLookup, 'Administrator virtual membership was not disabled during the throwing lookup.');
			oras_ai_assert_same(false, apply_filters('pmpro_disable_admin_membership_access', false), 'Temporary PMPro filter leaked after an exception.');
			oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_hooks']['pmpro_disable_admin_membership_access'], 'Temporary PMPro callback remained registered after an exception.');
		}
	);
});

oras_ai_test('administrator factual lookup preserves an existing caller PMPro filter', function (): void {
	oras_ai_test_with_pmpro_filter_sandbox(
		static function (): void {
			$callerFilter = static function (): bool {
				return false;
			};
			add_filter('pmpro_disable_admin_membership_access', $callerFilter, 999);

			$duringLookup = null;
			$connector = new ORAS_AI_PMPro_Context_Connector(
				static function () use (&$duringLookup): array {
					$duringLookup = apply_filters('pmpro_disable_admin_membership_access', false);
					return array();
				}
			);
			$connector->fetch(oras_ai_test_pmpro_request('What is my membership status?', 7, true));

			$registered = $GLOBALS['oras_ai_test_hooks']['pmpro_disable_admin_membership_access'];
			oras_ai_assert_same(true, $duringLookup, 'An existing later filter overrode the temporary factual-lookup safety filter.');
			oras_ai_assert_same(1, count($registered), 'Existing caller filter was removed or replaced.');
			oras_ai_assert_true($callerFilter === $registered[0]['callback'], 'Existing caller callback identity changed.');
			oras_ai_assert_same(999, $registered[0]['priority'], 'Existing caller filter priority changed.');
			oras_ai_assert_same(false, apply_filters('pmpro_disable_admin_membership_access', true), 'Existing caller filter behavior was not restored after lookup.');
		}
	);
});

oras_ai_test('LIVE-004 missing malformed and failed PMPro return bounded outcomes without retry', function (): void {
	$missing = (new ORAS_AI_PMPro_Context_Connector())->fetch(oras_ai_test_pmpro_request('What is my membership status?'));
	oras_ai_assert_same(ORAS_AI_Live_Result::UNAVAILABLE, $missing->status(), 'Missing PMPro API did not return unavailable.');
	oras_ai_assert_same('pmpro_connector_unavailable', $missing->reason(), 'Missing PMPro reason changed.');

	$malformedLookups = array();
	$malformed = oras_ai_test_pmpro_connector('not an array', $malformedLookups)->fetch(
		oras_ai_test_pmpro_request('What is my membership status?')
	);
	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $malformed->status(), 'Malformed PMPro collection did not fail safely.');
	oras_ai_assert_same('membership_data_malformed', $malformed->reason(), 'Malformed PMPro reason changed.');
	oras_ai_assert_same(array(91), $malformedLookups, 'Malformed lookup retried.');

	$recordLookups = array();
	$badRecord = oras_ai_test_pmpro_connector(array((object) array('id' => 4, 'name' => '')), $recordLookups)->fetch(
		oras_ai_test_pmpro_request('What membership level do I have?')
	);
	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $badRecord->status(), 'Malformed current membership record did not fail safely.');
	oras_ai_assert_same('membership_data_malformed', $badRecord->reason(), 'Malformed record reason changed.');

	$calls = 0;
	$failed = new ORAS_AI_PMPro_Context_Connector(
		static function () use (&$calls) {
			$calls++;
			throw new RuntimeException('private provider failure');
		}
	);
	$failure = $failed->fetch(oras_ai_test_pmpro_request('What is my membership status?'));
	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $failure->status(), 'PMPro exception escaped the connector.');
	oras_ai_assert_same('membership_lookup_failed', $failure->reason(), 'PMPro failure reason exposed provider detail.');
	oras_ai_assert_same(1, $calls, 'Failed PMPro lookup retried.');
});

oras_ai_test('LIVE-005 PMPro raw records are structurally excluded from evidence model context and sources', function (): void {
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level()), $lookups));
	$request = oras_ai_test_pmpro_request('What is my membership status and level?');
	$result = $service->query($request);
	$packet = $service->evidence_packet($result);
	$guarded = new ORAS_AI_Guarded_Request(
		new ORAS_AI_Authorized_Request(91, $request->question(), array('public', 'members'), false),
		ORAS_AI_Domain_Result::from_outcome(ORAS_AI_Domain_Result::ORAS)
	);
	$context = (new ORAS_AI_Grounded_Context_Assembler(new ORAS_AI_Source_Precedence()))->assemble($guarded, $packet, 'current');
	$serializedFacts = wp_json_encode(array_map(static function ($fact): array { return $fact->to_array(); }, $result->facts()));
	$providerInput = wp_json_encode($context->provider_input());
	$allContext = $serializedFacts . $providerInput;

	foreach (
		array(
			'member@example.test',
			'555-0100',
			'Private billing address',
			'txn_private',
			'sub_private',
			'private_gateway',
			'Former Member',
			'private_note',
			'arbitrary_usermeta',
			'raw PMPro',
		) as $forbidden
	) {
		oras_ai_assert_not_contains($forbidden, $allContext, 'Excluded PMPro data escaped normalization.');
	}
	oras_ai_assert_not_contains('"user_id":91', $allContext, 'User ID entered membership evidence or model context.');
	oras_ai_assert_not_contains('2026-09-08T12:00:00-04:00', $providerInput, 'Membership freshness entered model prose/context.');
	oras_ai_assert_same(array(), $context->source_references(), 'URL-less membership evidence produced an invented or empty source link.');
	oras_ai_assert_same(array(), (new ORAS_AI_Conversations())->normalize_source_references($context->source_references()), 'Raw membership state entered conversation source metadata.');
});

oras_ai_test('canonical URL is optional per fact while every non-empty URL remains validated', function (): void {
	$connector = new class implements ORAS_AI_Live_Connector_Interface {
		public function supports(ORAS_AI_Live_Request $request) { return true; }
		public function fetch(ORAS_AI_Live_Request $request) {
			$fact = ORAS_AI_Live_Fact::from_array(
				array(
					'fact_key' => 'member:self:membership-status',
					'source_title' => 'Current ORAS membership',
					'source_type' => 'pmpro_membership',
					'canonical_url' => '',
					'relevant_text' => 'Current membership status: active.',
					'visibility' => 'members',
					'retrieved_at' => '2026-09-08T12:00:00-04:00',
				)
			);
			return ORAS_AI_Live_Result::success(array($fact));
		}
	};
	$service = oras_ai_test_pmpro_service($connector);
	$result = $service->query(oras_ai_test_pmpro_request('What is my membership status?'));
	oras_ai_assert_true($result instanceof ORAS_AI_Live_Result && $result->successful(), 'A URL-less live fact was rejected solely for lacking a canonical URL.');

	$unsafeConnector = new class implements ORAS_AI_Live_Connector_Interface {
		public function supports(ORAS_AI_Live_Request $request) { return true; }
		public function fetch(ORAS_AI_Live_Request $request) {
			$fact = ORAS_AI_Live_Fact::from_array(
				array(
					'fact_key' => 'member:self:membership-status',
					'source_title' => 'Current ORAS membership',
					'source_type' => 'pmpro_membership',
					'canonical_url' => 'http://127.0.0.1/private',
					'relevant_text' => 'Current membership status: active.',
					'visibility' => 'members',
				)
			);
			return ORAS_AI_Live_Result::success(array($fact));
		}
	};
	$unsafe = oras_ai_test_pmpro_service($unsafeConnector)->query(oras_ai_test_pmpro_request('What is my membership status?'));
	oras_ai_assert_same(ORAS_AI_Live_Result::UNKNOWN, $unsafe->status(), 'Unsafe non-empty URL bypassed URL policy.');
	oras_ai_assert_same('unsafe_canonical_url', $unsafe->reason(), 'Unsafe non-empty URL reason changed.');
});

oras_ai_test('live PMPro facts displace only matching stale membership claims', function (): void {
	oras_ai_test_reset();
	$staleMembership = oras_ai_test_answer_evidence(
		array(
			'artifact_id' => 951,
			'source_title' => 'Old membership page',
			'relevant_text' => 'The copied page says your membership is inactive.',
			'fact_key' => '',
			'fact_keys' => array('member:self:membership-status'),
		)
	);
	$orientation = oras_ai_test_answer_evidence(
		array(
			'artifact_id' => 952,
			'source_title' => 'Observatory orientation',
			'relevant_text' => 'Members complete observatory orientation.',
			'fact_key' => '',
			'fact_keys' => array('observatory:orientation'),
		)
	);
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level()), $lookups));
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($staleMembership, $orientation)),
		oras_ai_test_provider_success('Your current membership is active.'),
		array(),
		$service
	);

	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status?'));
	$items = $provider->calls[0]['context']->evidence_packet()->items();
	$text = implode(' ', array_map(static function ($item): string { return (string) $item->field('relevant_text'); }, $items));
	$factKeys = array();
	foreach ($items as $item) {
		$factKeys = array_merge($factKeys, (array) $item->field('fact_keys'));
	}

	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Grounded own-membership answer failed.');
	oras_ai_assert_same(array(91), $lookups, 'Orchestration changed or retried trusted membership identity.');
	oras_ai_assert_same(ORAS_AI_Retrieval_Request::INTENT_CURRENT, $retriever->requests[0]->intent(), 'Self membership did not receive current intent.');
	oras_ai_assert_same(array('member:self:membership-status'), $retriever->requests[0]->fact_keys(), 'Live membership identity did not reach retrieval precedence.');
	oras_ai_assert_contains('Current membership status: active.', $text, 'Authoritative live membership status missing.');
	oras_ai_assert_not_contains('membership is inactive', $text, 'Stale matching membership claim survived precedence.');
	oras_ai_assert_contains('observatory:orientation', implode(',', $factKeys), 'Unrelated synchronized fact was displaced.');
});

oras_ai_test('PMPro failure blocks stale self state and remains isolated from unrelated stable ORAS answers', function (): void {
	oras_ai_test_reset();
	$failedLookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector('malformed', $failedLookups));
	$stale = oras_ai_test_answer_evidence(
		array(
			'relevant_text' => 'A copied page says the member is active.',
			'fact_key' => '',
			'fact_keys' => array('member:self:membership-status'),
		)
	);
	list($currentOrchestrator, $currentProvider, $currentRetriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($stale)),
		oras_ai_test_provider_success('Stale membership answer.'),
		array(),
		$service
	);
	$current = $currentOrchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $current->status(), 'Failed PMPro lookup did not produce bounded uncertainty.');
	oras_ai_assert_same(0, count($currentProvider->calls), 'Failed PMPro lookup reached the answer provider.');
	oras_ai_assert_same(0, count($currentRetriever->requests), 'Failed PMPro lookup retrieved stale membership text.');

	oras_ai_test_reset();
	list($stableOrchestrator, $stableProvider, $stableRetriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence())),
		oras_ai_test_provider_success('Members complete orientation.'),
		array(),
		$service
	);
	$stable = $stableOrchestrator->answer(oras_ai_test_authorized_request(91, 'How does ORAS observatory access work?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $stable->status(), 'PMPro failure broke an unrelated stable ORAS answer.');
	oras_ai_assert_same(1, count($stableProvider->calls), 'Unrelated stable ORAS answer did not reach provider.');
	oras_ai_assert_same(1, count($stableRetriever->requests), 'Unrelated stable ORAS answer did not use stable retrieval.');
	oras_ai_assert_same(array(91), $failedLookups, 'Unrelated stable ORAS request invoked PMPro.');
});

oras_ai_test('PMPro connector source is read-only minimized and separately registered from authorization', function (): void {
	$path = dirname(__DIR__, 2) . '/includes/class-oras-ai-pmpro-context-connector.php';
	oras_ai_assert_true(is_file($path), 'PMPro context connector production file is missing.');
	$source = (string) file_get_contents($path);
	oras_ai_assert_contains('pmpro_getMembershipLevelsForUser', $source, 'PMPro connector does not use the current-level API boundary.');
	oras_ai_assert_contains('pmpro_disable_admin_membership_access', $source, 'PMPro admin virtual-access behavior is not disabled for factual own-membership lookup.');
	oras_ai_assert_not_contains('ORAS_AI_PMPro_Membership_Authorizer', $source, 'Answer context was coupled to the M3 Boolean authorizer.');
	foreach (
		array(
			'get_user_by',
			'get_userdata',
			'WP_User_Query',
			'$wpdb',
			'update_user_meta',
			'pmpro_changeMembershipLevel',
			'wp_remote_',
			'error_log',
		) as $forbidden
	) {
		oras_ai_assert_not_contains($forbidden, $source, 'PMPro context connector contains lookup expansion, mutation, remote access, or routine logging.');
	}

	$bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/oras-ai-assistant.php');
	oras_ai_assert_contains("require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-pmpro-context-connector.php';", $bootstrap, 'PMPro context connector is not loaded by the plugin.');
	oras_ai_assert_contains('new ORAS_AI_PMPro_Context_Connector()', $bootstrap, 'PMPro context connector is not registered with the live service.');
});
