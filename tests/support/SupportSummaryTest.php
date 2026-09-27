<?php
declare(strict_types=1);

final class ORAS_AI_Test_Summary_Provider {
	public array $calls = array();
	public $response;
	public function __construct( $summary = 'The member needs clarification about renewing ORAS membership.' ) {
		$this->response = ORAS_AI_Provider_Answer::success( $summary, 'gpt-5.6-luna', 35, 18 );
	}
	public function model() { return 'gpt-5.6-luna'; }
	public function summarize_support_question( $question, $tokens, $timeout ) {
		$this->calls[] = array( $question, $tokens, $timeout );
		return $this->response;
	}
}

function oras_ai_test_summary_fixture( $provider = null, array $overrides = array() ): array {
	oras_ai_test_reset();
	$provider = $provider ?: new ORAS_AI_Test_Summary_Provider();
	$config = ORAS_AI_Cost_Config::defaults();
	$config['pricing'] = array( 'gpt-5.6-luna' => array(
		'input_microdollars_per_million_tokens' => 1000000,
		'output_microdollars_per_million_tokens' => 3000000,
		'unit' => 'per_million_tokens',
	) );
	$config = array_replace( $config, $overrides );
	$ledger = new ORAS_AI_Usage_Ledger();
	$service = new ORAS_AI_Support_Summary_Service( $provider, new ORAS_AI_Execution_Controls( $ledger, $config ), $ledger );
	return array( $service, $provider, $ledger );
}

oras_ai_test('SUP-004 dedicated summary uses one metered bounded model call', function (): void {
	list( $service, $provider, $ledger ) = oras_ai_test_summary_fixture();
	$request = oras_ai_test_authorized_request( 7, 'What are the ORAS membership renewal rules?' );
	$result = $service->generate( $request, $request->question() );
	oras_ai_assert_same( 'generated', $result['status'], 'Summary was not generated.' );
	oras_ai_assert_same( 'The member needs clarification about renewing ORAS membership.', $result['summary'], 'Model summary was not preserved.' );
	oras_ai_assert_same( 1, count( $provider->calls ), 'Support proposal made other than one model call.' );
	oras_ai_assert_same( $request->question(), $provider->calls[0][0], 'Summary sent unrelated conversation context.' );
	oras_ai_assert_true( $provider->calls[0][1] <= 160 && $provider->calls[0][2] <= 10, 'Provider bounds exceeded.' );
	oras_ai_assert_same( 'reconciled', $ledger->reservation( 'oras-ai-0000000001' )['status'], 'Summary cost was not reconciled.' );
});

oras_ai_test('SUP-004 invalid duplicate HTML empty oversized and malformed summaries fail closed', function (): void {
	$question = 'What are the ORAS membership renewal rules?';
	$cases = array(
		$question,
		'  MEMBER ASKS FOR HELP:  ' . strtoupper( $question ),
		'Some text ' . $question,
		'Member asks for help: ' . $question,
		str_repeat( 'x', 601 ),
		'The member asks about renewal; customer_id 53 is active.',
		'The member asks whether renewal costs $99.',
		'The member asks about &lt;b&gt;renewal&lt;/b&gt;.',
	);
	foreach ( $cases as $summary ) {
		$provider = new ORAS_AI_Test_Summary_Provider( $summary );
		list( $service, $provider ) = oras_ai_test_summary_fixture( $provider );
		$result = $service->generate( oras_ai_test_authorized_request( 7, $question ), $question );
		oras_ai_assert_same( 'unavailable', $result['status'], 'Invalid summary accepted.' );
	}
	foreach ( array( '', '   ' ) as $empty ) {
		$provider = new ORAS_AI_Test_Summary_Provider();
		$provider->response = ORAS_AI_Provider_Answer::success( $empty, 'gpt-5.6-luna', 35, 18 );
		list( $service ) = oras_ai_test_summary_fixture( $provider );
		oras_ai_assert_same( 'unavailable', $service->generate( oras_ai_test_authorized_request( 7, $question ), $question )['status'], 'Empty summary accepted.' );
	}
});

oras_ai_test('SUP-004 burst denial blocks the second summary call', function (): void {
	list( $service, $provider ) = oras_ai_test_summary_fixture( null, array( 'burst_per_minute' => 1 ) );
	$request = oras_ai_test_authorized_request( 7, 'What are the ORAS membership renewal rules?' );
	oras_ai_assert_same( 'generated', $service->generate( $request, $request->question() )['status'], 'First summary failed.' );
	oras_ai_assert_same( 'unavailable', $service->generate( $request, $request->question() )['status'], 'Burst limit bypassed.' );
	oras_ai_assert_same( 1, count( $provider->calls ), 'Burst-denied summary reached model.' );
});

