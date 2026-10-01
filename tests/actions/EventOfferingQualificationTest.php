<?php
declare(strict_types=1);

oras_ai_test('M8 event offering resolves only after trusted event identity and keeps schedule on provider failure', function (): void {
	$lookups = array();
	$offeringIds = array();
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function ($subject) use (&$lookups): array {
			$lookups[] = $subject;
			return array(oras_ai_test_event_record(array('end' => '2026-10-18 23:00:00', 'start' => '2026-10-18 20:00:00')));
		},
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function ($eventId) use (&$offeringIds) {
			$offeringIds[] = $eventId;
			return new WP_Error('provider_down');
		}
	);
	$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('Can I register for AstroBlast? product_id=444 event_id=555'));
	oras_ai_assert_true($result->successful(), 'Schedule should survive offering failure.');
	oras_ai_assert_same(array(901), $offeringIds, 'Member-supplied IDs reached the offering provider.');
	oras_ai_assert_same('event:astroblast:start', $result->facts()[0]->fact_key(), 'Schedule fact was lost.');
	oras_ai_assert_same('event:astroblast:registration', $result->failed_fact_keys()[0], 'Offering failure was not scoped.');
});

oras_ai_test('M8 event offering projects open full closed and unknown without private provider fields', function (): void {
	foreach (array('open', 'full', 'closed', 'unknown') as $state) {
		$connector = new ORAS_AI_Events_Calendar_Connector(
			static function (): array { return array(oras_ai_test_event_record(array('end' => '2026-10-18 23:00:00', 'start' => '2026-10-18 20:00:00'))); },
			static function (): string { return '2026-09-27T12:00:00-04:00'; },
			static function () use ($state): array { return array(array('state' => $state, 'attendees' => array('private@example.test'), 'product_id' => 444)); }
		);
		$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('Can I register for AstroBlast?'));
		$facts = oras_ai_test_facts_by_key($result);
		oras_ai_assert_same($state, $facts['event:astroblast:registration']->field('comparison_value'), 'Offering state was not projected.');
		$serialized = json_encode(array_map(static fn($fact): array => $fact->to_array(), $result->facts()));
		oras_ai_assert_false(str_contains($serialized, 'private@example.test'), 'Private provider fields leaked.');
		oras_ai_assert_false(str_contains($serialized, '444'), 'Provider ID leaked.');
	}
});

oras_ai_test('M8 event answer rejects model registration claims that contradict live provider state', function (): void {
	foreach (array('open', 'full', 'closed', 'unknown') as $state) {
		oras_ai_test_reset();
		$connector = new ORAS_AI_Events_Calendar_Connector(
			static function (): array { return array(oras_ai_test_event_record(array('end' => '2026-10-18 23:00:00', 'start' => '2026-10-18 20:00:00'))); },
			static function (): string { return '2026-09-27T12:00:00-04:00'; },
			static function () use ($state): array { return array(array('state' => $state)); }
		);
		list($orchestrator) = oras_ai_test_answer_fixture(
			new ORAS_AI_Evidence_Packet(),
			oras_ai_test_provider_success('Tickets are available. Register now at https://evil.test/ and your registration is complete.'),
			array(),
			oras_ai_test_live_service($connector)
		);
		$result = $orchestrator->answer(oras_ai_test_authorized_request(71, 'Can I register for AstroBlast?'));
		oras_ai_assert_not_contains('evil.test', $result->answer(), 'Model URL escaped the action boundary.');
		oras_ai_assert_not_contains('registration is complete', strtolower($result->answer()), 'Model claimed completed registration.');
		if ('open' === $state) {
			oras_ai_assert_contains('currently open', $result->answer(), 'Open state was suppressed.');
		} else {
			oras_ai_assert_not_contains('Registration is currently open', $result->answer(), 'Non-open state became open.');
		}
	}
});

