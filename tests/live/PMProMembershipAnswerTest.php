<?php
declare(strict_types=1);

oras_ai_test('M8 Task 2 generic self-membership question uses current authenticated PMPro state', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level('Family')), $lookups));
	list($orchestrator, $provider, $retriever) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Your membership is inactive.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, "What's my membership?"));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Generic self-membership question missed live PMPro.');
	oras_ai_assert_same(array(91), $lookups, 'Generic question did not use the authorized identity.');
	oras_ai_assert_same(ORAS_AI_Retrieval_Request::INTENT_CURRENT, $retriever->requests[0]->intent(), 'Generic self-membership was not current intent.');
	oras_ai_assert_contains('active', strtolower($result->answer()), 'Current self status was not answered.');
	oras_ai_assert_not_contains('inactive', strtolower($result->answer()), 'Model contradicted current self status.');
});

oras_ai_test('M8 Task 2 inactive self membership cannot become active in final answer', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Your membership is active.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'Am I an active ORAS member?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Inactive business state was treated as failure.');
	oras_ai_assert_contains('inactive', strtolower($result->answer()), 'Inactive current state was omitted.');
	oras_ai_assert_not_contains('is active', strtolower($result->answer()), 'Model invented active membership.');
	oras_ai_assert_same(array(91), $lookups, 'Inactive state changed lookup identity.');
});

oras_ai_test('M8 Task 2 current level outranks invented and stale former levels in answer', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level('Family')), $lookups));
	$stale = oras_ai_test_answer_evidence(array(
		'source_title' => 'Old member guide',
		'relevant_text' => 'Your current membership level is Lifetime Membership.',
		'fact_key' => '',
		'fact_keys' => array('member:self:membership-level'),
	));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array($stale)),
		oras_ai_test_provider_success('Your current level is Lifetime Membership.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Current level answer failed.');
	oras_ai_assert_contains('Family', $result->answer(), 'Current PMPro level was omitted.');
	oras_ai_assert_not_contains('Lifetime Membership', $result->answer(), 'Model or stale level replaced current level.');
	$input = wp_json_encode($provider->calls[0]['context']->provider_input());
	oras_ai_assert_not_contains('Lifetime Membership', $input, 'Stale matching level reached model context.');
	oras_ai_assert_not_contains('Private billing address', $input, 'Raw PMPro billing entered model context.');
});

oras_ai_test('M8 Task 2 multiple levels disclose status but never model-select one level', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(
		oras_ai_test_pmpro_level('Family'), oras_ai_test_pmpro_level('Volunteer'),
	), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('You are an active Lifetime Member.'),
		array(),
		$service
	);
	$status = $orchestrator->answer(oras_ai_test_authorized_request(91, 'Am I an active ORAS member?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $status->status(), 'Multiple levels hid known active status.');
	oras_ai_assert_contains('active', strtolower($status->answer()), 'Known active status was omitted.');
	oras_ai_assert_not_contains('Lifetime', $status->answer(), 'Model selected an unverified level.');

	oras_ai_test_reset();
	$level = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $level->status(), 'Ambiguous level was presented as established.');
	oras_ai_assert_same(1, count($provider->calls), 'Ambiguous level reached model after the status-only answer.');
});

oras_ai_test('M8 Task 2 PMPro row carrying another user ID is never admitted', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$foreign = oras_ai_test_pmpro_level('Private other-member level');
	$foreign->user_id = 92;
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array($foreign), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Your level is Private other-member level.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Foreign PMPro row became current self membership.');
	oras_ai_assert_same(array(91), $lookups, 'Provider lookup changed identity.');
	oras_ai_assert_same(0, count($provider->calls), 'Foreign row reached answer model.');
	oras_ai_assert_not_contains('Private other-member level', wp_json_encode($result->to_array()), 'Foreign level escaped.');
});

oras_ai_test('M8 Task 2 malformed PMPro row identity cannot be coerced into self', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$malformed = oras_ai_test_pmpro_level('Private malformed level');
	$malformed->user_id = '91.5';
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array($malformed), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Private malformed level'), array(), $service);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Malformed row identity was coerced into self.');
	oras_ai_assert_same(0, count($provider->calls), 'Malformed row reached model.');
});

oras_ai_test('M8 Task 2 browser identity cannot redirect an authorized membership answer', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_default_capability'] = false;
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function (): bool { return true; }));
	$authorized = $gateway->authorize(array('nonce' => 'valid', 'user_id' => 92, 'member_id' => 92, 'question' => "What's my membership?"));
	oras_ai_assert_true($authorized instanceof ORAS_AI_Authorized_Request, 'Self membership request was denied.');
	oras_ai_assert_same(7, $authorized->user_id(), 'Browser identity replaced authenticated WordPress user.');
	$lookups = array();
	$self = oras_ai_test_pmpro_level('Family');
	$self->user_id = 7;
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array($self), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('You are inactive.'), array(), $service);
	$result = $orchestrator->answer($authorized);
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Authorized self status was unavailable.');
	oras_ai_assert_same(array(7), $lookups, 'Browser identity redirected PMPro lookup.');
	oras_ai_assert_contains('active', strtolower($result->answer()), 'Actual self status was not returned.');
	oras_ai_assert_not_contains('inactive', strtolower($result->answer()), 'Model contradicted actual self status.');
	$input = wp_json_encode($provider->calls[0]['context']->provider_input());
	foreach (array('member@example.test', 'Private billing address', 'txn_private', 'Former Member', 'private_note', '"user_id":7', '"user_id":92') as $private) {
		oras_ai_assert_not_contains($private, $input, 'Private provider or browser identity reached model context.');
	}
	oras_ai_assert_same(array(), $result->sources(), 'Member facts invented a source link.');
});

