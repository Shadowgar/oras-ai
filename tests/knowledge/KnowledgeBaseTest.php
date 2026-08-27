<?php
declare(strict_types=1);

oras_ai_test('knowledge base registers its private post type and taxonomy', function (): void {
	oras_ai_test_reset();

	$knowledgeBase = new ORAS_AI_Knowledge_Base();
	$knowledgeBase->register_content_types();

	oras_ai_assert_same(
		false,
		$GLOBALS['oras_ai_test_registered_post_types']['oras_ai_knowledge']['public'],
		'Knowledge Base post type should remain private.'
	);
	oras_ai_assert_same(
		true,
		$GLOBALS['oras_ai_test_registered_taxonomies']['oras_ai_category']['args']['hierarchical'],
		'Knowledge Base taxonomy should remain hierarchical.'
	);
	oras_ai_assert_same(
		'oras_ai_knowledge',
		$GLOBALS['oras_ai_test_registered_taxonomies']['oras_ai_category']['object_type'],
		'Taxonomy should remain attached to the Knowledge Base post type.'
	);
	oras_ai_assert_same(
		array('title', 'revisions', 'author'),
		$GLOBALS['oras_ai_test_registered_post_types']['oras_ai_knowledge']['supports'],
		'Knowledge Base post support should remain unchanged.'
	);
});

oras_ai_test('knowledge base exposes and seeds the v0.2.1 default categories', function (): void {
	oras_ai_test_reset();
	$expected = array(
		'Membership',
		'Observatory Access',
		'Observer Passes',
		'Telescopes & Equipment',
		'Events',
		'AstroBlast',
		'Public Nights',
		'Facilities',
		'Volunteering',
		'Policies & Rules',
		'Website / Technical Help',
		'Payments / Treasurer',
		'Board & Organization',
		'Directions / Parking / Accessibility',
		'Contacts & Question Routing',
		'General FAQ',
	);

	$knowledgeBase = new ORAS_AI_Knowledge_Base();
	$knowledgeBase->register_content_types();
	ORAS_AI_Knowledge_Base::seed_default_categories();

	oras_ai_assert_same($expected, ORAS_AI_Knowledge_Base::default_categories(), 'Default category names changed.');
	oras_ai_assert_same(16, count($GLOBALS['oras_ai_test_terms']), 'Every default category should be seeded once.');
	ORAS_AI_Knowledge_Base::seed_default_categories();
	oras_ai_assert_same(16, count($GLOBALS['oras_ai_test_terms']), 'Repeated category seeding should not duplicate terms.');
});

oras_ai_test('manual knowledge save persists current meta names status visibility and category', function (): void {
	oras_ai_test_reset();
	$knowledgeBase = new ORAS_AI_Knowledge_Base();
	$knowledgeBase->register_content_types();
	$category = wp_insert_term('Facilities', ORAS_AI_Knowledge_Base::TAXONOMY);
	$_POST = array(
		'oras_ai_entry_nonce' => 'valid',
		'oras_ai_visibility' => 'public',
		'oras_ai_status' => 'approved',
		'oras_ai_official_answer' => '<p>Use the upper field.</p>',
		'oras_ai_source' => 'Observatory guide',
		'oras_ai_source_url' => 'https://oras.org/observatory/',
		'oras_ai_responsible_group' => 'Observatory Committee',
		'oras_ai_escalation_contact' => 'board@example.test',
		'oras_ai_last_reviewed' => '2026-08-20',
		'oras_ai_internal_notes' => "First line\nSecond line",
		'oras_ai_category' => (string) $category['term_id'],
	);

	$knowledgeBase->save_entry(42);

	$expectedMeta = array(
		'_oras_ai_visibility' => 'public',
		'_oras_ai_status' => 'approved',
		'_oras_ai_official_answer' => '<p>Use the upper field.</p>',
		'_oras_ai_source' => 'Observatory guide',
		'_oras_ai_responsible_group' => 'Observatory Committee',
		'_oras_ai_escalation_contact' => 'board@example.test',
		'_oras_ai_last_reviewed' => '2026-08-20',
		'_oras_ai_source_url' => 'https://oras.org/observatory/',
		'_oras_ai_internal_notes' => "First line\nSecond line",
	);
	oras_ai_assert_same($expectedMeta, get_post_meta(42), 'Manual save meta contract changed.');
	oras_ai_assert_same(
		array((int) $category['term_id']),
		wp_get_post_terms(42, ORAS_AI_Knowledge_Base::TAXONOMY),
		'Manual save should replace the category relationship.'
	);
});

oras_ai_test('manual knowledge save falls back invalid status and visibility to v0.2.1 defaults', function (): void {
	oras_ai_test_reset();
	$_POST = array(
		'oras_ai_entry_nonce' => 'valid',
		'oras_ai_visibility' => 'subscriber',
		'oras_ai_status' => 'active',
	);

	(new ORAS_AI_Knowledge_Base())->save_entry(43);

	oras_ai_assert_same('members', get_post_meta(43, '_oras_ai_visibility', true), 'Invalid visibility should fall back to members.');
	oras_ai_assert_same('draft', get_post_meta(43, '_oras_ai_status', true), 'Invalid status should fall back to draft.');
});

oras_ai_test('scanned knowledge upsert records managed marker and source linkage', function (): void {
	oras_ai_test_reset();
	$knowledgeBase = new ORAS_AI_Knowledge_Base();
	$knowledgeBase->register_content_types();
	$entryId = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
		array(
			'source_id' => 77,
			'title' => 'Speaker biography',
			'content' => 'Biography text',
			'category' => 'Events',
			'visibility' => 'public',
			'status' => 'approved',
			'source_label' => 'ORAS Website – Speaker biography',
			'source_url' => 'https://oras.org/speaker/',
			'internal_notes' => 'Automatically managed.',
		)
	);

	oras_ai_assert_same('oras_ai_knowledge', get_post_type($entryId), 'Scanned entry should use the Knowledge Base post type.');
	oras_ai_assert_same('1', get_post_meta($entryId, '_oras_ai_managed_by_scan', true), 'Scanner marker changed.');
	oras_ai_assert_same(77, get_post_meta($entryId, '_oras_ai_source_record_id', true), 'Source record linkage changed.');
	oras_ai_assert_same('approved', get_post_meta($entryId, '_oras_ai_status', true), 'Scanned status changed.');
	oras_ai_assert_same('public', get_post_meta($entryId, '_oras_ai_visibility', true), 'Scanned visibility changed.');
	oras_ai_assert_same('2026-08-27', get_post_meta($entryId, '_oras_ai_last_reviewed', true), 'Approved scans should set last reviewed.');
});
