<?php
declare(strict_types=1);

function oras_ai_test_ajax_response(callable $callback): ORAS_AI_Test_Json_Response {
	try {
		$callback();
	} catch (ORAS_AI_Test_Json_Response $response) {
		return $response;
	}

	throw new RuntimeException('Expected a WordPress JSON response.');
}

oras_ai_test('NFR-OBS-001 scan runs persist bounded aggregate outcomes without source content', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, 'gpt-5.6-sol');

	$runId = ORAS_AI_Scan_Runs::start('rebuild', 1, 1, ORAS_AI_Config::get_openai_model());
	ORAS_AI_Scan_Runs::record_discovery(
		$runId,
		array(
			'discovered' => 12,
			'unchanged'  => 2,
			'excluded'   => 1,
			'missing'    => 1,
			'retired'    => 2,
		)
	);
	ORAS_AI_Scan_Runs::record_outcome($runId, 'static');
	ORAS_AI_Scan_Runs::record_outcome($runId, 'mixed', true);
	ORAS_AI_Scan_Runs::record_outcome($runId, 'live');
	ORAS_AI_Scan_Runs::record_outcome($runId, 'ignored');
	ORAS_AI_Scan_Runs::record_outcome($runId, 'error');
	ORAS_AI_Scan_Runs::complete($runId);

	$record = ORAS_AI_Scan_Runs::find($runId);
	oras_ai_assert_same(
		array(
			'id', 'mode', 'started_at', 'completed_at', 'discovered', 'processed',
			'unchanged', 'static', 'mixed', 'review', 'live', 'ignored', 'excluded',
			'missing', 'retired', 'failures', 'rule_version', 'extraction_version', 'model',
		),
		array_keys($record),
		'Scan-run schema should remain aggregate and explicit.'
	);
	oras_ai_assert_same('rebuild', $record['mode'], 'Scan mode was not recorded.');
	oras_ai_assert_same('2026-08-27 12:34:56', $record['started_at'], 'Run start time changed.');
	oras_ai_assert_same('2026-08-27 12:34:56', $record['completed_at'], 'Run completion time changed.');
	oras_ai_assert_same(12, $record['discovered'], 'Discovery count changed.');
	oras_ai_assert_same(5, $record['processed'], 'Processed count should include every attempted outcome.');
	oras_ai_assert_same(2, $record['unchanged'], 'Unchanged count changed.');
	oras_ai_assert_same(1, $record['static'], 'Static count changed.');
	oras_ai_assert_same(1, $record['mixed'], 'Mixed count changed.');
	oras_ai_assert_same(1, $record['review'], 'Review count changed.');
	oras_ai_assert_same(1, $record['live'], 'Live count changed.');
	oras_ai_assert_same(1, $record['ignored'], 'Ignored count changed.');
	oras_ai_assert_same(1, $record['excluded'], 'Excluded count changed.');
	oras_ai_assert_same(1, $record['missing'], 'Missing count changed.');
	oras_ai_assert_same(2, $record['retired'], 'Retired count changed.');
	oras_ai_assert_same(1, $record['failures'], 'Failure count changed.');
	oras_ai_assert_same(1, $record['rule_version'], 'Rule version changed.');
	oras_ai_assert_same(1, $record['extraction_version'], 'Extraction version changed.');
	oras_ai_assert_same('gpt-5.6-sol', $record['model'], 'Configured model changed.');
	oras_ai_assert_same(false, $GLOBALS['oras_ai_test_option_autoload'][ORAS_AI_Scan_Runs::OPTION], 'Scan runs should not autoload.');
	$serialized = serialize($record);
	oras_ai_assert_not_contains('source_url', $serialized, 'Scan runs must not store source URLs.');
	oras_ai_assert_not_contains('source_content', $serialized, 'Scan runs must not store source content.');
	oras_ai_assert_not_contains('api_key', $serialized, 'Scan runs must not store secrets.');

	for ($index = 0; $index < ORAS_AI_Scan_Runs::MAX_RECORDS + 3; $index++) {
		ORAS_AI_Scan_Runs::start('changed', 1, 1, 'gpt-5.6-luna');
	}

	$recent = ORAS_AI_Scan_Runs::recent();
	oras_ai_assert_same(ORAS_AI_Scan_Runs::MAX_RECORDS, count($recent), 'Scan-run history must stay bounded.');
	oras_ai_assert_same($runId + 4, $recent[0]['id'], 'Oldest excess records should be discarded.');
});

