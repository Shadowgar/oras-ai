<?php
declare(strict_types=1);

function oras_ai_test_find_source_for_post(int $postId): int {
	$ids = get_posts(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_oras_ai_wp_post_id',
			'meta_value' => $postId,
		)
	);
	return empty($ids) ? 0 : (int) $ids[0];
}

function oras_ai_test_create_processed_speaker(): array {
	$postId = oras_ai_test_add_post(
		array(
			'post_type' => 'oras_speaker',
			'post_title' => 'Dr. Version',
			'post_content' => 'Stable speaker biography',
		)
	);
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$result = oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	return array($sources, $sourceId, $result);
}

oras_ai_test('source discovery includes public content and excludes attachments and ORAS AI internals', function (): void {
	oras_ai_test_reset();
	$pageId = oras_ai_test_add_post(
		array(
			'post_type' => 'page',
			'post_title' => 'Observatory Guide',
			'post_content' => '<p>Visit the observatory.</p>',
			'post_name' => 'observatory-guide',
			'post_modified_gmt' => '2026-08-26 11:00:00',
		)
	);
	$postId = oras_ai_test_add_post(
		array(
			'post_type' => 'post',
			'post_title' => 'Club News',
			'post_content' => 'Club news text.',
			'post_name' => 'club-news',
		)
	);
	oras_ai_test_add_post(array('post_type' => 'attachment', 'post_title' => 'Image', 'post_content' => 'Attachment text'));
	oras_ai_test_add_post(array('post_type' => ORAS_AI_Knowledge_Base::POST_TYPE, 'post_title' => 'Internal KB', 'post_content' => 'Private'));
	oras_ai_test_add_post(array('post_type' => ORAS_AI_Sources::POST_TYPE, 'post_title' => 'Internal source', 'post_content' => 'Private'));

	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'discover_wordpress_sources', array(false));

	oras_ai_assert_same(2, $result['found'], 'Only the two public non-internal source records should be discovered.');
	oras_ai_assert_same(2, count($result['queue']), 'New public sources should be queued.');
	$pageSourceId = oras_ai_test_find_source_for_post($pageId);
	$postSourceId = oras_ai_test_find_source_for_post($postId);
	oras_ai_assert_true($pageSourceId > 0 && $postSourceId > 0, 'Both public WordPress types should create source records.');
	oras_ai_assert_same('page', get_post_meta($pageSourceId, '_oras_ai_wp_post_type', true), 'Page source type linkage changed.');
	oras_ai_assert_same('pending', get_post_meta($pageSourceId, '_oras_ai_scan_status', true), 'New source status changed.');
	oras_ai_assert_same(0, oras_ai_test_find_source_for_post(102), 'Attachment should not create a source record.');
	oras_ai_assert_same(0, oras_ai_test_find_source_for_post(103), 'Knowledge Base records should not create source records.');
});

oras_ai_test('source discovery hashes title permalink and extracted content with SHA-256', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(
		array(
			'post_type' => 'page',
			'post_title' => 'Facilities',
			'post_content' => '<strong>Warm room</strong>',
			'post_name' => 'facilities',
			'permalink' => 'https://oras.org/facilities/',
		)
	);
	oras_ai_invoke_private(new ORAS_AI_Sources(), 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$expected = hash('sha256', 'Facilities|https://oras.org/facilities/|Warm room');
	oras_ai_assert_same($expected, get_post_meta($sourceId, '_oras_ai_source_hash', true), 'Source hash formula changed.');
	oras_ai_assert_same('Warm room', get_post($sourceId)->post_content, 'Extracted source content changed.');
});

oras_ai_test('normal scan skips an unchanged completed source', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'Stable', 'post_content' => 'Same content'));
	$sources = new ORAS_AI_Sources();
	$first = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	update_post_meta($sourceId, '_oras_ai_scan_status', 'complete');
	update_post_meta($sourceId, '_oras_ai_extraction_version', ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION);
	$second = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));

	oras_ai_assert_same(array($sourceId), $first['queue'], 'New source should initially queue.');
	oras_ai_assert_same(array(), $second['queue'], 'Unchanged completed source should not queue on normal scan.');
	oras_ai_assert_same('complete', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Unchanged status should remain complete.');
});

