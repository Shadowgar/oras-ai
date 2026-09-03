<?php
declare(strict_types=1);

function oras_ai_test_run_source_action(ORAS_AI_Sources $sources, int $sourceId, string $action): string {
	$_POST = array(
		'source_id' => (string) $sourceId,
		'source_action' => $action,
		'oras_ai_source_action_nonce' => 'valid',
	);

	try {
		$sources->handle_source_action();
	} catch (ORAS_AI_Test_Redirect_Exception $exception) {
		return $exception->location;
	}

	throw new RuntimeException('Expected source action redirect.');
}

function oras_ai_test_run_review_action(ORAS_AI_Sources $sources, int $sourceId, int $artifactId, string $action): string {
	$_POST = array(
		'source_id' => (string) $sourceId,
		'artifact_id' => (string) $artifactId,
		'review_action' => $action,
		'oras_ai_review_action_nonce' => 'valid',
	);

	try {
		$sources->handle_review_action();
	} catch (ORAS_AI_Test_Redirect_Exception $exception) {
		return $exception->location;
	}

	throw new RuntimeException('Expected review action redirect.');
}

oras_ai_test('ADM-003 exclusion persists across normal scan and rebuild and unexclude requeues safely', function (): void {
	oras_ai_test_reset();
	$wpPostId = oras_ai_test_add_post(array('post_type' => 'oras_speaker', 'post_title' => 'Dr. Excluded', 'post_content' => 'Original biography'));
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($wpPostId);
	$processed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$managedId = $processed['kb_id'];
	$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
	$manualBefore = oras_ai_test_manual_snapshot($manualId);
	$sourceBefore = array(get_post($sourceId)->post_content, get_post_meta($sourceId, '_oras_ai_source_hash', true));

	$redirect = oras_ai_test_run_source_action($sources, $sourceId, 'exclude');

	oras_ai_assert_contains('page=oras-ai-sources', $redirect, 'Exclusion should return to Knowledge Sources.');
	oras_ai_assert_same('1', get_post_meta($sourceId, '_oras_ai_excluded', true), 'Explicit exclusion marker did not persist.');
	oras_ai_assert_same('excluded', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Excluded source state changed.');
	oras_ai_assert_same('retired', get_post_meta($managedId, '_oras_ai_status', true), 'Excluding a speaker source must retire its scanner-managed artifact.');
	oras_ai_assert_same($manualBefore, oras_ai_test_manual_snapshot($manualId), 'Excluding a source changed manual knowledge.');

	wp_update_post(array('ID' => $wpPostId, 'post_content' => 'Changed while excluded'));
	$normal = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$rebuild = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));
	$direct = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	oras_ai_assert_same(array(), $normal['queue'], 'Normal scan queued an excluded source.');
	oras_ai_assert_same(array(), $rebuild['queue'], 'Rebuild queued an excluded source.');
	oras_ai_assert_same('excluded', $direct['kind'], 'Direct processing must honor the persisted exclusion guard.');
	oras_ai_assert_same($sourceBefore, array(get_post($sourceId)->post_content, get_post_meta($sourceId, '_oras_ai_source_hash', true)), 'Excluded source was reingested.');
	oras_ai_assert_same($manualBefore, oras_ai_test_manual_snapshot($manualId), 'Excluded scan/rebuild changed manual knowledge.');

	oras_ai_test_run_source_action($sources, $sourceId, 'unexclude');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_excluded', true), 'Unexclude did not remove the persistent marker.');
	oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Unexcluded source should become pending.');
	$rediscovered = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	oras_ai_assert_same(array($sourceId), $rediscovered['queue'], 'Unexcluded source should requeue on the next normal scan.');
	$reprocessed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	oras_ai_assert_same($managedId, $reprocessed['kb_id'], 'Unexclude should reactivate the existing managed artifact, not duplicate it.');
	oras_ai_assert_same('approved', get_post_meta($managedId, '_oras_ai_status', true), 'Reprocessed speaker artifact should return to Approved.');
	oras_ai_assert_same($manualBefore, oras_ai_test_manual_snapshot($manualId), 'Unexclude/reprocessing changed manual knowledge.');
});