oras_ai_test('SUP-004 monthly quota denies a later-day summary without provider dispatch', function (): void {
	oras_ai_test_reset();
	$now = strtotime( '2026-09-03 00:00:00 UTC' );
	$ledger = new ORAS_AI_Usage_Ledger( static function () use ( &$now ): int { return $now; } );
	$config = ORAS_AI_Cost_Config::defaults();
	$config['daily_quota'] = 1;
	$config['monthly_quota'] = 1;
	$config['pricing'] = array( 'gpt-5.6-luna' => array(
		'input_microdollars_per_million_tokens' => 1000000,
		'output_microdollars_per_million_tokens' => 3000000,
		'unit' => 'per_million_tokens',
	) );
	$provider = new ORAS_AI_Test_Summary_Provider();
	$service = new ORAS_AI_Support_Summary_Service( $provider, new ORAS_AI_Execution_Controls( $ledger, $config ), $ledger );
	$request = oras_ai_test_authorized_request( 7, 'What are the ORAS membership renewal rules?' );
	oras_ai_assert_same( 'generated', $service->generate( $request, $request->question() )['status'], 'First monthly admission failed.' );
	$now += DAY_IN_SECONDS + 61;
	oras_ai_assert_same( 'unavailable', $service->generate( $request, $request->question() )['status'], 'Monthly quota bypassed.' );
	oras_ai_assert_same( 1, count( $provider->calls ), 'Monthly-denied summary reached model.' );
});

oras_ai_test('SUP-004 quota and hard stop deny summary before provider dispatch', function (): void {
	foreach ( array( array( 'daily_quota' => 1 ), array( 'hard_stop_microdollars' => 1 ) ) as $limits ) {
		list( $service, $provider, $ledger ) = oras_ai_test_summary_fixture( null, $limits );
		$request = oras_ai_test_authorized_request( 7, 'What are the ORAS membership renewal rules?' );
		if ( isset( $limits['daily_quota'] ) ) {
			oras_ai_assert_same( 'generated', $service->generate( $request, $request->question() )['status'], 'First quota admission failed.' );
		}
		$result = $service->generate( $request, $request->question() );
		oras_ai_assert_same( 'unavailable', $result['status'], 'Cost admission did not fail closed.' );
		oras_ai_assert_same( isset( $limits['daily_quota'] ) ? 1 : 0, count( $provider->calls ), 'Denied call reached provider.' );
	}
});

oras_ai_test('SUP-004 provider failures release or conservatively settle reservation', function (): void {
	foreach ( array( false, true ) as $usage_may_have_occurred ) {
		$provider = new ORAS_AI_Test_Summary_Provider();
		$provider->response = ORAS_AI_Provider_Answer::failure( 'provider_response_invalid', $usage_may_have_occurred );
		list( $service, $provider, $ledger ) = oras_ai_test_summary_fixture( $provider );
		$request = oras_ai_test_authorized_request( 7, 'What are the ORAS membership renewal rules?' );
		oras_ai_assert_same( 'unavailable', $service->generate( $request, $request->question() )['status'], 'Provider failure produced proposal summary.' );
		oras_ai_assert_same( $usage_may_have_occurred ? 'reconciled' : 'released', $ledger->reservation( 'oras-ai-0000000001' )['status'], 'Failure accounting was unsafe.' );
	}
});

oras_ai_test('SUP-004 production proposal uses a distinct model summary and stores it unchanged', function (): void {
	list( $summary_service, $summary_provider ) = oras_ai_test_summary_fixture();
	$support_provider = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	$conversations = new ORAS_AI_Conversations();
	$conversation_id = $conversations->create_conversation();
	$service = new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary_service );
	$question = 'What are the ORAS membership renewal rules?';
	$proposal = $service->propose( oras_ai_test_authorized_request( 7, $question ), $conversation_id, ORAS_AI_Answer_Result::no_evidence( 'Unknown.' ) );
	oras_ai_assert_same( 'proposed', $proposal->status(), 'Valid support request lacked proposal.' );
	$preview = $proposal->proposal()->to_member_array();
	oras_ai_assert_same( $question, $preview['original_question'], 'Original question lost.' );
	oras_ai_assert_same( 'The member needs clarification about renewing ORAS membership.', $preview['summary'], 'AI summary lost.' );
	oras_ai_assert_same( 1, count( $summary_provider->calls ), 'Summary call count changed.' );
	$pending = new ORAS_AI_Pending_Escalations();
	$created = $pending->create( $proposal->proposal() );
	oras_ai_assert_same( $preview['summary'], $created['preview']['summary'], 'Pending record changed AI summary.' );
});

