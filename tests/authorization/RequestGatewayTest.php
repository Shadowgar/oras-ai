<?php
declare(strict_types=1);

function oras_ai_test_gateway_with_eligibility(bool $eligible, array &$checked_user_ids) {
	$authorizer = new ORAS_AI_PMPro_Membership_Authorizer(
		static function ($levels, $user_id) use ($eligible, &$checked_user_ids) {
			$checked_user_ids[] = (int) $user_id;
			return $eligible;
		}
	);

	return new ORAS_AI_Request_Gateway($authorizer);
}

function oras_ai_test_gateway_payload(array $extra = array()): array {
	return array_merge(
		array(
			'nonce'    => 'valid-member-request-nonce',
			'question' => 'When may members use the observatory?',
		),
		$extra
	);
}

oras_ai_test('AT-AUTH-001 anonymous request is denied before nonce membership or provider work', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$result = $gateway->authorize(oras_ai_test_gateway_payload());

	oras_ai_assert_wp_error($result, 'oras_ai_request_denied', 'Anonymous request must be denied.');
	oras_ai_assert_same(array(), $checked_user_ids, 'Anonymous request must not query membership.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_nonce_verifications'], 'Anonymous request must stop before nonce processing.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Anonymous request must not invoke any provider.');
});

oras_ai_test('AT-AUTH-002 active member receives server-derived member visibility', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 17;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$result = $gateway->authorize(oras_ai_test_gateway_payload(array('question' => '  Observatory access?  ')));

	oras_ai_assert_true($result instanceof ORAS_AI_Authorized_Request, 'Active member should receive an authorized request.');
	oras_ai_assert_same(17, $result->user_id(), 'Authorized identity must come from the WordPress session.');
	oras_ai_assert_same('Observatory access?', $result->question(), 'Question should trim harmless surrounding whitespace.');
	oras_ai_assert_same(array('public', 'members'), $result->allowed_visibilities(), 'Member visibility must be derived server-side.');
	oras_ai_assert_false($result->is_administrator(), 'Member request must not be marked administrative.');
	oras_ai_assert_same(array(17), $checked_user_ids, 'Membership must be checked for the authenticated user only.');
	oras_ai_assert_same(
		array(array('valid-member-request-nonce', ORAS_AI_Request_Gateway::NONCE_ACTION)),
		$GLOBALS['oras_ai_test_nonce_verifications'],
		'Gateway must verify its action-specific nonce.'
	);
});

oras_ai_test('AT-AUTH-003 inactive member is denied and browser membership claims cannot bypass policy', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 23;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(false, $checked_user_ids);

	$result = $gateway->authorize(
		oras_ai_test_gateway_payload(
			array(
				'membership_active' => true,
				'membership_level'  => 'owner',
				'is_admin'           => true,
			)
		)
	);

	oras_ai_assert_wp_error($result, 'oras_ai_request_denied', 'Inactive member must be denied.');
	oras_ai_assert_same('Request denied.', $result->get_error_message(), 'Client denial must not disclose membership or capability details.');
	oras_ai_assert_same(array(23), $checked_user_ids, 'Only server-derived identity may be checked.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Denied member must not invoke any provider.');
});

oras_ai_test('AT-AUTH-004 browser identity and visibility claims cannot impersonate or elevate', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 31;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$result = $gateway->authorize(
		oras_ai_test_gateway_payload(
			array(
				'user_id'              => 99,
				'username'             => 'other-user',
				'email'                => 'other@example.test',
				'allowed_visibilities' => array('public', 'members', 'admin'),
				'visibility'           => 'admin',
			)
		)
	);

	oras_ai_assert_true($result instanceof ORAS_AI_Authorized_Request, 'Actual active member should remain authorized.');
	oras_ai_assert_same(31, $result->user_id(), 'Browser-supplied user ID must be ignored.');
	oras_ai_assert_same(array(31), $checked_user_ids, 'Another user membership must never be queried.');
	oras_ai_assert_same(array('public', 'members'), $result->allowed_visibilities(), 'Browser visibility must not elevate an active member.');
});

oras_ai_test('administrator capability allows access without querying membership and derives admin visibility', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_current_user_id'] = 7;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(false, $checked_user_ids);

	$result = $gateway->authorize(oras_ai_test_gateway_payload());

	oras_ai_assert_true($result instanceof ORAS_AI_Authorized_Request, 'Administrator should be allowed without active membership.');
	oras_ai_assert_true($result->is_administrator(), 'Administrator state must come from the server capability.');
	oras_ai_assert_same(array('public', 'members', 'admin'), $result->allowed_visibilities(), 'Administrator visibility mismatch.');
	oras_ai_assert_same(array(), $checked_user_ids, 'Administrator allowance should not expose or query membership details.');
});

