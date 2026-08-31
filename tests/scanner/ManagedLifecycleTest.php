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
		update_post_meta($kbId, '_oras_ai_source_record_id', $sourceId);
	}
	update_post_meta($sourceId, '_oras_ai_kb_entry_id', $kbId);
	return $kbId;
}

function oras_ai_test_manual_snapshot(int $artifactId): array {
	$post = get_post($artifactId);
	$meta = get_post_meta($artifactId);
	ksort($meta);
	return array(
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
		'post_status' => $post->post_status,
		'meta' => $meta,
		'terms' => wp_get_post_terms($artifactId, ORAS_AI_Knowledge_Base::TAXONOMY),
	);
}

function oras_ai_test_prepare_manual_artifact(int $sourceId): int {
	$artifactId = oras_ai_test_add_linked_kb($sourceId, false);
	wp_update_post(
		array(
			'ID' => $artifactId,
			'post_title' => 'Owner-authored manual knowledge',
			'post_content' => 'Manual post body',
		)
	);
	update_post_meta($artifactId, '_oras_ai_official_answer', 'Owner-authored answer');
	update_post_meta($artifactId, '_oras_ai_visibility', 'members');
	update_post_meta($artifactId, '_oras_ai_source_record_id', $sourceId);
	update_post_meta($artifactId, '_oras_ai_source_hash', 'manual-source-hash');
	update_post_meta($artifactId, '_oras_ai_internal_notes', 'Owner notes');
	$term = wp_insert_term('Facilities', ORAS_AI_Knowledge_Base::TAXONOMY);
	wp_set_post_terms($artifactId, array((int) $term['term_id']), ORAS_AI_Knowledge_Base::TAXONOMY, false);
	return $artifactId;
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

oras_ai_test('AT-KB-007 manual artifact survives every scanner disposition unchanged', function (): void {
	$scenarios = array(
		'static' => array('page', oras_ai_test_classification()),
		'review' => array('page', oras_ai_test_classification(array('source_kind' => 'review', 'confidence' => 'medium'))),
		'mixed' => array('page', oras_ai_test_mixed_classification()),
		'live' => array('tribe_events', null),
		'ignore' => array('elementor_library', null),
	);

	foreach ($scenarios as $name => $scenario) {
		oras_ai_test_reset();
		$sourceId = oras_ai_test_add_source($scenario[0], ucfirst($name) . ' source');
		$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
		$before = oras_ai_test_manual_snapshot($manualId);
		$classifier = null === $scenario[1]
			? null
			: oras_ai_test_classifier_result(ORAS_AI_Source_Classification_Result::from_array($scenario[1], 'ai', ucfirst($name) . ' source'));

		oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

		oras_ai_assert_same($before, oras_ai_test_manual_snapshot($manualId), "Manual artifact changed during {$name} processing.");
		oras_ai_assert_same('', get_post_meta($manualId, '_oras_ai_managed_by_scan', true), "Manual artifact gained ownership during {$name} processing.");
	}
});

oras_ai_test('scanner upsert never adopts a source-linked manual artifact', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Manual-linked source');
	$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
	$before = oras_ai_test_manual_snapshot($manualId);

	$managedId = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
		array(
			'source_id' => $sourceId,
			'title' => 'Scanner artifact',
			'content' => 'Scanner content',
			'category' => 'General FAQ',
			'status' => 'review',
		)
	);

	oras_ai_assert_true($manualId !== $managedId, 'Source linkage without exact ownership marker must not authorize reuse.');
	oras_ai_assert_same($before, oras_ai_test_manual_snapshot($manualId), 'Scanner upsert changed source-linked manual knowledge.');
	oras_ai_assert_true(ORAS_AI_Knowledge_Base::is_scanner_managed($managedId), 'Replacement scanner artifact should carry exact ownership.');
});

oras_ai_test('AT-KB-007 missing source preserves a linked manual artifact unchanged', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Deleted WordPress page');
	$manualKbId = oras_ai_test_prepare_manual_artifact($sourceId);
	$before = oras_ai_test_manual_snapshot($manualKbId);

	oras_ai_invoke_private(new ORAS_AI_Sources(), 'retire_missing_sources', array(array()));

	oras_ai_assert_same('missing', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Missing source status changed.');
	oras_ai_assert_same($before, oras_ai_test_manual_snapshot($manualKbId), 'Missing-source cleanup changed manual knowledge.');
});

oras_ai_test('AT-KB-006 missing source retires every managed artifact and preserves provenance', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_source('page', 'Deleted Mixed source');
	$firstId = oras_ai_test_add_linked_kb($sourceId, true);
	$secondId = oras_ai_test_add_linked_kb($sourceId, true);
	delete_post_meta($secondId, '_oras_ai_source_record_id');
	update_post_meta($firstId, '_oras_ai_source_hash', 'first-provenance');
	update_post_meta($secondId, '_oras_ai_source_hash', 'second-provenance');
	update_post_meta($sourceId, '_oras_ai_kb_entry_id', $firstId);
	update_post_meta($sourceId, '_oras_ai_kb_entry_ids', array($firstId, $secondId));

	oras_ai_invoke_private(new ORAS_AI_Sources(), 'retire_missing_sources', array(array()));

	oras_ai_assert_same('missing', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Missing Mixed source should enter missing state.');
	oras_ai_assert_same('retired', get_post_meta($firstId, '_oras_ai_status', true), 'First managed artifact should retire.');
	oras_ai_assert_same('retired', get_post_meta($secondId, '_oras_ai_status', true), 'Second managed artifact should retire.');
	oras_ai_assert_same('first-provenance', get_post_meta($firstId, '_oras_ai_source_hash', true), 'First artifact provenance was erased.');
	oras_ai_assert_same('second-provenance', get_post_meta($secondId, '_oras_ai_source_hash', true), 'Second artifact provenance was erased.');
});
