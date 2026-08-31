<?php
declare(strict_types=1);

function oras_ai_test_mixed_classification(array $overrides = array()): array {
	return array_merge(
		array(
			'source_kind' => 'mixed',
			'category' => 'AstroBlast',
			'visibility' => 'public',
			'confidence' => 'high',
			'knowledge_title' => 'About AstroBlast',
			'reason' => 'The source combines durable program information with current registration details.',
			'historical_event' => false,
			'stable_fragments' => array(
				array(
					'stable_title' => 'About AstroBlast',
					'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.',
				),
			),
			'excluded_dynamic_claims' => array(
				'AstroBlast begins on August 21, 2026.',
				'Tickets are $25.',
			),
			'dynamic_fact_types' => array('event_date', 'ticket_price'),
			'validation' => array(
				'stable_dynamic_separation' => true,
				'critical_qualifications_preserved' => true,
			),
		),
		$overrides
	);
}

oras_ai_test('classification result exposes exactly five outcomes and extraction version one', function (): void {
	oras_ai_assert_same(
		array('static_knowledge', 'live_data', 'mixed', 'ignore', 'review'),
		ORAS_AI_Source_Classification_Result::supported_source_kinds(),
		'M2 source dispositions changed.'
	);
	oras_ai_assert_same(1, ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION, 'Extraction schema version changed.');
	oras_ai_assert_same(1, ORAS_AI_Source_Classification_Rules::VERSION, 'Deterministic rule version must remain one.');
});

oras_ai_test('classification result normalizes a provider-independent static result', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(),
		'ai',
		'Stable facts'
	);

	oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'Classification should normalize to an application result.');
	oras_ai_assert_same('static_knowledge', $result->source_kind(), 'Static source kind changed.');
	oras_ai_assert_same('General FAQ', $result->category(), 'Static category changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Static visibility changed.');
	oras_ai_assert_same('high', $result->confidence(), 'Static confidence changed.');
	oras_ai_assert_same('Stable facts', $result->knowledge_title(), 'Static title changed.');
	oras_ai_assert_same('ai', $result->classified_by(), 'Classifier marker changed.');
	oras_ai_assert_same('valid', $result->validation_status(), 'Valid result should pass validation.');
	oras_ai_assert_false($result->requires_review(), 'High-confidence valid static knowledge should not require review by contract.');
});

oras_ai_test('classification result accepts valid mixed stable and dynamic fields separately', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(),
		'ai',
		'AstroBlast'
	);

	oras_ai_assert_same('mixed', $result->source_kind(), 'Valid Mixed must not be mapped to Static or Review.');
	oras_ai_assert_same(
		array(
			array(
				'stable_title' => 'About AstroBlast',
				'stable_content' => 'AstroBlast is ORAS\'s annual astronomy gathering.',
			),
		),
		$result->stable_fragments(),
		'Mixed stable fragments changed.'
	);
	oras_ai_assert_same(
		array('AstroBlast begins on August 21, 2026.', 'Tickets are $25.'),
		$result->excluded_dynamic_claims(),
		'Mixed dynamic claims changed.'
	);
	oras_ai_assert_same(array('event_date', 'ticket_price'), $result->dynamic_fact_types(), 'Dynamic fact types changed.');
	oras_ai_assert_same('valid', $result->validation_status(), 'Valid Mixed result should pass validation.');
	oras_ai_assert_false($result->requires_review(), 'A valid high-confidence Mixed contract result should not itself require review.');
});

oras_ai_test('classification result routes mixed output with no stable content to review', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(array('stable_fragments' => array())),
		'ai',
		'AstroBlast'
	);

	oras_ai_assert_same('review', $result->source_kind(), 'Dynamic-only Mixed output must fall back to Review.');
	oras_ai_assert_true($result->requires_review(), 'Dynamic-only Mixed output must require review.');
	oras_ai_assert_true(in_array('mixed_missing_stable_content', $result->validation_errors(), true), 'Missing stable content must be reported.');
});

oras_ai_test('classification result routes malformed mixed fragments to review', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(
			array(
				'stable_fragments' => array(
					array('stable_title' => 'Missing content'),
				),
			)
		),
		'ai',
		'AstroBlast'
	);

	oras_ai_assert_same('review', $result->source_kind(), 'Malformed Mixed fragments must fall back to Review.');
	oras_ai_assert_true(in_array('invalid_stable_fragments', $result->validation_errors(), true), 'Malformed fragments must be reported.');
});