oras_ai_test('scanner AJAX records a complete qualified run across every M2 disposition', function (): void {
	oras_ai_test_reset();

	oras_ai_test_add_post(array('post_type' => 'oras_speaker', 'post_title' => 'Speaker', 'post_content' => 'Stable speaker biography'));
	oras_ai_test_add_post(array('post_type' => 'product', 'post_title' => 'Public Night Ticket', 'post_content' => 'Current product data'));
	oras_ai_test_add_post(array('post_type' => 'tribe_events', 'post_title' => 'Public Night', 'post_content' => 'Current event data'));
	oras_ai_test_add_post(array('post_type' => 'elementor_library', 'post_title' => 'Footer Template', 'post_content' => 'Template content'));
	oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'AstroBlast', 'post_content' => 'Stable and current AstroBlast facts'));
	$excludedPostId = oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'Excluded Page', 'post_content' => 'Excluded content'));
	$excludedSourceId = oras_ai_test_add_source('page', 'Excluded Page', 'Previously discovered content');
	update_post_meta($excludedSourceId, '_oras_ai_wp_post_id', $excludedPostId);
	update_post_meta($excludedSourceId, ORAS_AI_Sources::META_EXCLUDED, '1');

	$missingSourceId = oras_ai_test_add_source('page', 'Removed Page', 'Removed source content');
	$missingArtifactId = oras_ai_test_add_linked_kb($missingSourceId, true);

	$mixed = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(
			array(
				'stable_fragments' => array(
					array(
						'stable_title' => 'About AstroBlast',
						'stable_content' => 'AstroBlast is an ORAS astronomy gathering.',
					),
				),
				'excluded_dynamic_claims' => array('Tickets are currently available.'),
				'dynamic_fact_types' => array('availability'),
			)
		),
		'ai',
		'AstroBlast'
	);
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($mixed));

	$_POST = array('scan_mode' => 'changed', 'nonce' => 'valid');
	$discovery = oras_ai_test_ajax_response(static function () use ($sources): void {
		$sources->ajax_discover_sources();
	});
	oras_ai_assert_true($discovery->success, 'Discovery should return a successful AJAX response.');
	$runId = (int) $discovery->data['run_id'];
	oras_ai_assert_true($runId > 0, 'Discovery must return a persistent scan-run ID.');

	foreach ($discovery->data['queue'] as $sourceId) {
		$_POST = array('source_id' => $sourceId, 'run_id' => $runId, 'nonce' => 'valid');
		$response = oras_ai_test_ajax_response(static function () use ($sources): void {
			$sources->ajax_process_source();
		});
		oras_ai_assert_true($response->success, 'Every deterministic or injected source should process successfully.');
	}

	$_POST = array('run_id' => $runId, 'nonce' => 'valid');
	$completion = oras_ai_test_ajax_response(static function () use ($sources): void {
		$sources->ajax_complete_scan();
	});
	oras_ai_assert_true($completion->success, 'Completion should return a successful AJAX response.');

	$record = ORAS_AI_Scan_Runs::find($runId);
	oras_ai_assert_same(6, $record['discovered'], 'All active and excluded public sources should be counted.');
	oras_ai_assert_same(5, $record['processed'], 'Every queued source should be counted once.');
	oras_ai_assert_same(0, $record['unchanged'], 'No new source should count as unchanged.');
	oras_ai_assert_same(1, $record['static'], 'Speaker should count as static knowledge.');
	oras_ai_assert_same(1, $record['mixed'], 'Mixed page should count as mixed.');
	oras_ai_assert_same(1, $record['review'], 'Mixed page should also count in review.');
	oras_ai_assert_same(2, $record['live'], 'Product and event should count as Live.');
	oras_ai_assert_same(1, $record['ignored'], 'Elementor library should count as ignored.');
	oras_ai_assert_same(1, $record['excluded'], 'Administrator-excluded source should be counted without processing.');
	oras_ai_assert_same(1, $record['missing'], 'Removed source should count as missing.');
	oras_ai_assert_same(1, $record['retired'], 'Newly retired missing-source artifact should be counted.');
	oras_ai_assert_same(0, $record['failures'], 'Qualified run should have no failures.');
	oras_ai_assert_same('retired', get_post_meta($missingArtifactId, '_oras_ai_status', true), 'Missing managed artifact should retire.');
	oras_ai_assert_same('excluded', get_post_meta($excludedSourceId, '_oras_ai_scan_status', true), 'Excluded source should remain excluded.');
	oras_ai_assert_same('2026-08-27 12:34:56', $record['completed_at'], 'Completed run should have an end time.');
});

oras_ai_test('scanner run records failed processing and validates completion IDs', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Broken source', 'Content requiring classification');
	$failure = new WP_Error('classification_failed', 'Classification failed safely.');
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($failure));
	$runId = ORAS_AI_Scan_Runs::start('changed', 1, 1, ORAS_AI_Config::get_openai_model());

	$_POST = array('source_id' => $sourceId, 'run_id' => $runId, 'nonce' => 'valid');
	$response = oras_ai_test_ajax_response(static function () use ($sources): void {
		$sources->ajax_process_source();
	});
	oras_ai_assert_false($response->success, 'Processing failure should remain a JSON error.');
	$record = ORAS_AI_Scan_Runs::find($runId);
	oras_ai_assert_same(1, $record['processed'], 'Failed attempt should count as processed.');
	oras_ai_assert_same(1, $record['failures'], 'Failed attempt should count as a failure.');
	oras_ai_assert_same('2026-08-27 12:34:56', $record['completed_at'], 'A failed browser queue should record when it stopped.');

	$_POST = array('run_id' => 999, 'nonce' => 'valid');
	$invalid = oras_ai_test_ajax_response(static function () use ($sources): void {
		$sources->ajax_complete_scan();
	});
	oras_ai_assert_false($invalid->success, 'Unknown run IDs must fail safely.');
	oras_ai_assert_same('Invalid scan run.', $invalid->data['message'], 'Malformed run completion message changed.');
});
