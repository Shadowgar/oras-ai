<?php
declare(strict_types=1);

function oras_ai_test_classifier_result($result): ORAS_AI_Source_Classifier_Interface {
	return new class($result) implements ORAS_AI_Source_Classifier_Interface {
		private $result;

		public function __construct($result) {
			$this->result = $result;
		}

		public function classify_source($title, $url, $post_type, $content) {
			return $this->result;
		}
	};
}

function oras_ai_test_prepare_provenance_source(string $title = 'About AstroBlast', string $content = 'Full mixed source content'): int {
	$sourceId = oras_ai_test_add_source('page', $title, $content);
	update_post_meta($sourceId, '_oras_ai_wp_post_id', 501);
	update_post_meta($sourceId, '_oras_ai_source_hash', 'source-hash-v1');
	update_post_meta($sourceId, '_oras_ai_wp_modified_gmt', '2026-08-26 11:00:00');
	return $sourceId;
}

function oras_ai_test_two_fragment_mixed_result(array $overrides = array()): ORAS_AI_Source_Classification_Result {
	return ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(
			array_merge(
				array(
					'stable_fragments' => array(
						array(
							'stable_title' => 'About AstroBlast',
							'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.',
						),
						array(
							'stable_title' => 'AstroBlast Program',
							'stable_content' => 'The program combines astronomy talks and observing activities.',
						),
					),
					'excluded_dynamic_claims' => array(
						'Tickets are $25.',
						'AstroBlast begins August 21, 2026.',
						'Registration closes August 1, 2026.',
						'Tickets are currently available.',
						'The current schedule starts at 7:00 PM.',
					),
					'dynamic_fact_types' => array('ticket_price', 'event_date', 'registration_deadline', 'availability', 'event_schedule'),
				),
				$overrides
			)
		),
		'ai',
		'About AstroBlast'
	);
}

oras_ai_test('AT-KB-005 mixed source persists one review artifact per stable fragment with complete provenance', function (): void {
	oras_ai_test_reset();
	$sourceContent = 'AstroBlast is ORAS\'s annual astronomy gathering. Tickets are $25 on August 21, 2026. Registration closes August 1, tickets are currently available, and the current schedule starts at 7:00 PM.';
	$sourceId = oras_ai_test_prepare_provenance_source('About AstroBlast', $sourceContent);
	$result = oras_ai_invoke_private(
		new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result())),
		'process_source',
		array($sourceId)
	);

	$artifactIds = (array) get_post_meta($sourceId, '_oras_ai_kb_entry_ids', true);
	oras_ai_assert_same(2, count($artifactIds), 'Each stable fragment should have one artifact.');
	oras_ai_assert_same($artifactIds, $result['kb_ids'], 'Process result should expose the ordered artifact set.');
	oras_ai_assert_same($artifactIds[0], $result['kb_id'], 'Primary KB ID should be the first stable fragment.');
	oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Mixed source should remain in review.');
	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_extraction_version', true), 'Source should retain extraction version one.');

	$expectedAnswers = array(
		'AstroBlast is ORAS\'s annual astronomy gathering.',
		'The program combines astronomy talks and observing activities.',
	);
	$expectedDynamicClaims = array(
		'Tickets are $25.',
		'AstroBlast begins August 21, 2026.',
		'Registration closes August 1, 2026.',
		'Tickets are currently available.',
		'The current schedule starts at 7:00 PM.',
	);

	foreach ($artifactIds as $index => $artifactId) {
		$answer = get_post_meta($artifactId, '_oras_ai_official_answer', true);
		oras_ai_assert_same($expectedAnswers[$index], $answer, 'Artifact official answer must contain only its stable fragment.');
		foreach ($expectedDynamicClaims as $claim) {
			oras_ai_assert_not_contains($claim, $answer, 'Dynamic claim leaked into durable official answer.');
		}

		oras_ai_assert_same($sourceId, get_post_meta($artifactId, '_oras_ai_source_record_id', true), 'Source record provenance missing.');
		oras_ai_assert_same(501, get_post_meta($artifactId, '_oras_ai_source_wp_post_id', true), 'WordPress source ID provenance missing.');
		oras_ai_assert_same('page', get_post_meta($artifactId, '_oras_ai_source_wp_post_type', true), 'WordPress source type provenance missing.');
		oras_ai_assert_same('https://oras.org/source-' . $sourceId . '/', get_post_meta($artifactId, '_oras_ai_source_url', true), 'Source URL provenance missing.');
		oras_ai_assert_same('source-hash-v1', get_post_meta($artifactId, '_oras_ai_source_hash', true), 'Source hash provenance missing.');
		oras_ai_assert_same(hash('sha256', $expectedAnswers[$index]), get_post_meta($artifactId, '_oras_ai_content_hash', true), 'Artifact content hash missing.');
		oras_ai_assert_same('2026-08-26 11:00:00', get_post_meta($artifactId, '_oras_ai_source_modified_gmt', true), 'Source modified-time provenance missing.');
		oras_ai_assert_same('2026-08-27 12:34:56', get_post_meta($artifactId, '_oras_ai_synced_at', true), 'Sync time provenance missing.');
		oras_ai_assert_same(1, get_post_meta($artifactId, '_oras_ai_rule_version', true), 'Rule version provenance missing.');
		oras_ai_assert_same(1, get_post_meta($artifactId, '_oras_ai_extraction_version', true), 'Extraction version provenance missing.');
		oras_ai_assert_same('1', get_post_meta($artifactId, '_oras_ai_managed_by_scan', true), 'Scanner ownership provenance missing.');
		oras_ai_assert_same('review', get_post_meta($artifactId, '_oras_ai_status', true), 'Mixed artifacts must begin in review.');
		oras_ai_assert_same('mixed', get_post_meta($artifactId, '_oras_ai_source_classification', true), 'Classification provenance missing.');
		oras_ai_assert_same('high', get_post_meta($artifactId, '_oras_ai_source_confidence', true), 'Confidence provenance missing.');
		oras_ai_assert_same('0', get_post_meta($artifactId, '_oras_ai_historical_event', true), 'Historical designation provenance missing.');
		oras_ai_assert_same($index, get_post_meta($artifactId, '_oras_ai_fragment_index', true), 'Fragment ordinal provenance missing.');
		oras_ai_assert_same($expectedDynamicClaims, get_post_meta($artifactId, '_oras_ai_excluded_dynamic_claims', true), 'Excluded claims must remain provenance context.');
		oras_ai_assert_same(
			array('ticket_price', 'event_date', 'registration_deadline', 'availability', 'event_schedule'),
			get_post_meta($artifactId, '_oras_ai_dynamic_fact_types', true),
			'Dynamic fact types must remain provenance context.'
		);
		oras_ai_assert_same(
			'The source combines durable program information with current registration details.',
			get_post_meta($artifactId, '_oras_ai_extraction_reason', true),
			'Extraction reason provenance missing.'
		);
		oras_ai_assert_same('public', get_post_meta($artifactId, '_oras_ai_visibility', true), 'Visibility policy changed.');
		$categoryId = term_exists('AstroBlast', ORAS_AI_Knowledge_Base::TAXONOMY);
		oras_ai_assert_same(array((int) $categoryId), wp_get_post_terms($artifactId, ORAS_AI_Knowledge_Base::TAXONOMY), 'Category taxonomy changed.');
	}
});

