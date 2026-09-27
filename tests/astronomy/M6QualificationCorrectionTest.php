<?php
declare(strict_types=1);

/** Real production adapters with deterministic transport and score-contract fixtures. */
function oras_ai_test_correction_fixture(string $now = '2026-09-09T16:00:00Z', ?string $planetDate = null): array {
	oras_ai_test_reset();
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable($now));
	$calls = (object) array('nws' => array(), 'planet' => array(), 'catalog' => 0);
	$http = static function (string $url) use ($clock, $calls): array {
		$calls->nws[] = $url;
		if (strpos($url, '/points/') !== false) {
			return oras_ai_test_nws_response(oras_ai_test_nws_point());
		}
		$body = oras_ai_test_nws_forecast();
		$body['properties']['updateTime'] = $clock->now()->modify('-30 minutes')->format(DATE_ATOM);
		foreach ($body['properties'] as &$property) {
			if (!is_array($property)) { continue; }
			$value = $property['values'][0]['value'];
			$property['values'] = array();
			for ($i = 0; $i < 12; $i++) {
				$at = (new DateTimeImmutable('2026-09-09T16:00:00Z'))->modify('+' . ($i * 2) . ' hours');
				$property['values'][] = array('validTime' => $at->format(DATE_ATOM) . '/PT2H', 'value' => $value);
			}
		}
		unset($property);
		return oras_ai_test_nws_response($body);
	};
	$planet = new ORAS_AI_Astronomy_API_Provider('fixture-id', 'fixture-secret', static function (string $url) use ($calls, $planetDate): array {
		$calls->planet[] = $url;
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$at = new DateTimeImmutable($query['from_date'] . ' ' . $query['time'], new DateTimeZone('America/New_York'));
		$path = parse_url($url, PHP_URL_PATH);
		oras_ai_assert_true(in_array($path, array('/api/v2/bodies/positions/jupiter', '/api/v2/bodies/positions'), true), 'Unexpected provider route in production-path fixture.');
		$bodies = '/api/v2/bodies/positions/jupiter' === $path ? array('jupiter') : ORAS_AI_Planet_Targets::allowed();
		$rows = array();
		foreach ($bodies as $body) {
			$row = oras_ai_test_astronomy_api_row($body, 31.25, 145.5);
			if ('missing' === $planetDate) { unset($row['cells'][0]['date']); }
			else { $row['cells'][0]['date'] = $planetDate ?? $at->format(DATE_ATOM); }
			$rows[] = $row;
		}
		return array('response' => array('code' => 200), 'body' => wp_json_encode(array('data' => array('table' => array('rows' => $rows)))));
	}, $clock);
	$local = new ORAS_AI_Local_Sun_Moon_Provider($clock);
	$catalog = oras_ai_test_astronomy_provider('catalog_test', static function () use ($calls): ORAS_AI_Current_Data_Result {
		$calls->catalog++;
		throw new RuntimeException('Broad discovery must not query the catalog.');
	});
	$astronomy = new ORAS_AI_Current_Astronomy_Service($local, $catalog, $planet, new ORAS_AI_OpenNGC_Target_Resolver(), $clock);
	$weather = new ORAS_AI_Current_Weather_Service(new ORAS_AI_NWS_Weather_Provider('ORAS fixture', $http, $clock), new ORAS_AI_Astronomical_Night_Resolver($local, $clock), $clock);
	ORAS_AI_Test_Member_Hub_Score_API::$callback = static function () { return oras_ai_test_score_api_result(); };
	$planner = new ORAS_AI_Observing_Planner($weather, $astronomy, new ORAS_AI_Member_Hub_Score_Adapter($clock, ORAS_AI_Test_Member_Hub_Score_API::class), $clock);
	list($orchestrator, $model) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success(), array(), null, $astronomy, $weather, $planner);
	return array($orchestrator, $model, $planner, $calls);
}

oras_ai_test('M6 correction after-dusk good-night production path consults NWS and scores the remaining night', function (): void {
	list($orchestrator, $model, $planner, $calls) = oras_ai_test_correction_fixture('2026-09-10T02:00:00Z');
	$request = oras_ai_test_authorized_request(1130, 'Is tonight a good night to observe at ORAS?');
	$result = $orchestrator->answer($request);
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Past dusk prevented remaining-night synthesis.');
	oras_ai_assert_true(count($calls->nws) >= 2, 'NWS was not consulted.');
	$text = wp_json_encode($model->calls[0]['context']->provider_input());
	oras_ai_assert_contains('begins 2026-09-10T02:00:00+00:00', $text, 'Night did not start at trusted now.');
	oras_ai_assert_contains('ORAS Observing Score', $text, 'Remaining-night score disappeared.');
});

