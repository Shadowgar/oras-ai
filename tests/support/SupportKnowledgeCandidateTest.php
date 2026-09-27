<?php
declare(strict_types=1);

final class ORAS_AI_Test_Knowledge_Ticket_Gateway {
	public array $versions = array( 'core' => '2.4.0', 'pro' => '2.4.0', 'capable' => true );
	public $ticket;
	public int $reads = 0;
	public function __construct( $status = 'closed' ) {
		$this->ticket = (object) array(
			'id' => 17, 'status' => $status, 'resolved_at' => '2026-09-27 12:00:00',
			'title' => 'Jane Doe jane@example.test private question',
			'content' => 'Full transcript and private answer', 'customer_id' => 53,
			'metadata' => array( 'private' => 'secret' ),
		);
	}
	public function versions(): array { return $this->versions; }
	public function ticket( int $id ) { ++$this->reads; return 17 === $id ? $this->ticket : null; }
}

oras_ai_test('M7 support candidate requires admin and a valid ticket ID', function (): void {
	oras_ai_test_reset();
	$gateway = new ORAS_AI_Test_Knowledge_Ticket_Gateway();
	$service = new ORAS_AI_Support_Knowledge_Candidates( new ORAS_AI_Fluent_Support_Adapter( $gateway ) );
	$GLOBALS['oras_ai_test_default_capability'] = false;
	oras_ai_assert_same( 'forbidden', $service->create( '17' )['status'], 'Member created candidate.' );
	$GLOBALS['oras_ai_test_current_user_id'] = 0;
	oras_ai_assert_same( 'forbidden', $service->create( '17' )['status'], 'Anonymous created candidate.' );
	$GLOBALS['oras_ai_test_default_capability'] = true;
	$GLOBALS['oras_ai_test_current_user_id'] = 1;
	foreach ( array( '0', '17x', '-17', '017', array( 17 ) ) as $bad ) {
		oras_ai_assert_same( 'invalid_id', $service->create( $bad )['status'], 'Malformed ID accepted.' );
	}
	oras_ai_assert_same( 0, $gateway->reads, 'Invalid request reached provider.' );
});

oras_ai_test('M7 support candidate accepts only server-loaded closed tickets', function (): void {
	oras_ai_test_reset();
	$gateway = new ORAS_AI_Test_Knowledge_Ticket_Gateway( 'active' );
	$service = new ORAS_AI_Support_Knowledge_Candidates( new ORAS_AI_Fluent_Support_Adapter( $gateway ) );
	oras_ai_assert_same( 'not_resolved', $service->create( '17' )['status'], 'Open ticket accepted.' );
	oras_ai_assert_same( 'unavailable', $service->create( '18' )['status'], 'Missing ticket accepted.' );
	$gateway->versions['core'] = null;
	oras_ai_assert_same( 'provider_unavailable', $service->create( '17' )['status'], 'Missing provider accepted.' );
	$gateway->versions['core'] = '2.4.0';
	$gateway->ticket->status = 'closed';
	$gateway->ticket->resolved_at = null;
	oras_ai_assert_same( 'not_resolved', $service->create( '17' )['status'], 'Unresolved closed-state ticket accepted.' );
	$events = ORAS_AI_Audit_Log::recent_events();
	oras_ai_assert_same( 'support_knowledge_candidate_rejected', $events[0]['action'], 'Rejected action not audited.' );
	oras_ai_assert_not_contains( 'private question', serialize( $events ), 'Ticket content appeared in rejection audit.' );
});

