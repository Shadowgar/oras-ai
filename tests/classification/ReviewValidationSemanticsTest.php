<?php
declare(strict_types=1);

foreach (array(array(false, false), array(true, true), array(false, true), array(true, false)) as $flags) {
	$label = json_encode($flags);
	oras_ai_test('M9 review semantics accepts Boolean flags ' . $label, function () use ($flags): void {
		$result = ORAS_AI_Source_Classification_Result::from_array(oras_ai_test_classification(array('source_kind' => 'review', 'validation' => array('stable_dynamic_separation' => $flags[0], 'critical_qualifications_preserved' => $flags[1]))), 'ai', 'Review source');
		oras_ai_assert_same('valid', $result->validation_status(), 'Boolean review flags describe unresolved safety and must remain valid.');
		oras_ai_assert_same('review', $result->source_kind(), 'Review disposition changed.');
		oras_ai_assert_true($result->requires_review(), 'Even high-confidence review requires a human.');
		oras_ai_assert_same(array(), $result->validation_errors(), 'Valid review acquired errors.');
	});
}

foreach (array('stable_dynamic_separation', 'critical_qualifications_preserved') as $field) {
	oras_ai_test('M9 review semantics rejects missing or non Boolean ' . $field, function () use ($field): void {
		foreach (array('missing', null, 0, 1, 'false', 'true', array()) as $value) {
			$payload = oras_ai_test_classification(array('source_kind' => 'review'));
			if ('missing' === $value) { unset($payload['validation'][$field]); } else { $payload['validation'][$field] = $value; }
			$result = ORAS_AI_Source_Classification_Result::from_array($payload, 'ai', 'Review source');
			oras_ai_assert_same('invalid', $result->validation_status(), 'Malformed flag accepted.');
			oras_ai_assert_same('review', $result->source_kind(), 'Malformed output escaped safe review fallback.');
			oras_ai_assert_true($result->requires_review(), 'Malformed output escaped human review.');
		}
	});
}

oras_ai_test('M9 review semantics rejects extracted fragments and invalid required dimensions', function (): void {
	$mutations = array('stable_fragments' => array(array('stable_title' => 'Policy', 'stable_content' => 'Access policy')), 'excluded_dynamic_claims' => array('Effective date unresolved'), 'dynamic_fact_types' => array('event_date'), 'category' => 'Invented', 'visibility' => 'everyone', 'confidence' => 'certain', 'knowledge_title' => '', 'reason' => '');
	foreach ($mutations as $field => $value) {
		$result = ORAS_AI_Source_Classification_Result::from_array(oras_ai_test_classification(array('source_kind' => 'review', $field => $value)), 'ai', 'Review source');
		oras_ai_assert_same('invalid', $result->validation_status(), 'Review weakened validation of ' . $field);
		oras_ai_assert_same(array(), $result->stable_fragments(), 'Invalid review created durable fragments.');
	}
});

foreach (array('static_knowledge', 'live_data', 'mixed', 'ignore') as $kind) {
	oras_ai_test('M9 review semantics retains false flag rejection for ' . $kind, function () use ($kind): void {
		foreach (array(array(false, false), array(false, true), array(true, false)) as $flags) {
			$payload = 'mixed' === $kind ? oras_ai_test_mixed_classification() : oras_ai_test_classification(array('source_kind' => $kind));
			$payload['validation'] = array('stable_dynamic_separation' => $flags[0], 'critical_qualifications_preserved' => $flags[1]);
			$result = ORAS_AI_Source_Classification_Result::from_array($payload, 'ai', 'Source');
			oras_ai_assert_same('invalid', $result->validation_status(), 'Unsafe non-review classification accepted.');
			oras_ai_assert_same('review', $result->source_kind(), 'Unsafe classification escaped review.');
			oras_ai_assert_true($result->requires_review(), 'Unsafe classification escaped human review.');
			oras_ai_assert_same(array(), $result->stable_fragments(), 'Rejected classification retained durable fragments.');
		}
	});
}

oras_ai_test('M9 review semantics scanner demotes approved knowledge and excludes review from retrieval', function (): void {
	foreach (array(array(false, false), array(true, true), array(false, true), array(true, false)) as $flags) {
		oras_ai_test_reset();
		$sourceId = oras_ai_test_add_source('page', 'Observatory access policy', 'Observatory access policy has unresolved approval qualifications.');
		$oldId = oras_ai_test_add_linked_kb($sourceId, true);
		$payload = oras_ai_test_classification(array('source_kind' => 'review', 'knowledge_title' => 'Observatory access policy', 'validation' => array('stable_dynamic_separation' => $flags[0], 'critical_qualifications_preserved' => $flags[1])));
		$result = ORAS_AI_Source_Classification_Result::from_array($payload, 'ai', 'Observatory access policy');
		oras_ai_assert_same('valid', $result->validation_status(), 'Lifecycle must exercise application-valid review.');
		$sources = new ORAS_AI_Sources(oras_ai_test_classifier_result($result));
		oras_ai_assert_false(oras_ai_invoke_private($sources, 'should_auto_approve', array('page', $result)), 'High confidence review auto-approved.');
		$processed = oras_ai_invoke_private($sources, 'process_source', array($sourceId));
		oras_ai_assert_same($oldId, $processed['kb_id'], 'Review created a second durable artifact.');
		oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Source escaped review queue.');
		oras_ai_assert_same('review', get_post_meta($oldId, '_oras_ai_status', true), 'Previously approved knowledge remained approved.');
		oras_ai_assert_same(array(), $result->stable_fragments(), 'Review extracted durable fragments.');
		oras_ai_assert_same(array(), $result->excluded_dynamic_claims(), 'Review retained extraction claims.');
		oras_ai_assert_same(array(), $result->dynamic_fact_types(), 'Review retained extraction fact types.');
		oras_ai_assert_false(ORAS_AI_Knowledge_Base::is_active_artifact($oldId), 'Review entered active knowledge state.');
		$packet = (new ORAS_AI_WordPress_Retriever())->retrieve(oras_ai_test_retrieval_request(array('query' => 'observatory access policy')));
		oras_ai_assert_true($packet->is_empty(), 'Review became authoritative retrieval evidence.');
	}
});
