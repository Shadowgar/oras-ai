<?php
declare(strict_types=1);

function oras_ai_integrity_corpus(): array {
	require_once __DIR__ . '/../../tools/evaluation/harness.php';
	return ORAS_AI_Release_Evaluation::corpus();
}

oras_ai_test('M9 integrity defaults to v2 and preserves every non scanner case and v1 bytes', function (): void {
	$v2 = oras_ai_integrity_corpus();
	oras_ai_assert_same('oras-release-core-v2', $v2['version'], 'Contaminated v1 still selected.');
	oras_ai_assert_same('synthetic_only', $v2['data_class'], 'Synthetic-only guard lost.');
	oras_ai_assert_same(72, count($v2['cases']), 'Retained review case was not promoted.');
	$path = __DIR__ . '/../../docs/quality/release-evaluation/core-v1.json';
	oras_ai_assert_same('9b7c7a4bee28a98cbcfd6f1abadd269c1612c16edd4505741c1ed4ac559b7fd3', hash_file('sha256', $path), 'Historical v1 changed.');
	$v1 = json_decode((string) file_get_contents($path), true);
	$non_scanner = static function (array $corpus): array { return array_values(array_filter($corpus['cases'], static function ($row) { return 'scanner' !== $row['workflow']; })); };
	oras_ai_assert_same($non_scanner($v1), $non_scanner($v2), 'Non-scanner comparison cases changed.');
});

oras_ai_test('M9 integrity scanner sources contain four clean realistic fields and all five meanings', function (): void {
	$corpus = oras_ai_integrity_corpus();
	oras_ai_assert_true(method_exists(ORAS_AI_Release_Evaluation::class, 'scanner_source'), 'Explicit scanner input boundary missing.');
	$kinds = array(); $titles = array(); $urls = array();
	foreach ($corpus['cases'] as $case) {
		if ('scanner' !== $case['workflow']) { continue; }
		$source = ORAS_AI_Release_Evaluation::scanner_source($case);
		$keys = array_keys($source); sort($keys);
		oras_ai_assert_same(array('content', 'post_type', 'source_title', 'source_url'), $keys, 'Source boundary contains evaluation metadata.');
		oras_ai_assert_false((bool) preg_match('/\b(?:synthetic|fixture|retained|evaluation|test|mock)\b/i', json_encode($source)), 'Test framing reached classifier input.');
		oras_ai_assert_false((bool) preg_match('/this is (?:static knowledge|live data)|ignore this page/i', $source['content']), 'Expected label supplied as a source instruction.');
		$kinds[] = $case['profile']; $titles[] = $source['source_title']; $urls[] = $source['source_url'];
	}
	sort($kinds);
	oras_ai_assert_same(array('ignore', 'live_data', 'mixed', 'review', 'static_knowledge'), $kinds, 'Frozen five meanings not represented.');
	oras_ai_assert_same(5, count(array_unique($titles)), 'Generic scanner title reused.');
	oras_ai_assert_same(5, count(array_unique($urls)), 'Generic scanner URL reused.');
});

oras_ai_test('M9 integrity actual classifier receives only explicit source fields without expected labels or notes', function (): void {
	$cases = array_column(oras_ai_integrity_corpus()['cases'], null, 'id');
	oras_ai_assert_true(isset($cases['X-stable']['source']), 'Structured model-visible source missing.');
	$case = $cases['X-stable'];
	$case['prompt'] = 'INTERNAL_PROMPT_SENTINEL';
	$case['evaluation_notes'] = 'INTERNAL_NOTES_SENTINEL';
	oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(oras_ai_test_classification()), 'usage' => array('input_tokens' => 100, 'output_tokens' => 100)));
	ORAS_AI_Release_Evaluation::execute($case);
	$payload = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	$source = $case['source'];
	$expected = "SOURCE TITLE:\n{$source['source_title']}\n\nSOURCE URL:\n{$source['source_url']}\n\nWORDPRESS TYPE:\n{$source['post_type']}\n\nCONTENT:\n{$source['content']}";
	oras_ai_assert_same($expected, $payload['input'][1]['content'], 'Case metadata leaked or source fields were substituted.');
	oras_ai_assert_false(str_contains(json_encode($payload['input']), 'INTERNAL_'), 'Internal prompt/notes sent to model.');
	oras_ai_assert_same(12000, $payload['max_output_tokens'], 'Production scanner cap changed.');
	$actual = $payload;
	oras_ai_test_site_cost_setup();
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(200, array('output_text' => wp_json_encode(oras_ai_test_classification()), 'usage' => array('input_tokens' => 100, 'output_tokens' => 100)));
	ORAS_AI_OpenAI::classify_source($source['source_title'], $source['source_url'], $source['post_type'], $source['content']);
	$direct = json_decode($GLOBALS['oras_ai_test_remote_calls'][0]['args']['body'], true);
	oras_ai_assert_same($direct, $actual, 'Harness changed production prompt/schema or transport configuration.');
});

foreach (array('source_title', 'source_url', 'post_type', 'content') as $field) {
	oras_ai_test('M9 integrity rejects evaluation contamination in ' . $field, function () use ($field): void {
		$corpus = oras_ai_integrity_corpus();
		$index = array_search('X-stable', array_column($corpus['cases'], 'id'), true);
		foreach (array('synthetic', 'fixture', 'retained', 'evaluation', 'test', 'mock') as $signal) {
			$bad = $corpus;
			$bad['cases'][$index]['source'][$field] = 'source_url' === $field ? 'https://oras.org/' . $signal . '/orientation/' : 'ORAS ' . $signal . ' orientation';
			$rejected = false;
			try { ORAS_AI_Release_Evaluation::validate($bad); } catch (InvalidArgumentException $error) { $rejected = true; }
			oras_ai_assert_true($rejected, 'Model-visible contamination admitted: ' . $field . '/' . $signal);
		}
	});
}

