<?php
declare(strict_types=1);

function oras_ai_test_retrieval_add_source(array $args = array()): int {
	$args = array_merge(
		array(
			'title'        => 'ORAS source',
			'url'          => 'https://oras.org/source/',
			'wp_post_id'   => 501,
			'wp_post_type' => 'page',
			'scan_status'  => 'complete',
		),
		$args
	);

	$source_id = oras_ai_test_add_post(
		array(
			'post_type'    => ORAS_AI_Sources::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $args['title'],
			'post_content' => 'Scanner source record.',
		)
	);

	update_post_meta($source_id, '_oras_ai_source_url', $args['url']);
	update_post_meta($source_id, '_oras_ai_wp_post_id', $args['wp_post_id']);
	update_post_meta($source_id, '_oras_ai_wp_post_type', $args['wp_post_type']);
	update_post_meta($source_id, '_oras_ai_scan_status', $args['scan_status']);
	update_post_meta($source_id, '_oras_ai_source_hash', 'source-hash-' . $source_id);

	return $source_id;
}

function oras_ai_test_retrieval_add_artifact(array $args = array()): int {
	$args = array_merge(
		array(
			'title'          => 'Observatory access',
			'answer'         => 'Members may use the observatory after completing orientation.',
			'post_status'    => 'publish',
			'lifecycle'      => 'approved',
			'visibility'     => 'public',
			'category'       => 'Observatory Access',
			'source_id'      => 0,
			'source_label'   => 'ORAS Knowledge Base',
			'source_url'     => 'https://oras.org/observatory/',
			'classification' => 'static_knowledge',
			'historical'     => '0',
		),
		$args
	);

	$artifact_id = oras_ai_test_add_post(
		array(
			'post_type'    => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status'  => $args['post_status'],
			'post_title'   => $args['title'],
			'post_content' => 'Administrative editing copy.',
		)
	);

	update_post_meta($artifact_id, '_oras_ai_official_answer', $args['answer']);
	update_post_meta($artifact_id, '_oras_ai_content_hash', hash('sha256', $args['answer']));
	update_post_meta($artifact_id, '_oras_ai_visibility', $args['visibility']);
	update_post_meta($artifact_id, '_oras_ai_status', $args['lifecycle']);
	update_post_meta($artifact_id, '_oras_ai_source', $args['source_label']);
	update_post_meta($artifact_id, '_oras_ai_source_url', $args['source_url']);
	update_post_meta($artifact_id, '_oras_ai_source_record_id', $args['source_id']);
	update_post_meta($artifact_id, '_oras_ai_source_wp_post_id', 501);
	update_post_meta($artifact_id, '_oras_ai_source_wp_post_type', 'page');
	update_post_meta($artifact_id, '_oras_ai_source_hash', 'source-hash-' . $args['source_id']);
	update_post_meta($artifact_id, '_oras_ai_source_modified_gmt', '2026-08-26 11:00:00');
	update_post_meta($artifact_id, '_oras_ai_synced_at', '2026-08-27 12:34:56');
	update_post_meta($artifact_id, '_oras_ai_source_classification', $args['classification']);
	update_post_meta($artifact_id, '_oras_ai_source_confidence', 'high');
	update_post_meta($artifact_id, '_oras_ai_historical_event', $args['historical']);

	$term = wp_insert_term($args['category'], ORAS_AI_Knowledge_Base::TAXONOMY);
	wp_set_post_terms($artifact_id, array((int) $term['term_id']), ORAS_AI_Knowledge_Base::TAXONOMY);

	return $artifact_id;
}

function oras_ai_test_retrieval_request(array $args = array()) {
	return ORAS_AI_Retrieval_Request::from_trusted_context(
		array_merge(
			array(
				'query'                => 'observatory orientation',
				'allowed_visibilities' => array('public'),
				'intent'               => 'general',
			),
			$args
		)
	);
}

function oras_ai_test_retrieval_ids($packet): array {
	return array_map(
		static function ($evidence): int {
			return (int) $evidence->field('artifact_id');
		},
		$packet->items()
	);
}

oras_ai_test('M3 retrieval boundary is provider independent and returns an explicit empty packet', function (): void {
	oras_ai_test_reset();
	$retriever = new ORAS_AI_WordPress_Retriever();

	oras_ai_assert_true($retriever instanceof ORAS_AI_Retriever_Interface, 'WordPress retriever must implement the provider-independent boundary.');
	$packet = $retriever->retrieve(oras_ai_test_retrieval_request(array('query' => 'no matching knowledge')));
	oras_ai_assert_true($packet instanceof ORAS_AI_Evidence_Packet, 'Retriever must return an evidence packet.');
	oras_ai_assert_true($packet->is_empty(), 'No relevant knowledge must be represented by an explicit empty packet.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Local retrieval must not invoke a remote provider.');
});

