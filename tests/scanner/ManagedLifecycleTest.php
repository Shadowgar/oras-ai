<?php
declare(strict_types=1);

function oras_ai_test_add_source(string $postType, string $title = 'Source', string $content = 'Source content'): int {
	$sourceId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => $title,
			'post_content' => $content,
		)
	);
	update_post_meta($sourceId, '_oras_ai_wp_post_type', $postType);
	update_post_meta($sourceId, '_oras_ai_source_url', 'https://oras.org/source-' . $sourceId . '/');
	return $sourceId;
}

function oras_ai_test_add_linked_kb(int $sourceId, bool $managed): int {
	$kbId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => $managed ? 'Managed KB' : 'Manual KB',
		)
	);
	update_post_meta($kbId, '_oras_ai_status', 'approved');
	if ($managed) {
		update_post_meta($kbId, '_oras_ai_managed_by_scan', '1');
	}
	update_post_meta($sourceId, '_oras_ai_kb_entry_id', $kbId);
	return $kbId;
}

oras_ai_test('static source creates then reuses one managed knowledge entry', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('oras_speaker', 'Dr. Nova', 'Original biography');
	$sources = new ORAS_AI_Sources();

	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$kbId = $first['kb_id'];
	oras_ai_assert_same('complete', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Static source should complete.');
	oras_ai_assert_same('approved', get_post_meta($kbId, '_oras_ai_status', true), 'High-confidence speaker should auto-approve.');
	oras_ai_assert_same('1', get_post_meta($kbId, '_oras_ai_managed_by_scan', true), 'Created KB should be scanner-managed.');
	oras_ai_assert_same($sourceId, get_post_meta($kbId, '_oras_ai_source_record_id', true), 'Created KB should link back to source.');

	wp_update_post(array('ID' => $sourceId, 'post_title' => 'Dr. Nova Updated', 'post_content' => 'Updated biography'));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$knowledgeIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);

	oras_ai_assert_same($kbId, $second['kb_id'], 'Repeated processing should reuse the linked KB.');
	oras_ai_assert_same(array($kbId), $knowledgeIds, 'Repeated processing should not create duplicate KB entries.');
	oras_ai_assert_same('Dr. Nova Updated', get_post($kbId)->post_title, 'Reused KB title should update.');
	oras_ai_assert_same('Updated biography', get_post_meta($kbId, '_oras_ai_official_answer', true), 'Reused KB content should update.');
});

oras_ai_test('review source creates then updates one review knowledge entry', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Mixed page', 'Mixed facts and changing details');
	$classification = oras_ai_test_classification(
		array(
			'source_kind' => 'review',
			'confidence' => 'medium',
			'knowledge_title' => 'Mixed page review',
		)
	);
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode($classification)));
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode($classification)));
	$sources = new ORAS_AI_Sources();

	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Review source status changed.');
	oras_ai_assert_same($first['kb_id'], $second['kb_id'], 'Review reprocessing should reuse its KB.');
	oras_ai_assert_same('review', get_post_meta($first['kb_id'], '_oras_ai_status', true), 'Review KB status changed.');
	oras_ai_assert_same('1', get_post_meta($first['kb_id'], '_oras_ai_managed_by_scan', true), 'Review KB should remain scanner-managed.');
});

oras_ai_test('live reclassification retires scanner-managed knowledge', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('tribe_events', 'Upcoming event');
	$kbId = oras_ai_test_add_linked_kb($sourceId, true);

	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($sourceId));

	oras_ai_assert_same('live_data', $result['kind'], 'Event should reclassify as live data.');
	oras_ai_assert_same('live', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Live source status changed.');
	oras_ai_assert_same('retired', get_post_meta($kbId, '_oras_ai_status', true), 'Managed KB should retire after live reclassification.');
});

oras_ai_test('ignored reclassification retires scanner-managed knowledge', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('elementor_library', 'Template');
	$kbId = oras_ai_test_add_linked_kb($sourceId, true);

	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($sourceId));

	oras_ai_assert_same('ignore', $result['kind'], 'Template should reclassify as ignored.');
	oras_ai_assert_same('ignored', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Ignored source status changed.');
	oras_ai_assert_same('retired', get_post_meta($kbId, '_oras_ai_status', true), 'Managed KB should retire after ignored reclassification.');
});

oras_ai_test('manual knowledge is protected during live or ignored reclassification cleanup', function (): void {
	foreach (array('tribe_events', 'elementor_library') as $postType) {
		oras_ai_test_reset();
		$sourceId = oras_ai_test_add_source($postType, 'Reclassified source');
		$kbId = oras_ai_test_add_linked_kb($sourceId, false);

		oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($sourceId));

		oras_ai_assert_same('approved', get_post_meta($kbId, '_oras_ai_status', true), "Manual KB should survive {$postType} cleanup.");
		oras_ai_assert_same('', get_post_meta($kbId, '_oras_ai_managed_by_scan', true), 'Manual KB should remain unmarked.');
	}
});

oras_ai_test('OBSERVED LEGACY DEFECT missing source retires a linked manual knowledge entry', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Deleted WordPress page');
	$manualKbId = oras_ai_test_add_linked_kb($sourceId, false);

	oras_ai_invoke_private(new ORAS_AI_Sources(), 'retire_missing_sources', array(array()));

	oras_ai_assert_same('missing', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Missing source status changed.');
	oras_ai_assert_same(
		'retired',
		get_post_meta($manualKbId, '_oras_ai_status', true),
		'Observed v0.2.1 behavior: missing-source cleanup retires linked manual KB without checking the managed marker.'
	);
	oras_ai_assert_same('', get_post_meta($manualKbId, '_oras_ai_managed_by_scan', true), 'Fixture must remain a manual KB entry.');
});