oras_ai_test('successful mixed extraction reuses the Task 1 managed review record as fragment zero', function (): void {
	oras_ai_test_reset();
	$sourceContent = 'Whole source with stable description and tickets are $25.';
	$sourceId = oras_ai_test_prepare_provenance_source('About AstroBlast', $sourceContent);
	$interimId = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
		array(
			'source_id' => $sourceId,
			'title' => 'About AstroBlast review',
			'content' => $sourceContent,
			'category' => 'AstroBlast',
			'visibility' => 'public',
			'status' => 'review',
		)
	);
	update_post_meta($sourceId, '_oras_ai_kb_entry_id', $interimId);

	oras_ai_invoke_private(
		new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result())),
		'process_source',
		array($sourceId)
	);

	$artifactIds = (array) get_post_meta($sourceId, '_oras_ai_kb_entry_ids', true);
	$allKbIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);
	oras_ai_assert_same($interimId, $artifactIds[0], 'Task 1 managed review record should become fragment zero.');
	oras_ai_assert_same($interimId, get_post_meta($sourceId, '_oras_ai_kb_entry_id', true), 'Primary managed link should remain stable.');
	oras_ai_assert_same(2, count($allKbIds), 'Task 1 whole-source record must not remain as an extra active artifact.');
	oras_ai_assert_same('AstroBlast is ORAS\'s annual astronomy gathering.', get_post_meta($interimId, '_oras_ai_official_answer', true), 'Interim whole-source answer should be replaced by stable fragment zero.');
	oras_ai_assert_not_contains('$25', get_post_meta($interimId, '_oras_ai_official_answer', true), 'Interim dynamic content must be removed.');
});