oras_ai_test('AT-RET-001 and AT-RET-004 retrieve approved source-linked evidence with citation provenance', function (): void {
	oras_ai_test_reset();
	$source_id = oras_ai_test_retrieval_add_source(
		array(
			'title' => 'ORAS Observatory Guide',
			'url'   => 'https://oras.org/observatory-guide/',
		)
	);
	$artifact_id = oras_ai_test_retrieval_add_artifact(
		array(
			'title'        => 'Observatory orientation requirements',
			'answer'       => '<p>Members must complete observatory orientation before independent use.</p>',
			'source_id'    => $source_id,
			'source_label' => 'ORAS Observatory Guide',
			'source_url'   => 'https://oras.org/observatory-guide/',
		)
	);

	$packet = (new ORAS_AI_WordPress_Retriever())->retrieve(oras_ai_test_retrieval_request());
	oras_ai_assert_same(1, $packet->count(), 'The relevant Approved artifact should be retrieved once.');
	$evidence = $packet->items()[0]->to_array();
	oras_ai_assert_same($artifact_id, $evidence['artifact_id'], 'Artifact identity missing.');
	oras_ai_assert_same($source_id, $evidence['source_record_id'], 'Source-record identity missing.');
	oras_ai_assert_same(501, $evidence['source_wp_object_id'], 'WordPress source identity missing.');
	oras_ai_assert_same('page', $evidence['source_type'], 'WordPress source type missing.');
	oras_ai_assert_same('ORAS Observatory Guide', $evidence['source_title'], 'Human-readable source title missing.');
	oras_ai_assert_same('https://oras.org/observatory-guide/', $evidence['canonical_url'], 'Canonical citation URL missing.');
	oras_ai_assert_same('public', $evidence['visibility'], 'Visibility provenance missing.');
	oras_ai_assert_same('approved', $evidence['lifecycle'], 'Lifecycle provenance missing.');
	oras_ai_assert_same('static_knowledge', $evidence['source_classification'], 'Classification provenance missing.');
	oras_ai_assert_same('synchronized_oras_knowledge', $evidence['authority_class'], 'Authority classification mismatch.');
	oras_ai_assert_same('source-hash-' . $source_id, $evidence['source_hash'], 'Source hash missing.');
	oras_ai_assert_same('2026-08-26 11:00:00', $evidence['source_modified_gmt'], 'Source freshness missing.');
	oras_ai_assert_same('2026-08-27 12:34:56', $evidence['synced_at'], 'Synchronization provenance missing.');
	oras_ai_assert_same('untrusted_evidence', $evidence['content_role'], 'Retrieved text must be explicitly marked as untrusted evidence.');
	oras_ai_assert_same('Members must complete observatory orientation before independent use.', $evidence['relevant_text'], 'Evidence text should be plain text without executing markup.');
});

oras_ai_test('AT-RET-002 eligibility reuses active lifecycle policy and rejects unavailable linked sources', function (): void {
	oras_ai_test_reset();
	$active_source = oras_ai_test_retrieval_add_source(array('title' => 'Active source'));
	$eligible = oras_ai_test_retrieval_add_artifact(array('source_id' => $active_source));

	foreach (array('review', 'draft', 'retired') as $lifecycle) {
		oras_ai_test_retrieval_add_artifact(
			array(
				'title'     => ucfirst($lifecycle) . ' observatory orientation',
				'lifecycle' => $lifecycle,
			)
		);
	}
	oras_ai_test_retrieval_add_artifact(array('title' => 'Trashed observatory orientation', 'post_status' => 'trash'));

	foreach (array('missing', 'excluded', 'error') as $state) {
		$source_id = oras_ai_test_retrieval_add_source(array('title' => ucfirst($state) . ' source', 'scan_status' => $state));
		if ('excluded' === $state) {
			update_post_meta($source_id, ORAS_AI_Sources::META_EXCLUDED, '1');
		}
		oras_ai_test_retrieval_add_artifact(array('title' => ucfirst($state) . ' observatory orientation', 'source_id' => $source_id));
	}

	$deleted_source = oras_ai_test_retrieval_add_source(array('title' => 'Deleted source'));
	oras_ai_test_retrieval_add_artifact(array('title' => 'Deleted-source observatory orientation', 'source_id' => $deleted_source));
	unset($GLOBALS['oras_ai_test_posts'][$deleted_source]);

	$packet = (new ORAS_AI_WordPress_Retriever())->retrieve(oras_ai_test_retrieval_request());
	oras_ai_assert_same(array($eligible), oras_ai_test_retrieval_ids($packet), 'Only an Approved artifact with an available source may be retrieved.');

	$source = file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-wordpress-retriever.php');
	oras_ai_assert_contains('ORAS_AI_Knowledge_Base::is_active_artifact', (string) $source, 'Retriever must reuse the M2 active-artifact authority.');
});