oras_ai_test('SUP-004 summary runs only for support proposals after route validation', function (): void {
	list( $summary_service, $summary_provider ) = oras_ai_test_summary_fixture();
	$support_provider = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	$conversations = new ORAS_AI_Conversations();
	$id = $conversations->create_conversation();
	$service = new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary_service );
	oras_ai_assert_same( 'none', $service->propose( oras_ai_test_authorized_request( 7, 'How does ORAS membership work?' ), $id, ORAS_AI_Answer_Result::success( 'Answer.', array(), 'test', array(), '' ) )->status(), 'Answered request escalated.' );
	oras_ai_assert_same( 'none', $service->propose( oras_ai_test_authorized_request( 7, 'Where is Mars tonight?' ), $id, ORAS_AI_Answer_Result::no_evidence( 'Unavailable.' ) )->status(), 'Astronomy escalated.' );
	oras_ai_assert_same( 0, count( $summary_provider->calls ), 'Non-support path called summary model.' );
	$support_provider->state = 'missing';
	oras_ai_assert_same( 'routing_unavailable', $service->propose( oras_ai_test_authorized_request( 7, 'ORAS membership support' ), $id, ORAS_AI_Answer_Result::no_evidence( 'Unknown.' ) )->status(), 'Missing route passed.' );
	oras_ai_assert_same( 0, count( $summary_provider->calls ), 'Unavailable route called summary model.' );
});

oras_ai_test('SUP-004 summary failure leaves chat usable but creates no proposal', function (): void {
	oras_ai_test_reset();
	$support_provider = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	$conversations = new ORAS_AI_Conversations();
	$id = $conversations->create_conversation();
	$summary = new ORAS_AI_Test_Support_Summary_Service();
	$summary->result = array( 'status' => 'unavailable' );
	$service = new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary );
	$result = $service->propose( oras_ai_test_authorized_request( 7, 'I need ORAS membership support' ), $id, ORAS_AI_Answer_Result::no_evidence( 'Unknown.' ) );
	oras_ai_assert_same( 'unavailable', $result->status(), 'Summary failure produced a proposal.' );
	oras_ai_assert_same( array( 'status' => 'unavailable' ), $result->to_member_array(), 'Failure exposed model/provider detail.' );
	oras_ai_assert_same( 1, count( $summary->calls ), 'Summary was retried.' );
	foreach ( $support_provider->calls as $call ) {
		oras_ai_assert_true( in_array( $call[0], array( 'status', 'validate_provider_route' ), true ), 'Summary failure caused provider write.' );
	}
	oras_ai_assert_true( ! is_wp_error( $conversations->get_conversation( $id ) ), 'Chat conversation became unavailable.' );
});

oras_ai_test('SUP-004 chat transport returns normal answer and manual fallback when summary fails', function (): void {
	oras_ai_test_reset();
	$support_provider = new ORAS_AI_Test_Read_Only_Support_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	list( $orchestrator ) = oras_ai_test_answer_fixture( new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success( 'Answer.' ) );
	$conversations = new ORAS_AI_Conversations();
	$gateway = new ORAS_AI_Request_Gateway( new ORAS_AI_PMPro_Membership_Authorizer( static function () { return true; } ), $orchestrator );
	$summary = new ORAS_AI_Test_Support_Summary_Service();
	$summary->result = array( 'status' => 'unavailable' );
	$transport = new ORAS_AI_Conversation_Transport( $gateway, $orchestrator, $conversations, new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary ) );
	$current = $transport->dispatch( oras_ai_test_transport_request( 'new_chat' ) );
	$response = $transport->dispatch( oras_ai_test_transport_request( 'send', array( 'conversation_id' => $current['conversation_id'], 'question' => 'What are the ORAS membership renewal rules?' ) ) );
	oras_ai_assert_same( 'no_evidence', $response['result']['status'], 'Normal chat answer was suppressed.' );
	oras_ai_assert_same( 'unavailable', $response['result']['escalation']['status'], 'Summary failure did not surface a bounded fallback state.' );
	oras_ai_assert_same( 2, count( $transport->dispatch( oras_ai_test_transport_request( 'load', array( 'conversation_id' => $current['conversation_id'] ) ) )['messages'] ), 'Chat messages were lost.' );
	oras_ai_assert_same( 1, count( $summary->calls ), 'Summary failure retried model.' );
});