oras_ai_test('M8 event offering rejects a provider link unrelated to its trusted event', function (): void {
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function (): array { return array(oras_ai_test_event_record(array('end' => '2026-10-18 23:00:00', 'start' => '2026-10-18 20:00:00'))); },
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function (): array { return array(array('state' => 'open', 'url' => 'https://evil.test/register')); }
	);
	$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('Where can I register for AstroBlast?'));
	$facts = oras_ai_test_facts_by_key($result);
	oras_ai_assert_same('unknown', $facts['event:astroblast:registration']->field('comparison_value'), 'Unsafe provider link permitted open registration.');
	oras_ai_assert_same('', $facts['event:astroblast:registration']->field('canonical_url'), 'Unsafe provider link was exposed.');
	oras_ai_assert_true(isset($facts['event:astroblast:start']), 'Valid schedule was lost to unsafe registration link.');
});

oras_ai_test('M8 generic upcoming event resolves a published event and its offering', function (): void {
	$subjects = array();
	$ids = array();
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function ($subject) use (&$subjects): array {
			$subjects[] = $subject;
			return array(oras_ai_test_event_record(array('start' => '2026-10-18 20:00:00', 'end' => '2026-10-18 23:00:00')));
		},
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function ($eventId) use (&$ids): array { $ids[] = $eventId; return array(array('state' => 'open')); }
	);
	$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('What upcoming event can I attend?'));
	oras_ai_assert_true($result->successful(), 'Generic upcoming event did not resolve.');
	oras_ai_assert_same(array('upcoming'), $subjects, 'Generic event lookup used the wrong route.');
	oras_ai_assert_same(array(901), $ids, 'Generic offering did not use the resolved event.');
	oras_ai_assert_same('open', oras_ai_test_facts_by_key($result)['event:upcoming:registration']->field('comparison_value'), 'Generic event offering was not qualified.');
});

oras_ai_test('M8 generic recommendation skips an unavailable offering when a later event is open', function (): void {
	$ids = array();
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function (): array {
			return array(
				oras_ai_test_event_record(array('id'=>901,'start'=>'2026-10-01 20:00:00','end'=>'2026-10-01 22:00:00')),
				oras_ai_test_event_record(array('id'=>902,'title'=>'ORAS Public Night','start'=>'2026-10-02 20:00:00','end'=>'2026-10-02 22:00:00','canonical_url'=>'https://oras.org/events/public-night/')),
			);
		},
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function ($id) use (&$ids) { $ids[]=$id; return 901===$id ? new WP_Error('missing') : array(array('state'=>'open')); }
	);
	$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('What upcoming event can I attend?'));
	$facts = oras_ai_test_facts_by_key($result);
	oras_ai_assert_same(902, $facts['event:upcoming:registration']->field('source_wp_object_id'), 'Later open event was erased by earlier provider failure.');
	oras_ai_assert_same(array(901,902), $ids, 'Upcoming recommendation did not inspect the bounded event candidates.');
});

oras_ai_test('M8 event availability path contains no registration or commerce mutation calls', function (): void {
	$source = file_get_contents(__DIR__ . '/../../includes/class-oras-ai-events-calendar-connector.php');
	oras_ai_assert_false((bool) preg_match('/\b(?:wp_insert_post|wp_update_post|update_post_meta|add_post_meta|update_user_meta|wc_create_order|add_to_cart|process_payment|handle_post)\s*\(/', $source), 'Event offering connector acquired a mutation path.');
});

oras_ai_test('M8 sibling ticket states retain an open option but mixed non-open states do not become sold out', function (): void {
	foreach (array('open'=>array('full','open'), 'unknown'=>array('full','closed')) as $expected=>$states) {
		$connector = new ORAS_AI_Events_Calendar_Connector(
			static function (): array { return array(oras_ai_test_event_record(array('start'=>'2026-10-18 20:00:00','end'=>'2026-10-18 23:00:00'))); },
			static function (): string { return '2026-09-27T12:00:00-04:00'; },
			static function () use ($states): array { return array_map(static fn($state): array => array('state'=>$state), $states); }
		);
		$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('Are tickets available for AstroBlast?'));
		$fact = oras_ai_test_facts_by_key($result)['event:astroblast:registration'];
		oras_ai_assert_same($expected, $fact->field('comparison_value'), 'Mixed ticket states were flattened into a false event claim.');
	}
});