oras_ai_test('M8 Task 2 administrator allowance does not create PMPro membership', function (): void {
	oras_ai_test_reset();
	$gateway = new ORAS_AI_Request_Gateway(new ORAS_AI_PMPro_Membership_Authorizer(static function (): bool { return false; }));
	$authorized = $gateway->authorize(array('nonce' => 'valid', 'question' => 'What is my membership status?'));
	oras_ai_assert_true($authorized instanceof ORAS_AI_Authorized_Request, 'Administrator test allowance was denied.');
	oras_ai_assert_true($authorized->is_administrator(), 'Administrator allowance was not marked.');
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(), $lookups));
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('You are an active member.'), array(), $service);
	$result = $orchestrator->answer($authorized);
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Actual administrator PMPro state was not returned.');
	oras_ai_assert_same(array(7), $lookups, 'Administrator queried another identity.');
	oras_ai_assert_contains('inactive', strtolower($result->answer()), 'Administrator allowance became active membership.');
	oras_ai_assert_not_contains('is active', strtolower($result->answer()), 'Model invented active administrator membership.');
});

oras_ai_test('M8 Task 2 membership answer path has no mutation capability', function (): void {
	$registry = new ORAS_AI_Capability_Registry();
	oras_ai_assert_same(array(), $registry->identifiers(), 'A model-callable mutation tool was registered.');
	foreach (array('class-oras-ai-pmpro-context-connector.php', 'class-oras-ai-live-service.php', 'class-oras-ai-answer-orchestrator.php') as $file) {
		$source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/' . $file);
		foreach (array('pmpro_changeMembershipLevel', 'pmpro_cancelMembershipLevel', 'pmpro_cancelMembership', 'pmpro_checkout', 'pmpro_renew', 'pmpro_updateMembershipLevel', 'pmpro_setMemberOrder') as $forbidden) {
			oras_ai_assert_not_contains($forbidden, $source, 'Membership answer path gained mutation API in ' . $file);
		}
	}
});

oras_ai_test('M8 Task 2 generic self lookup does not turn membership changes into status answers', function (): void {
	$lookups = array();
	$connector = oras_ai_test_pmpro_connector(array(), $lookups);
	oras_ai_assert_true($connector->supports(oras_ai_test_pmpro_request("What's my membership?")), 'Generic self lookup was not routed.');
	foreach (array('Can I cancel my membership?', 'How do I renew my membership?', 'How do I update my membership?', 'Change my membership billing details') as $question) {
		oras_ai_assert_false($connector->supports(oras_ai_test_pmpro_request($question)), 'Mutation question was recast as self status: ' . $question);
	}
	oras_ai_assert_same(array(), $lookups, 'Route qualification called PMPro.');
});

oras_ai_test('M8 Task 2 failed PMPro cannot be replaced by model memory when event facts survive', function (): void {
	oras_ai_test_reset();
	$pmpro = new ORAS_AI_PMPro_Context_Connector(static function () { throw new RuntimeException('private member history'); });
	$event_lookups = array();
	$events = oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $event_lookups);
	$service = new ORAS_AI_Live_Service(array($pmpro, $events), new ORAS_AI_URL_Policy(array('oras.org')));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(),
		oras_ai_test_provider_success('Your membership is active and AstroBlast starts Friday.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status, and when is the next AstroBlast?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'PMPro failure erased valid event schedule.');
	oras_ai_assert_contains('could not verify', strtolower($result->answer()), 'Failed current membership was not disclosed.');
	oras_ai_assert_not_contains('membership is active', strtolower($result->answer()), 'Model memory replaced failed PMPro.');
	oras_ai_assert_contains('starts', strtolower($result->answer()), 'Valid event schedule was erased.');
	oras_ai_assert_same('https://oras.org/events/astroblast-2026/', $result->sources()[0]['canonical_url'], 'Event provenance was lost.');
	oras_ai_assert_same(1, count($provider->calls), 'Partial fact answer added model calls.');
});

oras_ai_test('M8 Task 2 current level name is sanitized before answer and model context', function (): void {
	oras_ai_test_reset();
	$lookups = array();
	$service = oras_ai_test_pmpro_service(oras_ai_test_pmpro_connector(array(oras_ai_test_pmpro_level('Family <b>Gold</b>')), $lookups));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Family <b>Gold</b>'), array(), $service);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What membership level do I have?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Sanitized level was unavailable.');
	oras_ai_assert_contains('Family Gold', $result->answer(), 'Sanitized current name was omitted.');
	oras_ai_assert_not_contains('<b>', $result->answer(), 'Raw level markup reached answer.');
	oras_ai_assert_not_contains('<b>', wp_json_encode($provider->calls[0]['context']->provider_input()), 'Raw level markup reached model.');
});

oras_ai_test('M8 Task 2 absent PMPro returns bounded current-data uncertainty without model fallback', function (): void {
	oras_ai_test_reset();
	$service = oras_ai_test_pmpro_service(new ORAS_AI_PMPro_Context_Connector());
	list($orchestrator, $provider) = oras_ai_test_answer_fixture(
		new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence(array(
			'relevant_text' => 'A copied page says your membership is active.',
			'fact_key' => '',
			'fact_keys' => array('member:self:membership-status'),
		)))),
		oras_ai_test_provider_success('You are active.'),
		array(),
		$service
	);
	$result = $orchestrator->answer(oras_ai_test_authorized_request(91, 'What is my membership status?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Absent PMPro allowed stale membership.');
	oras_ai_assert_same(0, count($provider->calls), 'Absent PMPro reached model.');
	oras_ai_assert_not_contains('active', strtolower($result->answer()), 'Absent PMPro guessed active status.');
});