oras_ai_test('retrieval enforces trusted visibility before ranking', function (): void {
	oras_ai_test_reset();
	$public_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Observatory orientation public', 'visibility' => 'public'));
	$member_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Observatory orientation members', 'visibility' => 'members'));
	$admin_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Observatory orientation admin', 'visibility' => 'admin'));

	$retriever = new ORAS_AI_WordPress_Retriever();
	oras_ai_assert_same(array($public_id), oras_ai_test_retrieval_ids($retriever->retrieve(oras_ai_test_retrieval_request())), 'Public retrieval leaked restricted evidence.');
	oras_ai_assert_same(
		array($public_id, $member_id),
		oras_ai_test_retrieval_ids($retriever->retrieve(oras_ai_test_retrieval_request(array('allowed_visibilities' => array('public', 'members'))))),
		'Member retrieval should include public and member evidence only.'
	);
	oras_ai_assert_same(
		array($public_id, $member_id, $admin_id),
		oras_ai_test_retrieval_ids($retriever->retrieve(oras_ai_test_retrieval_request(array('allowed_visibilities' => array('public', 'members', 'admin'))))),
		'Administrative retrieval should include every explicitly allowed visibility.'
	);
});

oras_ai_test('policy evidence is intent gated before relevance ranking', function (): void {
	oras_ai_test_reset();
	$policy_id = oras_ai_test_retrieval_add_artifact(
		array(
			'title'    => 'Observatory security policy',
			'answer'   => 'The observatory security policy requires the gate to remain locked.',
			'category' => 'Policies & Rules',
		)
	);
	$retriever = new ORAS_AI_WordPress_Retriever();

	oras_ai_assert_true($retriever->retrieve(oras_ai_test_retrieval_request(array('query' => 'observatory security policy')))->is_empty(), 'Policy evidence must not enter a general-intent packet.');
	$packet = $retriever->retrieve(oras_ai_test_retrieval_request(array('query' => 'observatory security policy', 'intent' => 'policy')));
	oras_ai_assert_same(array($policy_id), oras_ai_test_retrieval_ids($packet), 'Relevant policy evidence should be eligible for policy intent.');
	oras_ai_assert_same('approved_oras_policy', $packet->items()[0]->field('authority_class'), 'Policy evidence authority class mismatch.');
});

oras_ai_test('historical evidence is limited to historical intent and cannot answer current intent', function (): void {
	oras_ai_test_reset();
	$historical_id = oras_ai_test_retrieval_add_artifact(
		array(
			'title'      => 'AstroBlast 2021 schedule',
			'answer'     => 'AstroBlast 2021 opened on Friday evening.',
			'category'   => 'AstroBlast',
			'historical' => '1',
		)
	);
	$retriever = new ORAS_AI_WordPress_Retriever();
	$query = 'AstroBlast 2021 Friday';

	oras_ai_assert_true($retriever->retrieve(oras_ai_test_retrieval_request(array('query' => $query, 'intent' => 'current')))->is_empty(), 'Historical evidence must not answer a current/upcoming question.');
	oras_ai_assert_same(
		array($historical_id),
		oras_ai_test_retrieval_ids($retriever->retrieve(oras_ai_test_retrieval_request(array('query' => $query, 'intent' => 'historical')))),
		'Historical intent should retrieve relevant historical evidence.'
	);
});