oras_ai_test('M9 integrity rejects incomplete or metadata carrying source envelopes and personal data', function (): void {
	$corpus = oras_ai_integrity_corpus();
	$index = array_search('X-stable', array_column($corpus['cases'], 'id'), true);
	foreach (array('missing', 'expected', 'member', 'email', 'external', 'query') as $mutation) {
		$bad = $corpus;
		if ('missing' === $mutation) { unset($bad['cases'][$index]['source']['source_title']); }
		if ('expected' === $mutation) { $bad['cases'][$index]['source']['expected_label'] = 'static_knowledge'; }
		if ('member' === $mutation) { $bad['cases'][$index]['source']['member_id'] = 819; }
		if ('email' === $mutation) { $bad['cases'][$index]['source']['content'] = 'Contact person@example.org'; }
		if ('external' === $mutation) { $bad['cases'][$index]['source']['source_url'] = 'https://outside.example/orientation/'; }
		if ('query' === $mutation) { $bad['cases'][$index]['source']['source_url'] = 'https://oras.org/orientation/?member_id=819'; }
		$rejected = false;
		try { ORAS_AI_Release_Evaluation::validate($bad); } catch (InvalidArgumentException $error) { $rejected = true; }
		oras_ai_assert_true($rejected, 'Unsafe scanner source envelope accepted: ' . $mutation);
	}
});

oras_ai_test('M9 integrity corpus and fixture versions cannot be confused', function (): void {
	$v2 = oras_ai_integrity_corpus();
	oras_ai_assert_same('oras-release-core-v2', $v2['version'], 'New corpus identity missing.');
	$v1 = json_decode((string) file_get_contents(__DIR__ . '/../../docs/quality/release-evaluation/core-v1.json'), true);
	foreach (array(array_replace($v2, array('version' => 'oras-release-core-v1')), array_replace($v1, array('version' => 'oras-release-core-v2')), array_replace($v2, array('version' => 'oras-release-core-v3')), array_replace($v2, array('data_class' => 'production'))) as $bad) {
		$rejected = false;
		try { ORAS_AI_Release_Evaluation::validate($bad); } catch (InvalidArgumentException $error) { $rejected = true; }
		oras_ai_assert_true($rejected, 'Corpus identity/provenance confusion accepted.');
	}
	oras_ai_assert_true(method_exists(ORAS_AI_Release_Evaluation::class, 'fixture_responses'), 'Version-bound fixture loading missing.');
	$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/release-evaluation-responses-v2.json'), true);
	$fixture['corpus_version'] = 'oras-release-core-v1';
	$rejected = false;
	try { ORAS_AI_Release_Evaluation::fixture_responses($fixture, $v2); } catch (InvalidArgumentException $error) { $rejected = true; }
	oras_ai_assert_true($rejected, 'Old fixture responses were mislabeled v2.');
});

oras_ai_test('M9 integrity v2 preserves static mixed current utility and policy ambiguity distinctions', function (): void {
	$cases = array_column(oras_ai_integrity_corpus()['cases'], null, 'id');
	oras_ai_assert_true(isset($cases['X-review']['source']), 'Review outcome missing from permanent corpus.');
	$static = $cases['X-stable']['source']['content']; $mixed = $cases['X-mixed']['source']['content']; $live = $cases['X-live']['source']['content']; $review = $cases['X-review']['source']['content'];
	oras_ai_assert_contains('orientation', $static, 'Orientation qualification lost.');
	oras_ai_assert_contains('blanket equipment authorization', $static, 'Access limitation weakened.');
	oras_ai_assert_contains('astronomy talks', $mixed, 'Mixed durable educational content lost.');
	oras_ai_assert_contains('$45', $mixed, 'Mixed changing price lost.');
	oras_ai_assert_contains('12', $mixed, 'Mixed changing capacity lost.');
	oras_ai_assert_contains('out of stock', $live, 'Live inventory distinction lost.');
	oras_ai_assert_not_contains('WooCommerce', $live, 'Implementation responsibility boilerplate still creates a mixed oracle ambiguity.');
	oras_ai_assert_contains('Board vote pending', $review, 'Genuine approval ambiguity removed.');
	oras_ai_assert_contains('Effective', $review, 'Genuine effective-date ambiguity removed.');
});

oras_ai_test('M9 integrity v2 offline fixture pipeline covers every case without live execution', function (): void {
	$corpus = oras_ai_integrity_corpus();
	oras_ai_assert_same('oras-release-core-v2', $corpus['version'], 'Offline test is still exercising v1.');
	$report = ORAS_AI_Release_Evaluation::fixture_run();
	oras_ai_assert_same(72, count($report['results']), 'Offline v2 case disappeared.');
	oras_ai_assert_same(0, $report['failed_cases'], 'Offline v2 contract failed.');
	oras_ai_assert_same(0, $report['live_calls'], 'Fixture output treated as live execution.');
	oras_ai_assert_same('fixture_pipeline_not_model_quality', $report['evidence_class'], 'Offline evidence overclaimed.');
	$rows = array_column($report['results'], null, 'id');
	foreach (array('X-stable' => 'static_knowledge', 'X-mixed' => 'mixed', 'X-live' => 'live_data', 'X-utility' => 'ignore', 'X-review' => 'review') as $id => $kind) {
		oras_ai_assert_same($kind, $rows[$id]['source_kind'], 'Scripted M2 outcome failed: ' . $id);
		oras_ai_assert_same('valid', $rows[$id]['validation_status'], 'Valid review/static/live fixture safely rejected instead of admitted.');
	}
});