oras_ai_test('reprocessing the same mixed source reuses the ordered artifact set', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_prepare_provenance_source();
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result()));

	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$allKbIds = get_posts(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		)
	);

	oras_ai_assert_same($first['kb_ids'], $second['kb_ids'], 'Immediate reprocessing must reuse fragment artifacts by ordinal.');
	oras_ai_assert_same(2, count($allKbIds), 'Immediate reprocessing must not create duplicate artifacts.');
});

oras_ai_test('mixed migration repairs source linkage without changing a manually linked KB entry', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_prepare_provenance_source();
	$manualId = oras_ai_test_add_linked_kb($sourceId, false);
	wp_update_post(array('ID' => $manualId, 'post_title' => 'Manual knowledge'));
	update_post_meta($manualId, '_oras_ai_official_answer', 'Owner-authored answer');

	oras_ai_invoke_private(
		new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result())),
		'process_source',
		array($sourceId)
	);

	$artifactIds = (array) get_post_meta($sourceId, '_oras_ai_kb_entry_ids', true);
	oras_ai_assert_same($artifactIds[0], get_post_meta($sourceId, '_oras_ai_kb_entry_id', true), 'Source primary should be repaired to the managed fragment.');
	oras_ai_assert_false(in_array($manualId, $artifactIds, true), 'Manual KB must not enter the managed fragment set.');
	oras_ai_assert_same('Manual knowledge', get_post($manualId)->post_title, 'Manual title changed.');
	oras_ai_assert_same('Owner-authored answer', get_post_meta($manualId, '_oras_ai_official_answer', true), 'Manual answer changed.');
	oras_ai_assert_same('approved', get_post_meta($manualId, '_oras_ai_status', true), 'Manual status changed.');
	oras_ai_assert_same('', get_post_meta($manualId, '_oras_ai_managed_by_scan', true), 'Manual KB gained scanner ownership.');
});

oras_ai_test('invalid mixed extraction reuses review state without creating approved fragment artifacts', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_prepare_provenance_source('Unsafe mixed source', 'Only current ticket price is $25.');
	$invalidResult = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(array('stable_fragments' => array())),
		'ai',
		'Unsafe mixed source'
	);
	$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($invalidResult));

	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	oras_ai_assert_same('review', $first['kind'], 'Invalid Mixed result must normalize to Review.');
	oras_ai_assert_same($first['kb_id'], $second['kb_id'], 'Invalid Mixed review state should be reused.');
	oras_ai_assert_same('review', get_post_meta($first['kb_id'], '_oras_ai_status', true), 'Invalid Mixed must not create approved knowledge.');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_kb_entry_ids', true), 'Invalid Mixed must not create stable fragment artifacts.');
});

oras_ai_test('mixed extraction retires only surplus scanner-managed fragment artifacts', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_prepare_provenance_source();
	$twoFragments = new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result()));
	$first = oras_ai_invoke_private($twoFragments, 'process_source', array($sourceId));
	$oneFragmentResult = oras_ai_test_two_fragment_mixed_result(
		array(
			'stable_fragments' => array(
				array(
					'stable_title' => 'About AstroBlast',
					'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.',
				),
			),
		)
	);

	$second = oras_ai_invoke_private(
		new ORAS_AI_Sources(oras_ai_test_classifier_result($oneFragmentResult)),
		'process_source',
		array($sourceId)
	);

	oras_ai_assert_same(array($first['kb_ids'][0]), $second['kb_ids'], 'Current fragment set should retain only the reusable first artifact.');
	oras_ai_assert_same('review', get_post_meta($first['kb_ids'][0], '_oras_ai_status', true), 'Retained fragment status changed.');
	oras_ai_assert_same('retired', get_post_meta($first['kb_ids'][1], '_oras_ai_status', true), 'Surplus managed fragment should retire.');
});

oras_ai_test('mixed fragment addition and reorder reuse the existing identity set', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_prepare_provenance_source();
	$oneFragment = oras_ai_test_two_fragment_mixed_result(
		array(
			'stable_fragments' => array(
				array('stable_title' => 'About AstroBlast', 'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.'),
			),
		)
	);
	$first = oras_ai_invoke_private(new ORAS_AI_Sources(oras_ai_test_classifier_result($oneFragment)), 'process_source', array($sourceId));
	$second = oras_ai_invoke_private(new ORAS_AI_Sources(oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result())), 'process_source', array($sourceId));
	$reordered = oras_ai_test_two_fragment_mixed_result(
		array(
			'stable_fragments' => array(
				array('stable_title' => 'AstroBlast Program', 'stable_content' => 'The program combines astronomy talks and observing activities.'),
				array('stable_title' => 'About AstroBlast', 'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.'),
			),
		)
	);
	$third = oras_ai_invoke_private(new ORAS_AI_Sources(oras_ai_test_classifier_result($reordered)), 'process_source', array($sourceId));

	oras_ai_assert_same($first['kb_ids'][0], $second['kb_ids'][0], 'Adding a fragment should retain existing fragment identity.');
	oras_ai_assert_same(2, count($second['kb_ids']), 'Adding one fragment should create exactly one additional artifact.');
	oras_ai_assert_same($second['kb_ids'], $third['kb_ids'], 'Reordering fragments should reuse the existing ordinal identity set.');
	oras_ai_assert_same('The program combines astronomy talks and observing activities.', get_post_meta($third['kb_ids'][0], '_oras_ai_official_answer', true), 'Reordered ordinal zero content did not update.');
});