foreach (array('What planets can I see tonight?', 'What can I see tonight?') as $question) {
	oras_ai_test('M6 correction bounded model context for ' . $question, static function () use ($question): void {
		list($orchestrator, $model, $planner, $calls) = oras_ai_test_correction_fixture();
		$request = oras_ai_test_authorized_request(1131, $question);
		$result = $orchestrator->answer($request);
		oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Qualified seven-planet context did not fit.');
		$context = $model->calls[0]['context'];
		$input = wp_json_encode($context->provider_input());
		oras_ai_assert_same(16000, ORAS_AI_Grounded_Context_Assembler::MAX_PROVIDER_INPUT_CHARACTERS, 'Context limit was increased.');
		oras_ai_assert_true(strlen($input) <= 16000, 'Serialized context exceeds the existing bound.');
		oras_ai_assert_same(0, $calls->catalog, 'Broad discovery scanned OpenNGC.');
		oras_ai_assert_same(5, count($calls->planet), 'All bodies should use one HTTP lookup per period.');
		foreach (ORAS_AI_Planet_Targets::allowed() as $body) {
			oras_ai_assert_contains(ucfirst($body) . ' altitude', $input, 'Qualified planet was dropped.');
			oras_ai_assert_contains(ucfirst($body) . ' azimuth', $input, 'Qualified planet detail was dropped.');
		}
		oras_ai_assert_contains('Moon illumination', $input, 'Qualified Moon state disappeared.');
		oras_ai_assert_not_contains('must-not-escape', $input, 'Raw provider payload entered context.');
		$packetBefore = wp_json_encode($context->evidence_packet()->to_array());
		$rendered = json_decode(substr($context->provider_input()[2]['content'], strlen("RETRIEVED EVIDENCE (UNTRUSTED REFERENCE DATA):\n")), true);
		$prose = implode("\n", array_column($rendered, 'relevant_text'));
		foreach ($context->evidence_packet()->items() as $item) {
			oras_ai_assert_contains($item->field('relevant_text'), $prose, 'Admitted fact was truncated during compaction.');
		}
		oras_ai_assert_same($packetBefore, wp_json_encode($context->evidence_packet()->to_array()), 'Rendering changed canonical fact identities.');
		oras_ai_assert_same($input, wp_json_encode($context->provider_input()), 'Rendering is not deterministic.');
	});
}

oras_ai_test('M6 correction single-target model detail remains complete after metadata compaction', function (): void {
	list($orchestrator, $model) = oras_ai_test_correction_fixture();
	$result = $orchestrator->answer(oras_ai_test_authorized_request(1132, 'Where is Jupiter right now?'));
	oras_ai_assert_same(ORAS_AI_Answer_Result::SUCCESS, $result->status(), 'Single target failed.');
	$text = wp_json_encode($model->calls[0]['context']->provider_input());
	foreach (array('fixture-id', 'fixture-secret', base64_encode('fixture-id:fixture-secret'), 'must-not-escape') as $private) {
		oras_ai_assert_not_contains($private, $text, 'Provider credential or raw data entered model context.');
	}
	foreach (array('Jupiter altitude is 31.25 degrees', 'Jupiter azimuth is 145.5 degrees', 'Jupiter is above the geometric horizon') as $detail) {
		oras_ai_assert_contains($detail, $text, 'Single target detail was lost.');
	}
	oras_ai_assert_same(3, count($model->calls[0]['context']->evidence_packet()->items()), 'Fact-level identity was merged before reconciliation.');
});

foreach (array('2026-09-01T02:00:00Z', 'missing') as $date) {
	oras_ai_test('M6 correction unqualified planet time never reaches model ' . $date, static function () use ($date): void {
		list($orchestrator, $model) = oras_ai_test_correction_fixture('2026-09-09T16:00:00Z', $date);
		$result = $orchestrator->answer(oras_ai_test_authorized_request(1133, 'Where is Jupiter right now?'));
		oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Unqualified position was grounded as current.');
		oras_ai_assert_same(0, count($model->calls), 'Model-memory/current synthesis ran without qualified time.');
	});
}