oras_ai_test('classification result rejects invalid category visibility and confidence', function (): void {
	$cases = array(
		array('category', 'Invented Category', 'invalid_category'),
		array('visibility', 'everyone', 'invalid_visibility'),
		array('confidence', 'certain', 'invalid_confidence'),
	);

	foreach ($cases as $case) {
		$result = ORAS_AI_Source_Classification_Result::from_array(
			oras_ai_test_mixed_classification(array($case[0] => $case[1])),
			'ai',
			'AstroBlast'
		);

		oras_ai_assert_same('review', $result->source_kind(), "Invalid {$case[0]} must fall back to Review.");
		oras_ai_assert_true(in_array($case[2], $result->validation_errors(), true), "Invalid {$case[0]} must be reported.");
	}
});

oras_ai_test('classification result rejects malformed or missing required fields', function (): void {
	$payload = oras_ai_test_mixed_classification();
	unset($payload['reason']);
	$result = ORAS_AI_Source_Classification_Result::from_array($payload, 'ai', 'AstroBlast');

	oras_ai_assert_same('review', $result->source_kind(), 'Missing required fields must fall back to Review.');
	oras_ai_assert_true(in_array('missing_reason', $result->validation_errors(), true), 'Missing reason must be reported.');
});

oras_ai_test('classification result rejects mixed output without dynamic material', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(
			array(
				'excluded_dynamic_claims' => array(),
				'dynamic_fact_types' => array(),
			)
		),
		'ai',
		'AstroBlast'
	);

	oras_ai_assert_same('review', $result->source_kind(), 'Mixed output without dynamic material must fall back to Review.');
	oras_ai_assert_true(in_array('mixed_missing_dynamic_data', $result->validation_errors(), true), 'Missing dynamic material must be reported.');
});

oras_ai_test('classification result routes failed mixed validation to review', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(
			array(
				'validation' => array(
					'stable_dynamic_separation' => false,
					'critical_qualifications_preserved' => true,
				),
			)
		),
		'ai',
		'AstroBlast'
	);

	oras_ai_assert_same('review', $result->source_kind(), 'Failed stable/dynamic validation must fall back to Review.');
	oras_ai_assert_true(in_array('stable_dynamic_separation_failed', $result->validation_errors(), true), 'Failed separation must be reported.');
});

oras_ai_test('classification result rejects current price and date claims hidden in stable mixed content', function (): void {
	$claims = array(
		'Tickets are $25 for August 21, 2026.',
		'Tickets typically cost $25.',
	);

	foreach ($claims as $claim) {
		$result = ORAS_AI_Source_Classification_Result::from_array(
			oras_ai_test_mixed_classification(
				array(
					'stable_fragments' => array(
						array(
							'stable_title' => 'Current AstroBlast details',
							'stable_content' => $claim,
						),
					),
				)
			),
			'ai',
			'AstroBlast'
		);

		oras_ai_assert_same('review', $result->source_kind(), 'Current price/date content must not pass as stable knowledge.');
		oras_ai_assert_true(in_array('dynamic_claim_in_stable_content', $result->validation_errors(), true), 'Dynamic leakage must be reported by application validation.');
	}
});

oras_ai_test('classification result marks historical event knowledge distinctly and requires review', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(
			array(
				'category' => 'Events',
				'knowledge_title' => 'AstroBlast 2018 Archive',
				'reason' => 'This is durable Historical Event Knowledge.',
				'historical_event' => true,
			)
		),
		'ai',
		'AstroBlast 2018 Archive'
	);

	oras_ai_assert_same('static_knowledge', $result->source_kind(), 'Historical event knowledge must remain within the five dispositions.');
	oras_ai_assert_true($result->is_historical_event(), 'Historical Event Knowledge needs a machine-readable contract designation.');
	oras_ai_assert_true($result->requires_review(), 'Historical event knowledge must not auto-approve before its lifecycle is qualified.');
});

oras_ai_test('classification result rejects unsupported source dispositions', function (): void {
	$result = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(array('source_kind' => 'historical')),
		'ai',
		'Past AstroBlast'
	);

	oras_ai_assert_same('review', $result->source_kind(), 'Historical must not become a sixth source disposition.');
	oras_ai_assert_true(in_array('invalid_source_kind', $result->validation_errors(), true), 'Unsupported dispositions must be reported.');
});
