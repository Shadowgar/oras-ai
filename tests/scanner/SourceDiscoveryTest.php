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