oras_ai_test('normal scan requeues changed content and updates its source hash', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'Changing', 'post_content' => 'Version one'));
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$oldHash = get_post_meta($sourceId, '_oras_ai_source_hash', true);
	update_post_meta($sourceId, '_oras_ai_scan_status', 'complete');
	wp_update_post(array('ID' => $postId, 'post_content' => 'Version two'));

	$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));

	oras_ai_assert_same(array($sourceId), $result['queue'], 'Changed source should queue on normal scan.');
	oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Changed source should return to pending.');
	oras_ai_assert_true($oldHash !== get_post_meta($sourceId, '_oras_ai_source_hash', true), 'Changed content should produce a new hash.');
});

oras_ai_test('rebuild scan requeues an unchanged terminal source', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'Stable', 'post_content' => 'Same content'));
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	update_post_meta($sourceId, '_oras_ai_scan_status', 'ignored');

	$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));

	oras_ai_assert_same(array($sourceId), $result['queue'], 'Rebuild should queue an unchanged terminal source.');
	oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Rebuild should set queued source to pending.');
});

oras_ai_test('processed source stores current rule and extraction versions and remains skipped', function (): void {
	oras_ai_test_reset();
	list($sources, $sourceId) = oras_ai_test_create_processed_speaker();

	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_rule_version', true), 'Processed source should store rule version one.');
	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_extraction_version', true), 'Processed source should store extraction version one.');
	$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	oras_ai_assert_same(array(), $result['queue'], 'Matching rule and extraction versions should preserve unchanged-source skip behavior.');
});

oras_ai_test('missing or stale extraction version requeues an unchanged source', function (): void {
	foreach (array('', 999) as $storedVersion) {
		oras_ai_test_reset();
		list($sources, $sourceId) = oras_ai_test_create_processed_speaker();
		if ('' === $storedVersion) {
			delete_post_meta($sourceId, '_oras_ai_extraction_version');
		} else {
			update_post_meta($sourceId, '_oras_ai_extraction_version', $storedVersion);
		}

		$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));

		oras_ai_assert_same(array($sourceId), $result['queue'], 'Extraction-version mismatch should queue unchanged source.');
		oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Stale extraction source should become pending.');
	}
});

oras_ai_test('missing rule version is legacy version one and does not requeue', function (): void {
	oras_ai_test_reset();
	list($sources, $sourceId) = oras_ai_test_create_processed_speaker();
	delete_post_meta($sourceId, '_oras_ai_rule_version');

	$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));

	oras_ai_assert_same(array(), $result['queue'], 'Missing legacy rule version must not force a version-one rescan.');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_rule_version', true), 'Discovery should not migrate missing rule metadata.');
});

oras_ai_test('stale rule version requeues and reprocesses without changing output or duplicating records', function (): void {
	oras_ai_test_reset();
	list($sources, $sourceId, $first) = oras_ai_test_create_processed_speaker();
	update_post_meta($sourceId, '_oras_ai_rule_version', 999);

	$discovery = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	oras_ai_assert_same(array($sourceId), $discovery['queue'], 'Stale rule version should queue an unchanged source.');
	oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Stale rule source should become pending.');

	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$sourceIds = get_posts(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);
	$knowledgeIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);

	oras_ai_assert_same('static_knowledge', $second['kind'], 'Version invalidation must not change deterministic output.');
	oras_ai_assert_same('Events', $second['category'], 'Version invalidation must not change deterministic category.');
	oras_ai_assert_same($first['kb_id'], $second['kb_id'], 'Rule reprocessing should reuse the linked Knowledge Base entry.');
	oras_ai_assert_same(array($sourceId), $sourceIds, 'Rule reprocessing should not duplicate source records.');
	oras_ai_assert_same(array($first['kb_id']), $knowledgeIds, 'Rule reprocessing should not duplicate Knowledge Base entries.');
	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_rule_version', true), 'Successful reprocessing should store current rule version.');
});

oras_ai_test('rebuild queues an unchanged source with matching rule version', function (): void {
	oras_ai_test_reset();
	list($sources, $sourceId) = oras_ai_test_create_processed_speaker();

	$result = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));

	oras_ai_assert_same(array($sourceId), $result['queue'], 'Rebuild should ignore matching rule version and queue the source.');
	oras_ai_assert_same('pending', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Rebuild should set current-version source to pending.');
});