oras_ai_test('global member AI kill switch denies members and administrators after authorization', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(false);
	$GLOBALS['oras_ai_test_current_user_id'] = 41;
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$member_result = $gateway->authorize(oras_ai_test_gateway_payload());
	oras_ai_assert_wp_error($member_result, 'oras_ai_request_denied', 'Kill switch must deny an otherwise eligible member.');
	oras_ai_assert_same(array(41), $checked_user_ids, 'Configured eligibility should be evaluated before the global execution switch.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	$admin_result = $gateway->authorize(oras_ai_test_gateway_payload());
	oras_ai_assert_wp_error($admin_result, 'oras_ai_request_denied', 'Kill switch has no frozen administrator bypass.');
	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_remote_calls'], 'Kill-switch denial must not invoke a provider.');
});

oras_ai_test('PMPro adapter answers only whether the specified user has any active level', function (): void {
	$checks = array();
	$adapter = new ORAS_AI_PMPro_Membership_Authorizer(
		static function ($levels, $user_id) use (&$checks) {
			$checks[] = array($levels, $user_id);
			return 55 === $user_id ? array('private-level-detail') : false;
		}
	);

	oras_ai_assert_same(true, $adapter->has_active_membership(55), 'Any active PMPro level should authorize.');
	oras_ai_assert_same(false, $adapter->has_active_membership(56), 'No active PMPro level should deny.');
	oras_ai_assert_same(array(array(null, 55), array(null, 56)), $checks, 'PMPro any-level API arguments changed.');
});

oras_ai_test('missing PMPro fails closed for a normal member without fatal error', function (): void {
	oras_ai_assert_false(function_exists('pmpro_hasMembershipLevel'), 'PMPro test environment unexpectedly defines the integration function.');
	$adapter = new ORAS_AI_PMPro_Membership_Authorizer();

	oras_ai_assert_same(false, $adapter->has_active_membership(7), 'Missing PMPro must fail closed.');
});

oras_ai_test('request gateway rejects missing and invalid nonce before membership work', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$missing = $gateway->authorize(array('question' => 'Valid question'));
	oras_ai_assert_wp_error($missing, 'oras_ai_request_denied', 'Missing nonce must be denied.');

	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$invalid = $gateway->authorize(oras_ai_test_gateway_payload(array('nonce' => 'invalid')));
	oras_ai_assert_wp_error($invalid, 'oras_ai_request_denied', 'Invalid nonce must be denied.');
	oras_ai_assert_same(array(), $checked_user_ids, 'Nonce failures must stop before membership work.');
	oras_ai_assert_same(
		array(array('invalid', ORAS_AI_Request_Gateway::NONCE_ACTION)),
		$GLOBALS['oras_ai_test_nonce_verifications'],
		'Invalid nonce must be checked against the gateway action.'
	);
});

oras_ai_test('request gateway trims valid string input and rejects empty or non-string input', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	$valid = $gateway->authorize(oras_ai_test_gateway_payload(array('question' => " \tWhat\\'s a valid question?\n")));
	oras_ai_assert_true($valid instanceof ORAS_AI_Authorized_Request, 'Valid string input should be accepted.');
	oras_ai_assert_same("What's a valid question?", $valid->question(), 'Valid input should be unslashed and trimmed.');

	foreach (array('', '   ', array('not-a-string'), (object) array('question' => 'not-a-string')) as $question) {
		$result = $gateway->authorize(oras_ai_test_gateway_payload(array('question' => $question)));
		oras_ai_assert_wp_error($result, 'oras_ai_invalid_request', 'Malformed question must fail safely.');
	}
});

oras_ai_test('authenticated AJAX gateway registers without an anonymous hook and returns minimal safe data', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked_user_ids = array();
	$gateway = oras_ai_test_gateway_with_eligibility(true, $checked_user_ids);

	oras_ai_assert_true(oras_ai_hook_registered('wp_ajax_oras_ai_member_request'), 'Authenticated gateway hook missing.');
	oras_ai_assert_false(oras_ai_hook_registered('wp_ajax_nopriv_oras_ai_member_request'), 'Anonymous gateway hook must not exist.');
	$_POST = oras_ai_test_gateway_payload(
		array(
			'user_id'              => 99,
			'allowed_visibilities' => array('admin'),
		)
	);

	try {
		$gateway->handle_ajax_request();
		throw new RuntimeException('Expected intercepted JSON response.');
	} catch (ORAS_AI_Test_Json_Response $response) {
		oras_ai_assert_true($response->success, 'Authorized gateway request should return success.');
		oras_ai_assert_same(200, $response->status, 'Authorized gateway status changed.');
		oras_ai_assert_same(array('authorized' => true), $response->data, 'Endpoint must return only minimal authorization state.');
		oras_ai_assert_not_contains('99', wp_json_encode($response->data), 'Response must not expose client identity claims.');
		oras_ai_assert_not_contains('question', wp_json_encode($response->data), 'Endpoint must not echo request text.');
	}
});

