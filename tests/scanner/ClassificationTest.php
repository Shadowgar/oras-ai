<?php
declare(strict_types=1);

function oras_ai_test_deterministic_result(string $postType, string $title, string $url) {
	$sourceId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_title' => $title,
			'post_content' => 'Source content',
		)
	);
	update_post_meta($sourceId, '_oras_ai_wp_post_type', $postType);
	update_post_meta($sourceId, '_oras_ai_source_url', $url);
	return oras_ai_invoke_private(new ORAS_AI_Sources(), 'deterministic_classification', array(get_post($sourceId)));
}

oras_ai_test('tribe_events remains deterministic public live data', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('tribe_events', 'AstroBlast 2026', 'https://oras.org/events/astroblast-2026/');
	oras_ai_assert_true($result instanceof ORAS_AI_Source_Classification_Result, 'Deterministic event must use the application result.');
	oras_ai_assert_same('live_data', $result->source_kind(), 'Event source kind changed.');
	oras_ai_assert_same('AstroBlast', $result->category(), 'Event category heuristic changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Event visibility changed.');
	oras_ai_assert_same('high', $result->confidence(), 'Event confidence changed.');
	oras_ai_assert_same('rule', $result->classified_by(), 'Event classification method changed.');
});

oras_ai_test('WooCommerce product remains deterministic public live data', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('product', 'Observer Pass', 'https://oras.org/product/observer-pass/');
	oras_ai_assert_same('live_data', $result->source_kind(), 'Product source kind changed.');
	oras_ai_assert_same('Observer Passes', $result->category(), 'Product category heuristic changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Product visibility changed.');
});

oras_ai_test('WordPress template record types remain ignored admin utility content', function (): void {
	foreach (array('elementor_library', 'mailpoet_page', 'gm_menu_block') as $postType) {
		oras_ai_test_reset();
		$result = oras_ai_test_deterministic_result($postType, 'Template', 'https://oras.org/template/');
		oras_ai_assert_same('ignore', $result->source_kind(), "{$postType} source kind changed.");
		oras_ai_assert_same('Website / Technical Help', $result->category(), "{$postType} category changed.");
		oras_ai_assert_same('admin', $result->visibility(), "{$postType} visibility changed.");
		oras_ai_assert_same('rule', $result->classified_by(), "{$postType} classification method changed.");
	}
});

oras_ai_test('known utility page paths remain ignored with admin visibility', function (): void {
	$paths = array(
		'https://oras.org/cart/',
		'https://oras.org/membership-account/membership-billing/',
		'https://oras.org/members-hub/equipment-exchange/my-listings/',
		'https://oras.org/order/checkout/review/',
		'https://oras.org/form/confirmation/123/',
		'https://oras.org/donation/thank-you/',
	);
	foreach ($paths as $url) {
		oras_ai_test_reset();
		$result = oras_ai_test_deterministic_result('page', 'Utility page', $url);
		oras_ai_assert_same('ignore', $result->source_kind(), "Utility detection changed for {$url}.");
		oras_ai_assert_same('admin', $result->visibility(), "Utility visibility changed for {$url}.");
	}
});

oras_ai_test('privacy and security pages have no deterministic v0.2.1 classification', function (): void {
	foreach (array('privacy-policy', 'security-policy') as $slug) {
		oras_ai_test_reset();
		$result = oras_ai_test_deterministic_result('page', ucwords(str_replace('-', ' ', $slug)), 'https://oras.org/' . $slug . '/');
		oras_ai_assert_same(null, $result, "{$slug} should continue to fall through to AI classification in v0.2.1.");
	}
});

oras_ai_test('ORAS speaker remains deterministic static public knowledge', function (): void {
	oras_ai_test_reset();
	$result = oras_ai_test_deterministic_result('oras_speaker', 'Dr. Nova', 'https://oras.org/speakers/dr-nova/');
	oras_ai_assert_same('static_knowledge', $result->source_kind(), 'Speaker source kind changed.');
	oras_ai_assert_same('Events', $result->category(), 'Speaker category changed.');
	oras_ai_assert_same('public', $result->visibility(), 'Speaker visibility changed.');
	oras_ai_assert_same('high', $result->confidence(), 'Speaker confidence changed.');
});

