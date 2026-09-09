<?php
declare(strict_types=1);

function oras_ai_test_conflict_guarded_request(string $question): ORAS_AI_Guarded_Request {
	return new ORAS_AI_Guarded_Request(
		new ORAS_AI_Authorized_Request(71, $question, array('public', 'members'), false),
		ORAS_AI_Domain_Result::from_outcome(ORAS_AI_Domain_Result::ORAS)
	);
}

function oras_ai_test_conflict_artifact(bool $managed = true): array {
	$sourceId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => 'Copied ORAS source',
		)
	);
	$artifactId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'post_title' => 'Copied ORAS fact',
		)
	);
	update_post_meta($artifactId, '_oras_ai_status', 'approved');
	update_post_meta($artifactId, '_oras_ai_source_record_id', $sourceId);
	if ($managed) {
		update_post_meta($artifactId, '_oras_ai_managed_by_scan', '1');
	}

	return array($sourceId, $artifactId);
}

function oras_ai_test_conflict_evidence(int $sourceId, int $artifactId, string $factKey, string $text): ORAS_AI_Evidence {
	return ORAS_AI_Evidence::from_array(
		array(
			'artifact_id' => $artifactId,
			'source_record_id' => $sourceId,
			'source_title' => 'Copied ORAS source',
			'relevant_text' => $text,
			'visibility' => 'public',
			'lifecycle' => 'approved',
			'authority_class' => ORAS_AI_Source_Precedence::SYNCHRONIZED_ORAS_KNOWLEDGE,
			'fact_keys' => array($factKey),
		)
	);
}

function oras_ai_test_live_comparison_evidence(string $factKey, string $text, string $comparisonValue): ORAS_AI_Evidence {
	return ORAS_AI_Evidence::from_array(
		array(
			'source_title' => 'Authoritative live state',
			'relevant_text' => $text,
			'visibility' => 'public',
			'lifecycle' => 'approved',
			'authority_class' => ORAS_AI_Source_Precedence::LIVE_ORAS_STATE,
			'fact_key' => $factKey,
			'fact_keys' => array($factKey),
			'comparison_value' => $comparisonValue,
		)
	);
}

function oras_ai_test_assemble_with_conflict_observer(string $question, array $evidence): ORAS_AI_Grounded_Context {
	$assembler = new ORAS_AI_Grounded_Context_Assembler(
		new ORAS_AI_Source_Precedence(),
		new ORAS_AI_Live_Conflict_Observer()
	);

	return $assembler->assemble(
		oras_ai_test_conflict_guarded_request($question),
		new ORAS_AI_Evidence_Packet($evidence),
		ORAS_AI_Retrieval_Request::INTENT_CURRENT
	);
}

oras_ai_test('differing live event time signals only the exact scanner artifact and keeps live authoritative', function (): void {
	oras_ai_test_reset();
	list($sourceId, $artifactId) = oras_ai_test_conflict_artifact();
	list($siblingSourceId, $siblingId) = oras_ai_test_conflict_artifact();
	$factKey = 'event:astroblast:start';
	$static = oras_ai_test_conflict_evidence($sourceId, $artifactId, $factKey, 'AstroBlast starts September 12, 2025.');
	$sibling = oras_ai_test_conflict_evidence($siblingSourceId, $siblingId, 'event:astroblast:description', 'AstroBlast is an annual gathering.');
	$live = oras_ai_test_live_comparison_evidence($factKey, 'AstroBlast starts 2026-09-18T20:00:00-04:00.', '2026-09-18T20:00:00-04:00');

	$context = oras_ai_test_assemble_with_conflict_observer('When does AstroBlast start?', array($static, $sibling, $live));
	$text = implode(' ', array_map(static fn($item): string => (string) $item->field('relevant_text'), $context->evidence_packet()->items()));

	oras_ai_assert_same('review', get_post_meta($artifactId, '_oras_ai_status', true), 'Exact conflicting scanner artifact did not enter review.');
	oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Owning source did not enter the existing Needs Review queue.');
	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_problem_count', true), 'Conflict signal was not recorded once.');
	oras_ai_assert_same('approved', get_post_meta($siblingId, '_oras_ai_status', true), 'Unrelated sibling artifact was changed.');
	oras_ai_assert_same('', get_post_meta($siblingSourceId, '_oras_ai_scan_status', true), 'Unrelated sibling source was signaled.');
	oras_ai_assert_contains('2026-09-18T20:00:00-04:00', $text, 'Authoritative live event fact was not retained.');
	oras_ai_assert_not_contains('September 12, 2025', $text, 'Conflicting static event fact survived precedence.');
	ob_start();
	(new ORAS_AI_Sources())->render_review_page();
	$reviewHtml = (string) ob_get_clean();
	oras_ai_assert_contains('Copied ORAS source', $reviewHtml, 'Conflict did not appear in the central Needs Review workflow.');
	oras_ai_assert_contains('Authoritative live data conflicts with synchronized knowledge.', $reviewHtml, 'Needs Review omitted the bounded conflict reason.');

	oras_ai_test_assemble_with_conflict_observer('When does AstroBlast start?', array($static, $live));
	oras_ai_assert_same(1, get_post_meta($sourceId, '_oras_ai_problem_count', true), 'Repeated conflict request duplicated the review signal.');
});