oras_ai_test('mixed rebuild reuses artifacts and repairs a manual primary link without changing manual knowledge', function (): void {
	oras_ai_test_reset();
	$postId = oras_ai_test_add_post(array('post_type' => 'page', 'post_title' => 'Mixed rebuild page', 'post_content' => 'Stable description and tickets are $25.'));
	$classifier = oras_ai_test_classifier_result(oras_ai_test_two_fragment_mixed_result());
	$sources = new ORAS_AI_Sources($classifier);
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(false));
	$sourceId = oras_ai_test_find_source_for_post($postId);
	$manualId = oras_ai_test_prepare_manual_artifact($sourceId);
	$manualBefore = oras_ai_test_manual_snapshot($manualId);
	$first = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
	oras_ai_invoke_private($sources, 'discover_wordpress_sources', array(true));
	$second = oras_ai_invoke_private($sources, 'process_source', array($sourceId));

	oras_ai_assert_same($first['kb_ids'], $second['kb_ids'], 'Mixed rebuild changed the artifact identity set.');
	oras_ai_assert_same($first['kb_ids'][0], get_post_meta($sourceId, '_oras_ai_kb_entry_id', true), 'Scanner should repair an incorrect manual primary link to its managed artifact.');
	oras_ai_assert_same($manualBefore, oras_ai_test_manual_snapshot($manualId), 'Mixed rebuild changed manual knowledge.');
});

oras_ai_test('privacy policy and historical artifacts retain qualified provenance without retrieval behavior', function (): void {
	$cases = array(
		array(
			'ORAS Privacy Policy',
			'ORAS explains its public data-handling policy.',
			array('category' => 'Policies & Rules', 'knowledge_title' => 'ORAS Privacy Policy'),
			'Policies & Rules',
			'0',
			'approved',
		),
		array(
			'AstroBlast 2018 Archive',
			'Historical speakers and activities from AstroBlast 2018.',
			array(
				'category' => 'Events',
				'knowledge_title' => 'AstroBlast 2018 Archive',
				'historical_event' => true,
				'reason' => 'This is durable Historical Event Knowledge.',
			),
			'Events',
			'1',
			'review',
		),
	);

	foreach ($cases as $case) {
		oras_ai_test_reset();
		$sourceId = oras_ai_test_prepare_provenance_source($case[0], $case[1]);
		$result = ORAS_AI_Source_Classification_Result::from_array(
			oras_ai_test_classification($case[2]),
			'ai',
			$case[0]
		);
		$processed = oras_ai_invoke_private(
			new ORAS_AI_Sources(oras_ai_test_classifier_result($result)),
			'process_source',
			array($sourceId)
		);
		$artifactId = $processed['kb_id'];

		oras_ai_assert_same($case[3], get_post_meta($sourceId, '_oras_ai_source_category', true), 'Qualified category changed.');
		oras_ai_assert_same($case[4], get_post_meta($artifactId, '_oras_ai_historical_event', true), 'Historical provenance changed.');
		oras_ai_assert_same($case[5], get_post_meta($artifactId, '_oras_ai_status', true), 'Approval protection changed.');
		oras_ai_assert_same('source-hash-v1', get_post_meta($artifactId, '_oras_ai_source_hash', true), 'Qualified artifact source hash missing.');
		oras_ai_assert_same(1, get_post_meta($artifactId, '_oras_ai_extraction_version', true), 'Qualified artifact extraction version missing.');
		oras_ai_assert_same('static_knowledge', get_post_meta($artifactId, '_oras_ai_source_classification', true), 'Qualified artifact classification missing.');
		oras_ai_assert_same('high', get_post_meta($artifactId, '_oras_ai_source_confidence', true), 'Qualified artifact confidence missing.');
		$categoryId = term_exists($case[3], ORAS_AI_Knowledge_Base::TAXONOMY);
		oras_ai_assert_same(array((int) $categoryId), wp_get_post_terms($artifactId, ORAS_AI_Knowledge_Base::TAXONOMY), 'Qualified artifact category missing.');
	}
});