oras_ai_test('deterministic Ignore and administrator Excluded remain visibly distinct', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('elementor_library', 'Template source');
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	ob_start();
	$sources->render_sources_page();
	$ignoredHtml = (string) ob_get_clean();
	oras_ai_assert_contains('Ignored', $ignoredHtml, 'Deterministic Ignore classification should remain visible.');
	oras_ai_assert_not_contains('Excluded by administrator', $ignoredHtml, 'Deterministic Ignore must not masquerade as an admin exclusion.');

	oras_ai_test_run_source_action($sources, $sourceId, 'exclude');
	ob_start();
	$sources->render_sources_page();
	$excludedHtml = (string) ob_get_clean();
	oras_ai_assert_contains('Ignored', $excludedHtml, 'Exclusion must preserve the underlying deterministic classification.');
	oras_ai_assert_contains('Excluded by administrator', $excludedHtml, 'Explicit exclusion state should be visible.');
	oras_ai_assert_contains('Include source', $excludedHtml, 'Excluded source should expose the reversible action.');
});

oras_ai_test('source exclusion rejects unauthorized requests before nonce verification', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Protected source');
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$_POST = array('source_id' => (string) $sourceId, 'source_action' => 'exclude', 'oras_ai_source_action_nonce' => 'valid');

	try {
		(new ORAS_AI_Sources())->handle_source_action();
		throw new RuntimeException('Expected source action permission rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('permission', $exception->getMessage(), 'Unexpected source action permission message.');
	}

	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized exclusion should stop before nonce verification.');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_excluded', true), 'Unauthorized request changed exclusion state.');
});

oras_ai_test('source exclusion rejects a bad nonce and malformed action safely', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Validated source');
	$sources = new ORAS_AI_Sources();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = array('source_id' => (string) $sourceId, 'source_action' => 'exclude', 'oras_ai_source_action_nonce' => 'invalid');
	try {
		$sources->handle_source_action();
		throw new RuntimeException('Expected source action nonce rejection.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same('Invalid admin nonce.', $exception->getMessage(), 'Unexpected exclusion nonce failure.');
	}
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_excluded', true), 'Bad nonce changed exclusion state.');

	$GLOBALS['oras_ai_test_nonce_valid'] = true;
	$_POST['source_action'] = 'delete';
	try {
		$sources->handle_source_action();
		throw new RuntimeException('Expected malformed source action rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('Invalid source action', $exception->getMessage(), 'Malformed source action should fail explicitly.');
	}
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_excluded', true), 'Malformed action changed exclusion state.');
});

oras_ai_test('ADM-004 Needs Review queue exposes actionable source provenance ownership and repeated review context', function (): void {
	oras_ai_test_reset();
	$classification = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(array(
			'source_kind' => 'review',
			'category' => 'Policies & Rules',
			'confidence' => 'medium',
			'knowledge_title' => 'Policy ambiguity review',
			'reason' => 'The policy effective date is ambiguous.',
		)),
		'ai',
		'Policy ambiguity'
	);
	$sourceId = oras_ai_test_add_source('page', 'Policy ambiguity', 'Ambiguous policy text');
	update_post_meta($sourceId, '_oras_ai_source_hash', 'review-source-hash');
	update_post_meta($sourceId, '_oras_ai_wp_modified_gmt', '2026-08-30 09:15:00');
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($classification));
	oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	ob_start();
	$sources->render_review_page();
	$html = (string) ob_get_clean();

	oras_ai_assert_contains('Policy ambiguity', $html, 'Review queue is missing the source title.');
	oras_ai_assert_contains('https://oras.org/source-' . $sourceId . '/', $html, 'Review queue is missing the source URL.');
	oras_ai_assert_contains('The policy effective date is ambiguous.', $html, 'Review queue is missing the classification reason.');
	oras_ai_assert_contains('Needs Review', $html, 'Review queue is missing the classification/lifecycle state.');
	oras_ai_assert_contains('Policies &amp; Rules', $html, 'Review queue is missing the category.');
	oras_ai_assert_contains('Medium', $html, 'Review queue is missing confidence.');
	oras_ai_assert_contains('review-source-hash', $html, 'Review queue is missing source provenance.');
	oras_ai_assert_contains('2026-08-30 09:15:00', $html, 'Review queue is missing source freshness.');
	oras_ai_assert_contains('Scanner-managed', $html, 'Review queue is missing ownership.');
	oras_ai_assert_contains('2 review occurrences', $html, 'Repeated review visibility is missing.');
	oras_ai_assert_contains('Approve', $html, 'Review queue lacks the authorized approval disposition.');
	oras_ai_assert_contains('Retire', $html, 'Review queue lacks the authorized retirement disposition.');
});

