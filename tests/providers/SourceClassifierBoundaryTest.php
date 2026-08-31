<?php
declare(strict_types=1);

oras_ai_test('source classifier contract and OpenAI adapter are available', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_true(
		interface_exists('ORAS_AI_Source_Classifier_Interface'),
		'Source classifier contract should load.'
	);
	oras_ai_assert_true(
		class_exists('ORAS_AI_OpenAI_Source_Classifier'),
		'OpenAI source classifier adapter should load.'
	);
	oras_ai_assert_true(
		class_exists('ORAS_AI_Source_Classification_Result'),
		'Provider-independent classification result should load.'
	);

	$classifier = new ORAS_AI_OpenAI_Source_Classifier();
	oras_ai_assert_true(
		$classifier instanceof ORAS_AI_Source_Classifier_Interface,
		'OpenAI source classifier should satisfy the internal contract.'
	);
});

oras_ai_test('source processing uses an injected classifier without HTTP', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_true(
		interface_exists('ORAS_AI_Source_Classifier_Interface'),
		'Source classifier contract is required for injection.'
	);

	$classification = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(),
		'ai',
		'Injected classifier source'
	);
	$classifier = new class($classification) implements ORAS_AI_Source_Classifier_Interface {
		public $calls = array();
		private $classification;

		public function __construct($classification) {
			$this->classification = $classification;
		}

		public function classify_source($title, $url, $post_type, $content) {
			$this->calls[] = array($title, $url, $post_type, $content);
			return $this->classification;
		}
	};

	$source_id = oras_ai_test_add_source('page', 'Injected classifier source', 'Injected source content');
	$result = oras_ai_invoke_private(
		new ORAS_AI_Sources($classifier),
		'process_source',
		array($source_id)
	);

	oras_ai_assert_same(1, count($classifier->calls), 'Injected classifier should receive one source.');
	oras_ai_assert_same(
		array(
			'Injected classifier source',
			'https://oras.org/source-' . $source_id . '/',
			'page',
			'Injected source content',
		),
		$classifier->calls[0],
		'Source classifier application inputs changed.'
	);
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Injected classifier path must not make HTTP calls.');
	oras_ai_assert_same('static_knowledge', $result['kind'], 'Injected classification should continue through source processing.');
	oras_ai_assert_same('ai', $result['classified_by'], 'Injected classifier should retain the current AI classification marker.');

	$sources_code = file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-sources.php');
	oras_ai_assert_true(
		false === strpos((string) $sources_code, 'ORAS_AI_OpenAI::classify_source'),
		'Source processing must not invoke the OpenAI implementation directly.'
	);
});

oras_ai_test('OpenAI adapter converts provider payloads to the application result', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode(oras_ai_test_mixed_classification()))
	);

	$result = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source(
		'AstroBlast',
		'https://oras.org/astroblast/',
		'page',
		'Mixed source content'
	);

	oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'OpenAI adapter must return the application result type.');
	oras_ai_assert_same('mixed', $result->source_kind(), 'OpenAI adapter lost the Mixed disposition.');
	oras_ai_assert_same('ai', $result->classified_by(), 'OpenAI adapter classifier marker changed.');
});

oras_ai_test('OpenAI adapter routes structurally invalid provider output to review', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_OpenAI::OPTION_API_KEY, 'stored-test-key');
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array(
			'output_text' => wp_json_encode(
				oras_ai_test_mixed_classification(array('category' => 'Invented Category'))
			),
		)
	);

	$result = (new ORAS_AI_OpenAI_Source_Classifier())->classify_source(
		'AstroBlast',
		'https://oras.org/astroblast/',
		'page',
		'Mixed source content'
	);

	oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'Invalid provider payload should still normalize safely.');
	oras_ai_assert_same('review', $result->source_kind(), 'Invalid provider payload must route to Review.');
	oras_ai_assert_true($result->requires_review(), 'Invalid provider payload must require review.');
});

oras_ai_test('default source classifier remains available when member AI is disabled', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::set_member_ai_enabled(false);
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, 'stored-test-key');
	$classification = oras_ai_test_classification(array('knowledge_title' => 'Admin classified source'));
	$GLOBALS['oras_ai_test_remote_responses'][] = oras_ai_test_http_response(
		200,
		array('output_text' => wp_json_encode($classification))
	);
	$source_id = oras_ai_test_add_source('page', 'Admin classified source', 'Administrative source content');

	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'process_source', array($source_id));

	oras_ai_assert_same(1, count($GLOBALS['oras_ai_test_remote_calls']), 'Default classifier should retain the OpenAI HTTP path.');
	oras_ai_assert_same('static_knowledge', $result['kind'], 'Administrative source classification changed.');
	oras_ai_assert_same('complete', get_post_meta($source_id, '_oras_ai_scan_status', true), 'Administrative scan should complete.');
});

oras_ai_test('deterministic source match bypasses the injected classifier', function (): void {
	oras_ai_test_reset();
	$classifier = new class implements ORAS_AI_Source_Classifier_Interface {
		public $calls = 0;

		public function classify_source($title, $url, $post_type, $content) {
			$this->calls++;
			return new WP_Error('unexpected_ai_call', 'Deterministic source reached AI.');
		}
	};
	$source_id = oras_ai_test_add_source('oras_speaker', 'Deterministic speaker', 'Speaker biography');

	$result = oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($source_id));

	oras_ai_assert_same(0, $classifier->calls, 'Deterministic source should not call the injected classifier.');
	oras_ai_assert_same(0, count($GLOBALS['oras_ai_test_remote_calls']), 'Deterministic source should not make HTTP calls.');
	oras_ai_assert_same('static_knowledge', $result['kind'], 'Deterministic source output changed.');
	oras_ai_assert_same('rule', $result['classified_by'], 'Deterministic source marker changed.');
});

oras_ai_test('events products and Elementor libraries bypass the provider', function (): void {
	$cases = array(
		array('tribe_events', 'AstroBlast 2026', 'live_data'),
		array('product', 'Observer Pass', 'live_data'),
		array('elementor_library', 'Header Template', 'ignore'),
	);

	foreach ($cases as $case) {
		oras_ai_test_reset();
		$classifier = new class implements ORAS_AI_Source_Classifier_Interface {
			public $calls = 0;

			public function classify_source($title, $url, $post_type, $content) {
				$this->calls++;
				return new WP_Error('unexpected_ai_call', 'Deterministic source reached AI.');
			}
		};
		$sourceId = oras_ai_test_add_source($case[0], $case[1], 'Deterministic source content');

		$result = oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

		oras_ai_assert_same(0, $classifier->calls, "{$case[0]} must not invoke the provider.");
		oras_ai_assert_same($case[2], $result['kind'], "{$case[0]} deterministic disposition changed.");
		oras_ai_assert_same('rule', $result['classified_by'], "{$case[0]} deterministic marker changed.");
	}
});