oras_ai_test('M7 support candidate is private review-only idempotent and contains no customer data', function (): void {
	oras_ai_test_reset();
	$gateway = new ORAS_AI_Test_Knowledge_Ticket_Gateway();
	$service = new ORAS_AI_Support_Knowledge_Candidates( new ORAS_AI_Fluent_Support_Adapter( $gateway ) );
	$first = $service->create( '17' );
	oras_ai_assert_same( 'created', $first['status'], 'Candidate not created.' );
	$id = $first['id'];
	oras_ai_assert_same( ORAS_AI_Knowledge_Base::POST_TYPE, get_post_type( $id ), 'Wrong knowledge store.' );
	oras_ai_assert_same( 'review', ORAS_AI_Knowledge_Base::lifecycle_status( $id ), 'Candidate approved.' );
	oras_ai_assert_false( ORAS_AI_Knowledge_Base::is_active_artifact( $id ), 'Candidate became retrieval evidence.' );
	oras_ai_assert_same( 'admin', get_post_meta( $id, '_oras_ai_visibility', true ), 'Candidate visibility was not private.' );
	oras_ai_assert_same( '', get_post_meta( $id, '_oras_ai_official_answer', true ), 'Resolution was fabricated.' );
	oras_ai_assert_same( 'fluent_support', get_post_meta( $id, '_oras_ai_support_provider', true ), 'Provider provenance missing.' );
	oras_ai_assert_same( 17, get_post_meta( $id, '_oras_ai_support_ticket_id', true ), 'Ticket provenance missing.' );
	$second = $service->create( '17' );
	oras_ai_assert_same( 'existing', $second['status'], 'Second click was not idempotent.' );
	oras_ai_assert_same( $id, $second['id'], 'Second click changed candidate.' );
	$gateway->versions['core'] = null;
	oras_ai_assert_same( 'existing', $service->create( '17' )['status'], 'Existing candidate was hidden when provider became unavailable.' );
	oras_ai_assert_same( 1, count( $GLOBALS['oras_ai_test_posts'] ), 'Duplicate candidate created.' );
	$stored = serialize( array( $GLOBALS['oras_ai_test_posts'], $GLOBALS['oras_ai_test_post_meta'], ORAS_AI_Audit_Log::recent_events() ) );
	foreach ( array( 'Jane Doe', 'jane@example.test', 'Full transcript', 'private answer', 'secret' ) as $private ) {
		oras_ai_assert_not_contains( $private, $stored, 'Private ticket data copied.' );
	}
	$events = ORAS_AI_Audit_Log::recent_events();
	oras_ai_assert_same( 'support_knowledge_candidate_existing', $events[0]['action'], 'Existing event missing.' );
	oras_ai_assert_same( 'support_knowledge_candidate_created', $events[2]['action'], 'Creation event missing.' );
});

oras_ai_test('M7 support candidate admin action requires nonce and ignores spoofed content', function (): void {
	oras_ai_test_reset();
	$gateway = new ORAS_AI_Test_Knowledge_Ticket_Gateway();
	$service = new ORAS_AI_Support_Knowledge_Candidates( new ORAS_AI_Fluent_Support_Adapter( $gateway ) );
	$_POST = array( 'ticket_id' => '17', 'status' => 'closed', 'title' => 'browser spoof', 'content' => 'browser secret' );
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	try { $service->handle_action(); } catch ( ORAS_AI_Test_Nonce_Exception $error ) {}
	oras_ai_assert_same( 0, $gateway->reads, 'Invalid nonce reached provider.' );
	$GLOBALS['oras_ai_test_nonce_valid'] = true;
	try { $service->handle_action(); } catch ( ORAS_AI_Test_Redirect_Exception $error ) {}
	oras_ai_assert_same( 1, $gateway->reads, 'Valid action did not read provider once.' );
	oras_ai_assert_not_contains( 'browser spoof', serialize( $GLOBALS['oras_ai_test_posts'] ), 'Browser title was trusted.' );
	oras_ai_assert_not_contains( 'browser secret', serialize( $GLOBALS['oras_ai_test_post_meta'] ), 'Browser content was trusted.' );
});

oras_ai_test('M7 support candidate does not overwrite manual knowledge or retry an incomplete claim', function (): void {
	oras_ai_test_reset();
	$manual = wp_insert_post( array( 'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE, 'post_title' => 'Manual knowledge' ) );
	update_post_meta( $manual, '_oras_ai_official_answer', 'Manual approved answer' );
	update_post_meta( $manual, '_oras_ai_status', 'approved' );
	$service = new ORAS_AI_Support_Knowledge_Candidates( new ORAS_AI_Fluent_Support_Adapter( new ORAS_AI_Test_Knowledge_Ticket_Gateway() ) );
	$created = $service->create( '17' );
	oras_ai_assert_same( 'created', $created['status'], 'Candidate not created.' );
	oras_ai_assert_same( 'Manual approved answer', get_post_meta( $manual, '_oras_ai_official_answer', true ), 'Manual answer changed.' );
	oras_ai_assert_same( 'approved', ORAS_AI_Knowledge_Base::lifecycle_status( $manual ), 'Manual status changed.' );
	update_option( 'oras_ai_support_candidate_fluent_support_18', 'creating', false );
	oras_ai_assert_same( 'pending', $service->create( '18' )['status'], 'Incomplete claim was retried after ticket disappeared.' );
	update_option( 'oras_ai_support_candidate_fluent_support_17', 'creating', false );
	oras_ai_assert_same( 'pending', $service->create( '17' )['status'], 'Incomplete claim was retried.' );
	oras_ai_assert_same( 2, count( $GLOBALS['oras_ai_test_posts'] ), 'Incomplete claim inserted duplicate.' );
});