oras_ai_test('authorized review dispositions update only linked scanner-managed review artifacts', function (): void {
	foreach (array('approve' => 'approved', 'retire' => 'retired') as $action => $expectedStatus) {
		oras_ai_test_reset();
		$classification = ORAS_AI_Source_Classification_Result::from_array(
			oras_ai_test_classification(array('source_kind' => 'review', 'confidence' => 'medium', 'reason' => 'Human decision required.')),
			'ai',
			'Review source'
		);
		$sourceId = oras_ai_test_add_source('page', 'Review source');
		$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($classification));
		$processed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
		$artifactId = $processed['kb_id'];
		$provenanceBefore = array(
			get_post_meta($artifactId, '_oras_ai_source_record_id', true),
			get_post_meta($artifactId, '_oras_ai_source_hash', true),
			get_post_meta($artifactId, '_oras_ai_managed_by_scan', true),
		);

		$redirect = oras_ai_test_run_review_action($sources, $sourceId, $artifactId, $action);

		oras_ai_assert_contains('page=oras-ai-review', $redirect, 'Review disposition should return to the central queue.');
		oras_ai_assert_same($expectedStatus, get_post_meta($artifactId, '_oras_ai_status', true), "{$action} disposition changed to the wrong lifecycle state.");
		oras_ai_assert_same($provenanceBefore, array(
			get_post_meta($artifactId, '_oras_ai_source_record_id', true),
			get_post_meta($artifactId, '_oras_ai_source_hash', true),
			get_post_meta($artifactId, '_oras_ai_managed_by_scan', true),
		), "{$action} disposition changed artifact provenance or ownership.");
		oras_ai_assert_same('complete', get_post_meta($sourceId, '_oras_ai_scan_status', true), "Resolved {$action} disposition should clear the source from Needs Review.");
		if ('approve' === $action) {
			oras_ai_assert_same('2026-08-27', get_post_meta($artifactId, '_oras_ai_last_reviewed', true), 'Approval should record the review date.');
		}
	}
});

oras_ai_test('review disposition refuses manual knowledge even when linked to a review source', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Manual review source');
	$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
	update_post_meta($manualId, '_oras_ai_status', 'review');
	update_post_meta($sourceId, '_oras_ai_scan_status', 'review');
	$before = oras_ai_test_manual_snapshot($manualId);
	$_POST = array(
		'source_id' => (string) $sourceId,
		'artifact_id' => (string) $manualId,
		'review_action' => 'approve',
		'oras_ai_review_action_nonce' => 'valid',
	);

	try {
		(new ORAS_AI_Sources())->handle_review_action();
		throw new RuntimeException('Expected manual review disposition rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('scanner-managed', $exception->getMessage(), 'Manual ownership rejection message changed.');
	}

	oras_ai_assert_same($before, oras_ai_test_manual_snapshot($manualId), 'Review workflow changed manual knowledge.');
});