oras_ai_test('AT-KB-001 normal and rebuild cycles preserve one static artifact and stable sync identity', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'oras_speaker', 'post_title' => 'Dr. Repeat', 'post_content' => 'Stable biography'));
	$sources = new ORAS_AI_Sources();
	$initialDiscovery = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$firstSyncTime = get_post_meta($first['kb_id'], '_oras_ai_synced_at', true);
	$firstWriteCounts = array(
		$GLOBALS['oras_ai_test_post_writes'][$first['kb_id']] ?? 0,
		$GLOBALS['oras_ai_test_meta_writes'][$first['kb_id']] ?? 0,
		$GLOBALS['oras_ai_test_term_writes'][$first['kb_id']] ?? 0,
	);
	$GLOBALS['oras_ai_test_now_mysql'] = '2026-08-28 09:00:00';

	$normal = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$rebuildOne = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$rebuildTwo = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));
	$third = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$artifactIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);

	oras_ai_assert_same(array($sourceId), $initialDiscovery['queue'], 'Initial source should queue exactly once.');
	oras_ai_assert_same(array(), $normal['queue'], 'Unchanged normal sync should skip current versions.');
	oras_ai_assert_same(array($sourceId), $rebuildOne['queue'], 'First rebuild should force processing.');
	oras_ai_assert_same(array($sourceId), $rebuildTwo['queue'], 'Second rebuild should force processing.');
	oras_ai_assert_same($first['kb_id'], $second['kb_id'], 'First rebuild changed static artifact identity.');
	oras_ai_assert_same($first['kb_id'], $third['kb_id'], 'Second rebuild changed static artifact identity.');
	oras_ai_assert_same(array($first['kb_id']), $artifactIds, 'Repeated rebuild created a duplicate static artifact.');
	oras_ai_assert_same($firstSyncTime, get_post_meta($first['kb_id'], '_oras_ai_synced_at', true), 'Unchanged rebuild churned artifact synchronization time.');
	oras_ai_assert_same(
		$firstWriteCounts,
		array(
			$GLOBALS['oras_ai_test_post_writes'][$first['kb_id']] ?? 0,
			$GLOBALS['oras_ai_test_meta_writes'][$first['kb_id']] ?? 0,
			$GLOBALS['oras_ai_test_term_writes'][$first['kb_id']] ?? 0,
		),
		'Unchanged rebuild rewrote the managed artifact.'
	);
});

oras_ai_test('stale extraction version reprocesses without duplicating the managed artifact', function (): void {
	oras_ai_test_reset();
	list($sources, $sourceId, $first) = oras_ai_test_create_processed_speaker();
	update_post_meta($sourceId, '_oras_ai_extraction_version', 999);

	$discovery = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$artifactIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);

	oras_ai_assert_same(array($sourceId), $discovery['queue'], 'Stale extraction version should queue the source.');
	oras_ai_assert_same($first['kb_id'], $second['kb_id'], 'Extraction invalidation changed artifact identity.');
	oras_ai_assert_same(array($first['kb_id']), $artifactIds, 'Extraction invalidation created a duplicate artifact.');
	oras_ai_assert_same(ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION, get_post_meta($sourceId, '_oras_ai_extraction_version', true), 'Successful reprocessing did not restore current extraction version.');
});

oras_ai_test('AT-KB-007 manual snapshot survives unchanged normal sync and rebuild', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'oras_speaker', 'post_title' => 'Manual protection speaker', 'post_content' => 'Stable biography'));
	$sources = new ORAS_AI_Sources();
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$managed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
	$before = oras_ai_test_manual_snapshot($manualId);

	$normal = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$rebuild = oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));
	$processed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	oras_ai_assert_same(array(), $normal['queue'], 'Unchanged normal scan should skip the source.');
	oras_ai_assert_same(array($sourceId), $rebuild['queue'], 'Rebuild should queue the source.');
	oras_ai_assert_same($before, oras_ai_test_manual_snapshot($manualId), 'Normal/rebuild scanner cycle changed manual knowledge.');
	oras_ai_assert_same($managed['kb_id'], $processed['kb_id'], 'Rebuild should reuse the existing managed artifact.');
	oras_ai_assert_same($managed['kb_id'], get_post_meta($sourceId, '_oras_ai_kb_entry_id', true), 'Rebuild should repair the source primary to managed knowledge.');
});
