<?php
declare(strict_types=1);

foreach (array(
	'past date plus pending approval' => array('Effective March 1, 2025. Board vote pending.', 'The notice has a future effective date and Board approval is pending.'),
	'future date plus pending approval' => array('Effective March 1, 2035. Board vote pending.', 'Its effective date is in the future; approval is pending.'),
	'missing effective date' => array('Board vote pending. Effective date unspecified.', 'The policy is not yet effective.'),
	'conflicting dates' => array('Footer effective March 1, 2025; header effective July 1, 2035. Approval unclear.', 'The footer has a past effective date but the header has a future date.'),
	'approval unknown' => array('Effective March 1, 2025. Approving resolution unspecified.', 'The effective date is past, so this policy is already effective.'),
) as $label => $case) {
	oras_ai_test('M9 Task 3I scanner no-clock boundary ' . $label, function () use ($case): void {
		oras_ai_test_reset();
		oras_ai_test_configure_paid_prices();
		update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
		$payload = oras_ai_test_classification(array('source_kind' => 'review', 'reason' => $case[1], 'validation' => array('stable_dynamic_separation' => false, 'critical_qualifications_preserved' => false)));
		$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(array('classification' => $payload))));
		$result = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source('Equipment notice', 'https://oras.org/equipment-notice/', 'page', $case[0]);
		oras_ai_assert_same('review', $result->source_kind(), 'Disputed source left review.');
		oras_ai_assert_true($result->requires_review(), 'Human review was removed.');
		oras_ai_assert_same(array(), $result->stable_fragments(), 'Disputed policy admitted durable content.');
		oras_ai_assert_same(array(), $result->excluded_dynamic_claims(), 'Non-mixed arrays changed.');
		oras_ai_assert_same(array(), $result->dynamic_fact_types(), 'Non-mixed arrays changed.');
		oras_ai_assert_true(in_array('unsupported_temporal_reference', $result->validation_errors(), true), 'No-clock date claim passed validation.');
		oras_ai_assert_not_contains($case[1], $result->reason(), 'Unsupported relative-date reason survived.');
		oras_ai_assert_contains('reference date', $result->reason(), 'Neutral human-review reason lost the validation limitation.');
	});
}

oras_ai_test('M9 Task 3I approved unambiguous notice retains absolute date and safe disposition', function (): void {
	oras_ai_test_reset();
	oras_ai_test_configure_paid_prices();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$reason = 'The source identifies Board resolution 2025-12, approval and an explicit effective date of March 1, 2025 without conflicting qualifications.';
	$payload = oras_ai_test_classification(array('reason' => $reason));
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(array('classification' => $payload))));
	$result = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source('Approved orientation policy', 'https://oras.org/orientation-policy/', 'page', 'Approved by Board resolution 2025-12; effective March 1, 2025. Orientation is required for independent equipment use.');
	oras_ai_assert_same('static_knowledge', $result->source_kind(), 'Unambiguous absolute-date policy was rejected.');
	oras_ai_assert_same('valid', $result->validation_status(), 'Grounded absolute-date reason failed.');
	oras_ai_assert_same($reason, $result->reason(), 'Grounded reason was overwritten.');
	oras_ai_assert_false($result->requires_review(), 'Unambiguous policy disposition changed.');
});

foreach (array('future effective date', 'effective date is past', 'already effective', 'not yet in effect') as $claim) {
	oras_ai_test('M9 Task 3I scanner rejects relative-date approval inference ' . $claim, function () use ($claim): void {
		$result = ORAS_AI_Source_Classification_Result::from_array(oras_ai_test_classification(array('reason' => 'The policy is approved because it has a ' . $claim . '.')));
		oras_ai_assert_same('review', $result->source_kind(), 'Relative time was used to approve knowledge without a reference clock.');
		oras_ai_assert_same('low', $result->confidence(), 'Rejected temporal reasoning retained high confidence.');
		oras_ai_assert_same(array(), $result->stable_fragments(), 'Unqualified policy content survived.');
	});
}

oras_ai_test('M9 Task 3I grounded review ambiguity reason remains unchanged', function (): void {
	$payload = oras_ai_test_classification(array('source_kind' => 'review', 'reason' => 'Board approval and applicability are unclear; the posted dates conflict.', 'validation' => array('stable_dynamic_separation' => false, 'critical_qualifications_preserved' => false)));
	$result = ORAS_AI_Source_Classification_Result::from_array($payload);
	oras_ai_assert_same('valid', $result->validation_status(), 'Valid review explanation became invalid.');
	oras_ai_assert_same($payload['reason'], $result->reason(), 'Source-grounded ambiguity explanation was rewritten.');
	oras_ai_assert_true($result->requires_review(), 'Human review requirement removed.');
});