oras_ai_test('review disposition rejects unauthorized requests before nonce verification', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Protected review source');
	$artifactId = oras_ai_test_add_linked_kb($sourceId, true);
	update_post_meta($artifactId, '_oras_ai_status', 'review');
	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	$_POST = array('source_id' => (string) $sourceId, 'artifact_id' => (string) $artifactId, 'review_action' => 'approve', 'oras_ai_review_action_nonce' => 'valid');

	try {
		(new ORAS_AI_Sources())->handle_review_action();
		throw new RuntimeException('Expected review permission rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('permission', $exception->getMessage(), 'Unexpected review permission message.');
	}

	oras_ai_assert_same(array(), $GLOBALS['oras_ai_test_admin_nonce_checks'], 'Unauthorized review action should stop before nonce verification.');
	oras_ai_assert_same('review', get_post_meta($artifactId, '_oras_ai_status', true), 'Unauthorized review action changed lifecycle.');
});

oras_ai_test('review disposition rejects a bad nonce and malformed action safely', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Validated review source');
	$artifactId = oras_ai_test_add_linked_kb($sourceId, true);
	update_post_meta($artifactId, '_oras_ai_status', 'review');
	$sources = new ORAS_AI_Sources();
	$GLOBALS['oras_ai_test_nonce_valid'] = false;
	$_POST = array('source_id' => (string) $sourceId, 'artifact_id' => (string) $artifactId, 'review_action' => 'approve', 'oras_ai_review_action_nonce' => 'invalid');
	try {
		$sources->handle_review_action();
		throw new RuntimeException('Expected review nonce rejection.');
	} catch (ORAS_AI_Test_Nonce_Exception $exception) {
		oras_ai_assert_same('Invalid admin nonce.', $exception->getMessage(), 'Unexpected review nonce failure.');
	}
	oras_ai_assert_same('review', get_post_meta($artifactId, '_oras_ai_status', true), 'Bad nonce changed review lifecycle.');

	$GLOBALS['oras_ai_test_nonce_valid'] = true;
	$_POST['review_action'] = 'publish_everything';
	try {
		$sources->handle_review_action();
		throw new RuntimeException('Expected malformed review action rejection.');
	} catch (ORAS_AI_Test_Die_Exception $exception) {
		oras_ai_assert_contains('Invalid review action', $exception->getMessage(), 'Malformed review action should fail explicitly.');
	}
	oras_ai_assert_same('review', get_post_meta($artifactId, '_oras_ai_status', true), 'Malformed action changed review lifecycle.');
});

oras_ai_test('NFR-OBS-004 repeated processing failures persist and appear without scan-run metrics', function (): void {
	oras_ai_test_reset();
	$failure = new WP_Error('classifier_unavailable', 'Classifier unavailable.');
	$sourceId = oras_ai_test_add_source('page', 'Repeated failure source');
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($failure));
	oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	oras_ai_assert_same(2, get_post_meta($sourceId, '_oras_ai_problem_count', true), 'Repeated failure count did not persist.');
	oras_ai_assert_same('error', get_post_meta($sourceId, '_oras_ai_last_problem_kind', true), 'Last problem kind changed.');
	oras_ai_assert_same('Classifier unavailable.', get_post_meta($sourceId, '_oras_ai_last_problem', true), 'Last problem detail changed.');

	ob_start();
	$sources->render_review_page();
	$html = (string) ob_get_clean();
	oras_ai_assert_contains('Repeated failure source', $html, 'Repeated failure source is missing from the central queue.');
	oras_ai_assert_contains('2 error occurrences', $html, 'Repeated failure count is not visible.');
	oras_ai_assert_contains('Classifier unavailable.', $html, 'Repeated failure detail is not visible.');
	oras_ai_assert_same(array(), get_posts(array('post_type' => 'oras_ai_sync_run', 'post_status' => 'publish', 'posts_per_page' => -1)), 'Task 4 must not introduce Task 5 SyncRun records.');
});