oras_ai_test('ordinary Elementor-built public page is not blanket ignored', function (): void {
	oras_ai_test_reset();
	$sourceId = oras_ai_test_add_post(
		array(
			'post_type' => ORAS_AI_Sources::POST_TYPE,
			'post_title' => 'About the Observatory',
			'post_content' => '<div class="elementor-section"><h2>About ORAS</h2><p>Rendered public page content.</p></div>',
		)
	);
	update_post_meta($sourceId, '_oras_ai_wp_post_type', 'page');
	update_post_meta($sourceId, '_oras_ai_source_url', 'https://oras.org/about-observatory/');
	$result = oras_ai_invoke_private(new ORAS_AI_Sources(), 'deterministic_classification', array(get_post($sourceId)));

	oras_ai_assert_same(null, $result, 'An ordinary public page must remain eligible for classification regardless of Elementor rendering.');
});

oras_ai_test('valid mixed classification is retained but routed to interim review without approved fragments', function (): void {
	oras_ai_test_reset();
	$classification = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_mixed_classification(),
		'ai',
		'About AstroBlast'
	);
	$classifier = new class($classification) implements ORAS_AI_Source_Classifier_Interface {
		private $classification;

		public function __construct($classification) {
			$this->classification = $classification;
		}

		public function classify_source($title, $url, $post_type, $content) {
			return $this->classification;
		}
	};
	$sourceId = oras_ai_test_add_source(
		'page',
		'About AstroBlast',
		'AstroBlast is an annual gathering. Tickets are $25 for the August 21, 2026 event.'
	);

	$result = oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

	oras_ai_assert_same('mixed', $result['kind'], 'Scanner must retain the Mixed classification.');
	oras_ai_assert_same('mixed', get_post_meta($sourceId, '_oras_ai_source_kind', true), 'Source registry must retain Mixed.');
	oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Mixed must use the interim review path before Task 2.');
	$kbId = (int) get_post_meta($sourceId, '_oras_ai_kb_entry_id', true);
	oras_ai_assert_true($kbId > 0, 'Interim Mixed handling should create a review record.');
	oras_ai_assert_same('review', get_post_meta($kbId, '_oras_ai_status', true), 'Interim Mixed record must not be approved.');
	oras_ai_assert_same(
		'AstroBlast is an annual gathering. Tickets are $25 for the August 21, 2026 event.',
		get_post_meta($kbId, '_oras_ai_official_answer', true),
		'Task 1 must not persist extracted stable fragments as final knowledge.'
	);
});

oras_ai_test('historical ORAS event knowledge is distinct from current deterministic event data', function (): void {
	oras_ai_test_reset();
	$historical = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(
			array(
				'category' => 'Events',
				'knowledge_title' => 'AstroBlast 2018 Archive',
				'reason' => 'This is durable historical ORAS event knowledge, not a current event record.',
				'historical_event' => true,
			)
		),
		'ai',
		'AstroBlast 2018 Archive'
	);
	$current = oras_ai_test_deterministic_result('tribe_events', 'AstroBlast 2026', 'https://oras.org/events/astroblast-2026/');

	oras_ai_assert_same('static_knowledge', $historical->source_kind(), 'Historical ORAS event knowledge should be durable knowledge.');
	oras_ai_assert_same('Events', $historical->category(), 'Historical ORAS event category changed.');
	oras_ai_assert_true($historical->is_historical_event(), 'Historical ORAS event knowledge needs a distinct contract marker.');
	oras_ai_assert_same('live_data', $current->source_kind(), 'Current event record must remain Live Data.');
});

oras_ai_test('historical ORAS event knowledge uses the safe scanner review disposition', function (): void {
	oras_ai_test_reset();
	$historical = ORAS_AI_Source_Classification_Result::from_array(
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
	$classifier = new class($historical) implements ORAS_AI_Source_Classifier_Interface {
		private $classification;

		public function __construct($classification) {
			$this->classification = $classification;
		}

		public function classify_source($title, $url, $post_type, $content) {
			return $this->classification;
		}
	};
	$sourceId = oras_ai_test_add_source('page', 'AstroBlast 2018 Archive', 'Past speakers and activities.');

	$result = oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

	oras_ai_assert_same('static_knowledge', $result['kind'], 'Historical event contract disposition changed.');
	oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), 'Historical event source must enter scanner review.');
	$kbId = (int) get_post_meta($sourceId, '_oras_ai_kb_entry_id', true);
	oras_ai_assert_same('review', get_post_meta($kbId, '_oras_ai_status', true), 'Historical event knowledge must not auto-approve.');
});