oras_ai_test('Task 2 gateway has no retrieval model domain quota or connector dependency', function (): void {
	$path = dirname(__DIR__, 2) . '/includes/class-oras-ai-request-gateway.php';
	oras_ai_assert_true(file_exists($path), 'Request gateway production file missing.');
	$source = (string) file_get_contents($path);

	foreach (array('ORAS_AI_Retriever', 'ORAS_AI_OpenAI', 'Domain_Guard', 'Quota', 'Usage_Ledger', 'wp_remote_') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $source, 'Task 2 gateway crossed a later milestone boundary.');
	}
});

oras_ai_test('Task 5 authenticated endpoint returns structured backend answer without chat UI', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$checked = array();
	$packet = new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence()));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture($packet, oras_ai_test_provider_success('Grounded endpoint answer.'));
	$authorizer = new ORAS_AI_PMPro_Membership_Authorizer(static function () use (&$checked) { $checked[] = get_current_user_id(); return true; });
	$gateway = new ORAS_AI_Request_Gateway($authorizer, $orchestrator);
	$_POST = oras_ai_test_gateway_payload(array('question' => 'How does ORAS observatory access work?'));

	try {
		$gateway->handle_ajax_request();
		throw new RuntimeException('Expected JSON interception.');
	} catch (ORAS_AI_Test_Json_Response $response) {
		oras_ai_assert_true($response->success, 'Handled answer should use successful JSON transport.');
		oras_ai_assert_same(200, $response->status, 'Answer endpoint status changed.');
		oras_ai_assert_same('success', $response->data['status'], 'Structured answer status missing.');
		oras_ai_assert_same('Grounded endpoint answer.', $response->data['answer'], 'Structured answer text missing.');
		oras_ai_assert_same(1, count($provider->calls), 'Authorized endpoint should call provider once.');
		oras_ai_assert_false(array_key_exists('html', $response->data), 'Task 5 endpoint must not return rendered chat HTML.');
	}
});

oras_ai_test('unauthorized invalid-nonce and kill-switch endpoint requests never call answer provider', function (): void {
	$cases = array('anonymous', 'bad_nonce', 'kill_switch');
	foreach ($cases as $case) {
		oras_ai_test_reset();
		$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
		$packet = new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence()));
		list($orchestrator, $provider) = oras_ai_test_answer_fixture($packet, oras_ai_test_provider_success());
		$checked = array();
		$gateway = oras_ai_test_gateway_with_eligibility(true, $checked);
		$gateway = new ORAS_AI_Request_Gateway(
			new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }),
			$orchestrator
		);
		$_POST = oras_ai_test_gateway_payload();
		if ('anonymous' === $case) {
			$GLOBALS['oras_ai_test_current_user_id'] = 0;
		} elseif ('bad_nonce' === $case) {
			$GLOBALS['oras_ai_test_nonce_valid'] = false;
		} else {
			ORAS_AI_Config::set_member_ai_enabled(false);
		}

		try {
			$gateway->handle_ajax_request();
			throw new RuntimeException('Expected denied JSON interception.');
		} catch (ORAS_AI_Test_Json_Response $response) {
			oras_ai_assert_false($response->success, 'Denied endpoint request must return error transport.');
			oras_ai_assert_same(0, count($provider->calls), $case . ' request reached answer provider.');
		}
	}
});

oras_ai_test('NFR-PRIV-004 gateway rejects payment-card input before answer-provider dispatch without logging it', function (): void {
	oras_ai_test_reset();
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$packet = new ORAS_AI_Evidence_Packet(array(oras_ai_test_answer_evidence()));
	list($orchestrator, $provider) = oras_ai_test_answer_fixture($packet, oras_ai_test_provider_success());
	$gateway = new ORAS_AI_Request_Gateway(
		new ORAS_AI_PMPro_Membership_Authorizer(static function () { return true; }),
		$orchestrator
	);
	$card = '4111 1111 1111 1111';
	$_POST = oras_ai_test_gateway_payload(array('question' => 'my card number is ' . $card));

	try {
		$gateway->handle_ajax_request();
		throw new RuntimeException('Expected sensitive-input JSON rejection.');
	} catch (ORAS_AI_Test_Json_Response $response) {
		oras_ai_assert_false($response->success, 'Card input must use error transport.');
		oras_ai_assert_same(400, $response->status, 'Sensitive-input response status changed.');
		oras_ai_assert_same(0, count($provider->calls), 'Card input reached the answer provider.');
		oras_ai_assert_not_contains($card, wp_json_encode($response->data), 'Sensitive response echoed the card number.');
		oras_ai_assert_not_contains($card, serialize($GLOBALS['oras_ai_test_options']), 'Card input appeared in stored logs or options.');
	}
});