oras_ai_test('ranking is deterministic and weights title above content above category and source', function (): void {
	oras_ai_test_reset();
	$title_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Rareword', 'answer' => 'General guidance.'));
	$content_id = oras_ai_test_retrieval_add_artifact(array('title' => 'General guidance', 'answer' => 'The rareword appears in the answer.'));
	$category_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Category guidance', 'answer' => 'General text.', 'category' => 'Rareword'));
	$source_id = oras_ai_test_retrieval_add_source(array('title' => 'Rareword source'));
	$source_match_id = oras_ai_test_retrieval_add_artifact(array('title' => 'Source guidance', 'answer' => 'General text.', 'source_id' => $source_id, 'source_label' => 'Rareword source'));
	oras_ai_test_retrieval_add_artifact(array('title' => 'Completely unrelated', 'answer' => 'Nothing applicable.'));

	$retriever = new ORAS_AI_WordPress_Retriever();
	$request = oras_ai_test_retrieval_request(array('query' => 'rareword'));
	$expected = array($title_id, $content_id, $category_id, $source_match_id);
	oras_ai_assert_same($expected, oras_ai_test_retrieval_ids($retriever->retrieve($request)), 'Documented field weighting or zero-relevance filtering changed.');
	oras_ai_assert_same($expected, oras_ai_test_retrieval_ids($retriever->retrieve($request)), 'Equal inputs must produce stable ordering.');
});

oras_ai_test('retrieval enforces explicit top-k per-item and total context bounds', function (): void {
	oras_ai_test_reset();
	for ($index = 0; $index < 8; $index++) {
		oras_ai_test_retrieval_add_artifact(
			array(
				'title'  => 'Bounded telescope evidence ' . $index,
				'answer' => 'telescope ' . str_repeat(chr(65 + $index), 2500),
			)
		);
	}

	$retriever = new ORAS_AI_WordPress_Retriever();
	$limited = $retriever->retrieve(oras_ai_test_retrieval_request(array('query' => 'telescope', 'top_k' => 2, 'text_budget' => 99999)));
	oras_ai_assert_same(2, $limited->count(), 'Caller top-K should be honored below the ceiling.');
	foreach ($limited->items() as $evidence) {
		oras_ai_assert_true(strlen((string) $evidence->field('relevant_text')) <= 2000, 'Per-item evidence exceeded the fixed bound.');
	}

	$maximum = $retriever->retrieve(oras_ai_test_retrieval_request(array('query' => 'telescope', 'top_k' => 999, 'text_budget' => 99999)));
	oras_ai_assert_same(5, $maximum->count(), 'Top-K must clamp to the fixed maximum.');
	oras_ai_assert_true($maximum->text_characters() <= 6000, 'Evidence packet exceeded the fixed total context budget.');
});

oras_ai_test('authority resolver prefers injected Live ORAS state and blocks historical evidence for current facts', function (): void {
	$static = ORAS_AI_Evidence::from_array(
		array(
			'artifact_id'    => 10,
			'authority_class' => 'synchronized_oras_knowledge',
			'fact_key'        => 'event.start_time',
			'historical_event' => false,
		)
	);
	$live = ORAS_AI_Evidence::from_array(
		array(
			'artifact_id'    => 0,
			'authority_class' => 'live_oras_state',
			'fact_key'        => 'event.start_time',
			'historical_event' => false,
		)
	);
	$historical = ORAS_AI_Evidence::from_array(
		array(
			'artifact_id'      => 11,
			'authority_class'  => 'synchronized_oras_knowledge',
			'fact_key'         => 'event.start_time',
			'historical_event' => true,
		)
	);

	$resolver = new ORAS_AI_Source_Precedence();
	oras_ai_assert_same($live, $resolver->select_for_fact(array($static, $live), 'event.start_time', 'current'), 'Live ORAS state must outrank static knowledge for the same fact.');
	oras_ai_assert_same($live, $resolver->select_for_fact(array($historical, $live), 'event.start_time', 'current'), 'Historical evidence must not outrank valid Live ORAS state for a current fact.');
	oras_ai_assert_same(null, $resolver->select_for_fact(array($historical), 'event.start_time', 'current'), 'Historical evidence must never win for current intent.');
});

oras_ai_test('Task 1 retrieval code has no model provider or external search dependency', function (): void {
	$root = dirname(__DIR__, 2);
	$files = array(
		'interface-oras-ai-retriever.php',
		'class-oras-ai-retrieval-request.php',
		'class-oras-ai-evidence.php',
		'class-oras-ai-evidence-packet.php',
		'class-oras-ai-source-precedence.php',
		'class-oras-ai-wordpress-retriever.php',
	);
	$source = '';
	foreach ($files as $file) {
		$source .= (string) file_get_contents($root . '/includes/' . $file);
	}

	foreach (array('ORAS_AI_OpenAI', 'wp_remote_', 'embedding', 'vector', 'file_search') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $source, 'Task 1 retrieval must remain local and provider independent.');
	}
});