oras_ai_test('AI live and ignored outcomes requiring review do not retire managed knowledge', function (): void {
	$cases = array(
		array('live_data', 'low'),
		array('ignore', 'medium'),
	);

	foreach ($cases as $case) {
		oras_ai_test_reset();
		$classification = ORAS_AI_Source_Classification_Result::from_array(
			oras_ai_test_classification(
				array(
					'source_kind' => $case[0],
					'confidence' => $case[1],
					'knowledge_title' => 'Uncertain source',
					'reason' => 'The provider is not sufficiently confident.',
				)
			),
			'ai',
			'Uncertain source'
		);
		$classifier = new class($classification) implements ORAS_AI_Source_Classifier_Interface {
			private $classification;

			public function __construct($classification) {
				$this->classification = $classification;
			}

			public function classify_source($title, $url, $post_type, $content) {
				return $this->classification;
			}
		};
		$sourceId = oras_ai_test_add_source('page', 'Uncertain source', 'Uncertain source content');
		$kbId = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
			array(
				'source_id' => $sourceId,
				'title' => 'Previously approved source',
				'content' => 'Previously approved content',
				'category' => 'General FAQ',
				'visibility' => 'public',
				'status' => 'approved',
			)
		);
		update_post_meta($sourceId, '_oras_ai_kb_entry_id', $kbId);

		oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

		oras_ai_assert_same('review', get_post_meta($sourceId, '_oras_ai_scan_status', true), "{$case[1]}-confidence {$case[0]} must route to review.");
		oras_ai_assert_same('review', get_post_meta($kbId, '_oras_ai_status', true), "{$case[1]}-confidence {$case[0]} must not retire managed knowledge.");
	}
});

oras_ai_test('legitimate ORAS privacy policy remains eligible Policies and Rules knowledge', function (): void {
	oras_ai_test_reset();
	$classification = ORAS_AI_Source_Classification_Result::from_array(
		oras_ai_test_classification(
			array(
				'category' => 'Policies & Rules',
				'knowledge_title' => 'ORAS Privacy Policy',
				'reason' => 'This public ORAS policy explains ORAS data-handling practices.',
			)
		),
		'ai',
		'ORAS Privacy Policy'
	);
	$classifier = new class($classification) implements ORAS_AI_Source_Classifier_Interface {
		private $classification;

		public function __construct($classification) {
			$this->classification = $classification;
		}

		public function classify_source($title, $url, $post_type, $content) {
			return $this->classification;
		}
	};
	$sourceId = oras_ai_test_add_source(
		'page',
		'ORAS Privacy Policy',
		'ORAS explains how member contact data is handled.'
	);

	$result = oras_ai_invoke_private(new ORAS_AI_Sources($classifier), 'process_source', array($sourceId));

	oras_ai_assert_same('static_knowledge', $result['kind'], 'Legitimate ORAS privacy policy must remain eligible knowledge.');
	oras_ai_assert_same('Policies & Rules', $result['category'], 'ORAS privacy policy category changed.');
	$kbId = (int) get_post_meta($sourceId, '_oras_ai_kb_entry_id', true);
	oras_ai_assert_true($kbId > 0, 'Eligible ORAS privacy policy should create synchronized knowledge.');
	oras_ai_assert_same('approved', get_post_meta($kbId, '_oras_ai_status', true), 'High-confidence ORAS policy page should retain normal page approval policy.');
});

oras_ai_test('current deterministic category heuristics retain priority and fallback', function (): void {
	$cases = array(
		array('AstroBlast Membership', 'https://oras.org/member/', 'AstroBlast'),
		array('Public Night', 'https://oras.org/events/', 'Public Nights'),
		array('Observer Pass', 'https://oras.org/passes/', 'Observer Passes'),
		array('Member handbook', 'https://oras.org/handbook/', 'Membership'),
		array('Telescope Loan', 'https://oras.org/equipment/', 'Telescopes & Equipment'),
		array('Donate', 'https://oras.org/give/', 'Payments / Treasurer'),
		array('Access', 'https://oras.org/observatory/', 'Observatory Access'),
		array('Unmatched', 'https://oras.org/other/', 'Events'),
	);
	$sources = new ORAS_AI_Sources();
	foreach ($cases as $case) {
		$result = oras_ai_invoke_private($sources, 'deterministic_category', array($case[0], $case[1], 'Events'));
		oras_ai_assert_same($case[2], $result, 'Category heuristic changed for ' . $case[0] . '.');
	}
});