oras_ai_test('equivalent event instants do not create a review signal', function (): void {
	oras_ai_test_reset();
	list($sourceId, $artifactId) = oras_ai_test_conflict_artifact();
	$factKey = 'event:astroblast:start';
	$static = oras_ai_test_conflict_evidence($sourceId, $artifactId, $factKey, 'AstroBlast starts 2026-09-19T00:00:00+00:00.');
	$live = oras_ai_test_live_comparison_evidence($factKey, 'AstroBlast starts 2026-09-18T20:00:00-04:00.', '2026-09-18T20:00:00-04:00');

	oras_ai_test_assemble_with_conflict_observer('When does AstroBlast start?', array($static, $live));

	oras_ai_assert_same('approved', get_post_meta($artifactId, '_oras_ai_status', true), 'Equivalent timestamps created a false conflict.');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Equivalent timestamp signaled the source for review.');

	oras_ai_test_reset();
	list($dateSourceId, $dateArtifactId) = oras_ai_test_conflict_artifact();
	$dateOnly = oras_ai_test_conflict_evidence($dateSourceId, $dateArtifactId, $factKey, 'AstroBlast starts September 18, 2026.');
	oras_ai_test_assemble_with_conflict_observer('When does AstroBlast start?', array($dateOnly, $live));
	oras_ai_assert_same('approved', get_post_meta($dateArtifactId, '_oras_ai_status', true), 'Matching local calendar date created a false precision conflict.');
});

oras_ai_test('Woo price comparison signals a differing value but treats equivalent decimals as equal', function (): void {
	$factKey = 'product:observer-pass-annual:price';

	oras_ai_test_reset();
	list($differentSourceId, $differentArtifactId) = oras_ai_test_conflict_artifact();
	$different = oras_ai_test_conflict_evidence($differentSourceId, $differentArtifactId, $factKey, 'Annual Observer Pass costs 30.00 USD.');
	$live = oras_ai_test_live_comparison_evidence($factKey, 'Annual Observer Pass current price is 45.00 USD.', '45.00 USD');
	oras_ai_test_assemble_with_conflict_observer('How much is an Annual Observer Pass?', array($different, $live));
	oras_ai_assert_same('review', get_post_meta($differentArtifactId, '_oras_ai_status', true), 'Differing static Woo price did not enter review.');

	foreach (array('45 USD', '45.0 USD', '45.00 USD') as $equivalentPrice) {
		oras_ai_test_reset();
		list($sourceId, $artifactId) = oras_ai_test_conflict_artifact();
		$equivalent = oras_ai_test_conflict_evidence($sourceId, $artifactId, $factKey, 'Annual Observer Pass costs ' . $equivalentPrice . '.');
		oras_ai_test_assemble_with_conflict_observer('How much is an Annual Observer Pass?', array($equivalent, $live));
		oras_ai_assert_same('approved', get_post_meta($artifactId, '_oras_ai_status', true), $equivalentPrice . ' created a false decimal conflict.');
	}
});

oras_ai_test('manual same-fact knowledge remains untouched by live conflict signaling', function (): void {
	oras_ai_test_reset();
	list($sourceId, $artifactId) = oras_ai_test_conflict_artifact(false);
	$factKey = 'member:self:membership-status';
	$manual = oras_ai_test_conflict_evidence($sourceId, $artifactId, $factKey, 'The copied page says membership is inactive.');
	$live = oras_ai_test_live_comparison_evidence($factKey, 'Current membership status: active.', 'active');

	oras_ai_test_assemble_with_conflict_observer('What is my membership status?', array($manual, $live));

	oras_ai_assert_same('approved', get_post_meta($artifactId, '_oras_ai_status', true), 'Manual artifact was moved into scanner review.');
	oras_ai_assert_same('', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Manual artifact caused a scanner source review signal.');
});
