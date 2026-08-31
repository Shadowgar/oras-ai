<?php
declare(strict_types=1);

function oras_ai_test_add_artifact(string $status = 'approved', string $managed = ''): int {
	$artifactId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => ucfirst($status) . ' artifact',
		)
	);
	if ('' !== $status) {
		update_post_meta($artifactId, '_oras_ai_status', $status);
	}
	if ('' !== $managed) {
		update_post_meta($artifactId, '_oras_ai_managed_by_scan', $managed);
	}
	return $artifactId;
}

oras_ai_test('AT-KB-008 active eligibility follows lifecycle and exact scanner ownership marker', function (): void {
	oras_ai_test_reset();
	$approved = oras_ai_test_add_artifact('approved', '1');
	$review = oras_ai_test_add_artifact('review');
	$draft = oras_ai_test_add_artifact('draft');
	$legacyManual = oras_ai_test_add_artifact('');
	$retired = oras_ai_test_add_artifact('retired', '1');
	$unknown = oras_ai_test_add_artifact('unknown', 'true');

	oras_ai_assert_true(ORAS_AI_Knowledge_Base::is_scanner_managed($approved), 'Exact managed marker should grant scanner ownership.');
	oras_ai_assert_false(ORAS_AI_Knowledge_Base::is_scanner_managed($unknown), 'Non-exact managed marker must remain manual.');
	oras_ai_assert_true(ORAS_AI_Knowledge_Base::is_active_artifact($approved), 'Approved artifact should be active and eligible for future retrieval.');
	foreach (array($review, $draft, $legacyManual) as $artifactId) {
		oras_ai_assert_false(ORAS_AI_Knowledge_Base::is_active_artifact($artifactId), 'Needs Review, Draft, and statusless artifacts must not be active or retrieval eligible.');
	}
	oras_ai_assert_false(ORAS_AI_Knowledge_Base::is_active_artifact($retired), 'Retired artifact must be inactive.');
	oras_ai_assert_false(ORAS_AI_Knowledge_Base::is_active_artifact($unknown), 'Unknown lifecycle must fail closed.');
});

oras_ai_test('AT-KB-008 active count and dashboard exclude retired records while retaining total count', function (): void {
	oras_ai_test_reset();
	oras_ai_test_add_artifact('approved', '1');
	oras_ai_test_add_artifact('review', '1');
	oras_ai_test_add_artifact('draft');
	oras_ai_test_add_artifact('');
	oras_ai_test_add_artifact('retired', '1');

	oras_ai_assert_same(1, ORAS_AI_Knowledge_Base::count_active_artifacts(), 'Only Approved artifacts should count as active.');
	oras_ai_assert_same(1, ORAS_AI_Knowledge_Base::count_artifacts_by_lifecycle('review'), 'Needs Review count should remain distinct from active Approved knowledge.');
	oras_ai_assert_same(1, ORAS_AI_Knowledge_Base::count_artifacts_by_lifecycle('retired'), 'Retired count should remain distinct from active Approved knowledge.');

	ob_start();
	(new ORAS_AI_Assistant())->render_dashboard();
	$html = (string) ob_get_clean();
	oras_ai_assert_contains('<p class="oras-ai-number">1</p>', $html, 'Dashboard should show one active Approved artifact.');
	oras_ai_assert_contains('Active Approved knowledge', $html, 'Dashboard must label the Approved count explicitly.');
	oras_ai_assert_contains('1 Needs Review', $html, 'Dashboard must show Needs Review separately.');
	oras_ai_assert_contains('1 Retired', $html, 'Dashboard must show Retired separately.');
	oras_ai_assert_contains('5 total records', $html, 'Dashboard should preserve the distinct total-record meaning.');
});

oras_ai_test('source admin lists active retired and ownership state for every linked artifact', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Lifecycle source');
	$retiredId = oras_ai_test_add_artifact('retired', '1');
	$activeId = oras_ai_test_add_artifact('review', '1');
	$manualId = oras_ai_test_add_artifact('approved');
	foreach (array($retiredId, $activeId) as $artifactId) {
		update_post_meta($artifactId, '_oras_ai_source_record_id', $sourceId);
	}
	update_post_meta($sourceId, '_oras_ai_kb_entry_id', $manualId);
	update_post_meta($sourceId, '_oras_ai_kb_entry_ids', array($retiredId, $activeId));

	ob_start();
	(new ORAS_AI_Sources())->render_sources_page();
	$html = (string) ob_get_clean();

	oras_ai_assert_contains('KB-' . str_pad((string) $activeId, 5, '0', STR_PAD_LEFT), $html, 'Active Mixed artifact should be visible.');
	oras_ai_assert_contains('Needs Review — Scanner-managed', $html, 'Active managed lifecycle label missing.');
	oras_ai_assert_contains('Retired — Scanner-managed', $html, 'Retired artifact must be labeled rather than presented as active.');
	oras_ai_assert_contains('Approved — Manual', $html, 'Manual ownership must be visible for an incorrectly linked artifact.');
});