oras_ai_test('AT-SUPPORT-003 confirmed ticket uses stored distinct AI summary without regeneration', function (): void {
	list( $summary_service, $summary_provider ) = oras_ai_test_summary_fixture();
	$support_provider = new ORAS_AI_Test_Confirmation_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	$conversations = new ORAS_AI_Conversations();
	$id = $conversations->create_conversation();
	$question = 'What are the ORAS membership renewal rules?';
	$proposal = ( new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary_service ) )->propose( oras_ai_test_authorized_request( 7, $question ), $id, ORAS_AI_Answer_Result::no_evidence( 'Unknown.' ) );
	oras_ai_assert_same( 'proposed', $proposal->status(), 'No proposal.' );
	$summary = $proposal->proposal()->to_member_array()['summary'];
	oras_ai_assert_true( $summary !== $question && ! str_contains( $summary, $question ), 'Summary duplicated question.' );
	$pending = new ORAS_AI_Pending_Escalations();
	$created = $pending->create( $proposal->proposal() );
	$confirm = new ORAS_AI_Escalation_Confirmation_Service( $pending, $routing, $conversations, $support_provider );
	oras_ai_assert_same( 0, count( array_filter( $support_provider->calls, 'is_array' ) ), 'Support write occurred before confirmation.' );
	oras_ai_assert_false( in_array( 'customer', $support_provider->calls, true ), 'Customer lookup or creation occurred before confirmation.' );
	oras_ai_assert_same( $summary, $confirm->status( $created['token'], $id )['preview']['summary'], 'Status regenerated summary.' );
	oras_ai_assert_same( $summary, $confirm->for_conversation( $id )[0]['preview']['summary'], 'Refresh regenerated summary.' );
	oras_ai_assert_same( 'created', $confirm->confirm( $created['token'], $id )['status'], 'Confirmation failed.' );
	oras_ai_assert_same( 'created', $confirm->confirm( $created['token'], $id )['status'], 'Replay changed result.' );
	oras_ai_assert_same( 1, count( $summary_provider->calls ), 'Confirmation or replay regenerated summary.' );
	$tickets = array_values( array_filter( $support_provider->calls, 'is_array' ) );
	oras_ai_assert_same( 1, count( $tickets ), 'Confirmation created duplicate ticket.' );
	oras_ai_assert_same( 7, $tickets[0][1], 'Confirmed customer identity changed.' );
	oras_ai_assert_same( 1, $tickets[0][2], 'Configured mailbox changed.' );
	oras_ai_assert_same( array( 3 ), $tickets[0][3], 'Configured topic tag changed.' );
	oras_ai_assert_contains( $summary, $tickets[0][5], 'Ticket omitted exact AI summary.' );
	oras_ai_assert_contains( $question, $tickets[0][5], 'Ticket omitted original question.' );
});

oras_ai_test('SUP-004 cancellation and feedback do not regenerate summaries', function (): void {
	list( $summary_service, $summary_provider ) = oras_ai_test_summary_fixture();
	$summary_provider->response = ORAS_AI_Provider_Answer::success( 'The member reports a problem with the ORAS calendar.', 'gpt-5.6-luna', 35, 18 );
	$support_provider = new ORAS_AI_Test_Confirmation_Adapter();
	$routing = new ORAS_AI_Support_Routing( $support_provider );
	oras_ai_assert_true( $routing->save( oras_ai_test_support_config() ), 'Routing fixture failed.' );
	$conversations = new ORAS_AI_Conversations();
	$id = $conversations->create_conversation();
	$question = 'The ORAS calendar is broken';
	$proposal = ( new ORAS_AI_Escalation_Proposal_Service( $routing, $conversations, $summary_service ) )->propose( oras_ai_test_authorized_request( 7, $question ), $id, ORAS_AI_Answer_Result::success( 'Thanks.', array(), 'test', array(), '' ) );
	oras_ai_assert_same( 'proposed', $proposal->status(), 'Bug feedback lacked proposal.' );
	$pending = new ORAS_AI_Pending_Escalations();
	$created = $pending->create( $proposal->proposal() );
	$confirm = new ORAS_AI_Escalation_Confirmation_Service( $pending, $routing, $conversations, $support_provider );
	oras_ai_assert_same( 'cancelled', $confirm->cancel( $created['token'], $id )['status'], 'Cancellation failed.' );
	oras_ai_assert_same( 'cancelled', $confirm->status( $created['token'], $id )['status'], 'Cancelled state was not restored.' );
	oras_ai_assert_same( 1, count( $summary_provider->calls ), 'Cancel or status regenerated summary.' );
	oras_ai_assert_same( 0, count( array_filter( $support_provider->calls, 'is_array' ) ), 'Cancelled feedback created a ticket.' );
});