oras_ai_test('M8 unrelated unsafe sibling link cannot erase another qualified open offering', function (): void {
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function (): array { return array(oras_ai_test_event_record(array('start'=>'2026-10-18 20:00:00','end'=>'2026-10-18 23:00:00'))); },
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function (): array { return array(array('state'=>'open','url'=>'https://evil.test/'),array('state'=>'open')); }
	);
	$result = oras_ai_test_live_service($connector)->query(oras_ai_test_live_request('Can I register for AstroBlast?'));
	$fact = oras_ai_test_facts_by_key($result)['event:astroblast:registration'];
	oras_ai_assert_same('open', $fact->field('comparison_value'), 'Unsafe sibling poisoned an independent open offering.');
	oras_ai_assert_same('https://oras.org/events/astroblast-2026/', $fact->field('canonical_url'), 'Qualified sibling lost the canonical event link.');
});

oras_ai_test('M8 generic upcoming answer uses the selected event link and ignores model completion', function (): void {
	oras_ai_test_reset();
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function (): array { return array(oras_ai_test_event_record(array('start'=>'2026-10-18 20:00:00','end'=>'2026-10-18 23:00:00'))); },
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function (): array { return array(array('state'=>'open')); }
	);
	list($orchestrator) = oras_ai_test_answer_fixture(new ORAS_AI_Evidence_Packet(), oras_ai_test_provider_success('Your ticket is booked.'), array(), oras_ai_test_live_service($connector));
	$result = $orchestrator->answer(oras_ai_test_authorized_request(71, 'What upcoming event can I attend?'));
	oras_ai_assert_contains('AstroBlast', $result->answer(), 'Selected upcoming event was not named: ' . $result->answer());
	oras_ai_assert_contains('currently open', $result->answer(), 'Selected offering state was omitted.');
	oras_ai_assert_not_contains('booked', $result->answer(), 'Model invented a completed booking.');
	oras_ai_assert_same('https://oras.org/events/astroblast-2026/', $result->sources()[0]['canonical_url'], 'Generic event handoff used the wrong URL.');
});

oras_ai_test('M8 event offering failure uses bounded existing Events connector health', function (): void {
	oras_ai_test_reset();
	$connector = new ORAS_AI_Events_Calendar_Connector(
		static function (): array { return array(oras_ai_test_event_record(array('start'=>'2026-10-18 20:00:00','end'=>'2026-10-18 23:00:00'))); },
		static function (): string { return '2026-09-27T12:00:00-04:00'; },
		static function () { throw new RuntimeException('private provider details'); }
	);
	$observer = new ORAS_AI_Connector_Observability();
	$service = new ORAS_AI_Live_Service(array($connector), new ORAS_AI_URL_Policy(array('oras.org')), $observer);
	$result = $service->query(oras_ai_test_live_request('Can I register for AstroBlast?'));
	oras_ai_assert_true($result->successful(), 'Schedule failed with provider exception.');
	$health = $observer->snapshot()['events_calendar'];
	oras_ai_assert_same('event_offering_unavailable', $health['last_failure_reason'], 'Provider failure was not observable by safe reason.');
	oras_ai_assert_false(str_contains(json_encode($health), 'private provider details'), 'Provider exception leaked to connector health.');
});

oras_ai_test('M8 registration questions without an event identity request a name and never invent availability', function (): void {
	foreach (array('Where can I register?', 'Are tickets still available for this event?') as $question) {
		oras_ai_test_reset();
		$lookups = array();
		$connector = oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $lookups);
		list($orchestrator, $provider) = oras_ai_test_answer_fixture(
			new ORAS_AI_Evidence_Packet(),
			oras_ai_test_provider_success('Tickets are available. Register now.'),
			array(),
			oras_ai_test_live_service($connector)
		);
		$result = $orchestrator->answer(oras_ai_test_authorized_request(71, $question));
		oras_ai_assert_same(ORAS_AI_Answer_Result::NO_EVIDENCE, $result->status(), 'Unidentified event received a positive answer.');
		oras_ai_assert_contains('name the event', strtolower($result->answer()), 'Unidentified event did not get an actionable clarification.');
		oras_ai_assert_same(array(), $lookups, 'Unidentified event reached a provider lookup.');
		oras_ai_assert_same(0, count($provider->calls), 'Unidentified event reached the model.');
	}
});
