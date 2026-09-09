<?php
declare(strict_types=1);

final class ORAS_AI_Test_Fixed_Clock implements ORAS_AI_Clock_Interface {
	private DateTimeImmutable $now;

	public function __construct(DateTimeImmutable $now) {
		$this->now = $now;
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}
}

final class ORAS_AI_Test_Horizon_Astronomy_Provider implements ORAS_AI_Astronomy_Provider_Interface {
	private DateTimeImmutable $supported_through;

	public function __construct(DateTimeImmutable $supported_through) {
		$this->supported_through = $supported_through;
	}

	public function provider_id() {
		return 'test_astronomy';
	}

	public function fetch(ORAS_AI_Current_Data_Request $request) {
		if ($request->requested_at() > $this->supported_through) {
			return ORAS_AI_Current_Data_Result::unavailable($this->provider_id(), 'outside_provider_horizon');
		}

		return ORAS_AI_Current_Data_Result::unknown($this->provider_id(), 'not_implemented');
	}
}

function oras_ai_test_expect_invalid_argument(callable $callback, string $message): void {
	try {
		$callback();
		throw new RuntimeException($message . ' Expected InvalidArgumentException.');
	} catch (InvalidArgumentException $exception) {
		oras_ai_assert_true('' !== $exception->getMessage(), $message . ' Exception must be bounded and non-empty.');
	}
}

oras_ai_test('M6 observing site uses the exact owner-approved server values', function (): void {
	$site = ORAS_AI_Observing_Site::oras_observatory();

	oras_ai_assert_same(41.321903, $site->latitude(), 'Authoritative latitude changed.');
	oras_ai_assert_same(-79.585394, $site->longitude(), 'Authoritative longitude changed.');
	oras_ai_assert_same(432.816, $site->elevation_meters(), 'Authoritative elevation changed.');
	oras_ai_assert_same('America/New_York', $site->timezone_name(), 'Authoritative timezone changed.');
});

oras_ai_test('M6 observing site rejects invalid coordinates elevation and timezone', function (): void {
	$invalidSites = array(
		array(90.1, -79.585394, 432.816, 'America/New_York'),
		array(41.321903, -180.1, 432.816, 'America/New_York'),
		array(41.321903, -79.585394, 'not-a-number', 'America/New_York'),
		array(41.321903, -79.585394, INF, 'America/New_York'),
		array(41.321903, -79.585394, 432.816, 'EST'),
		array(41.321903, -79.585394, 432.816, 'Not/A_Timezone'),
	);

	foreach ($invalidSites as $site) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($site): void {
				new ORAS_AI_Observing_Site($site[0], $site[1], $site[2], $site[3]);
			},
			'Invalid observing site was accepted.'
		);
	}
});

oras_ai_test('M6 clock is injectable and request times are immutable UTC instants', function (): void {
	$fixed = new DateTimeImmutable('2026-01-15T12:34:56-05:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($fixed);
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(801, 'What is the Moon doing tonight?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::MOON_STATE)
	);

	oras_ai_assert_same('2026-01-15T17:34:56+00:00', $request->requested_at()->format(DATE_ATOM), 'Trusted clock instant was not normalized to UTC.');
	oras_ai_assert_true($request->requested_at() instanceof DateTimeImmutable, 'Requested instant must remain immutable.');
	oras_ai_assert_same('2026-01-15T12:34:56-05:00', $request->local_requested_at()->format(DATE_ATOM), 'Site-local instant changed.');
});

oras_ai_test('M6 time context preserves America New York DST transitions', function (): void {
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-03-08T06:30:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(802, 'What is visible after the DST change?'),
		$clock,
		array(ORAS_AI_Current_Data_Request::TARGET_POSITION),
		new DateTimeImmutable('2026-03-08T07:30:00+00:00'),
		null,
		'ngc:1976'
	);

	oras_ai_assert_same('2026-03-08T03:30:00-04:00', $request->local_requested_at()->format(DATE_ATOM), 'DST offset must come from the authoritative IANA timezone.');
});

oras_ai_test('M6 provider-neutral request does not impose a seven-day weather horizon on astronomy', function (): void {
	$now = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($now);
	$authorized = oras_ai_test_authorized_request(803, 'Plan an observing window.');
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		$authorized,
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		$now->modify('+10 days'),
		$now->modify('+10 days 8 hours'),
		'planet:saturn'
	);

	oras_ai_assert_same('2026-09-19T12:00:00+00:00', $request->requested_at()->format(DATE_ATOM), 'Future astronomy instant was changed or rejected by a global provider horizon.');
	oras_ai_assert_same('2026-09-19T20:00:00+00:00', $request->window_end()->format(DATE_ATOM), 'Finite observation-window end was changed.');
});

oras_ai_test('M6 current-data request keeps observation intervals closed and rejects invalid ordering', function (): void {
	$now = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$clock = new ORAS_AI_Test_Fixed_Clock($now);
	$authorized = oras_ai_test_authorized_request(803, 'Plan an observing window.');
	$instant = ORAS_AI_Current_Data_Request::from_authorized_request(
		$authorized,
		$clock,
		array(ORAS_AI_Current_Data_Request::WEATHER_CONDITIONS),
		$now->modify('+1 hour')
	);

	oras_ai_assert_same($instant->requested_at()->format(DATE_ATOM), $instant->window_end()->format(DATE_ATOM), 'An omitted end must be a bounded point request, not an open interval.');

	foreach (
		array(
			array($now->modify('-1 second'), null),
			array($now->modify('+2 hours'), $now->modify('+1 hour')),
		) as $times
	) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($authorized, $clock, $times): void {
				ORAS_AI_Current_Data_Request::from_authorized_request(
					$authorized,
					$clock,
					array(ORAS_AI_Current_Data_Request::WEATHER_CONDITIONS),
					$times[0],
					$times[1]
				);
			},
			'Invalid observation window was accepted.'
		);
	}
});

oras_ai_test('M6 provider adapters own their supported forecast or calculation horizon', function (): void {
	$now = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		oras_ai_test_authorized_request(806, 'Where is Saturn in ten days?'),
		new ORAS_AI_Test_Fixed_Clock($now),
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		$now->modify('+10 days'),
		null,
		'planet:saturn'
	);
	$short_horizon_provider = new ORAS_AI_Test_Horizon_Astronomy_Provider($now->modify('+7 days'));
	$long_horizon_provider = new ORAS_AI_Test_Horizon_Astronomy_Provider($now->modify('+30 days'));

	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNAVAILABLE, $short_horizon_provider->fetch($request)->status(), 'Adapter-specific short horizon was not enforced by the adapter.');
	oras_ai_assert_same('outside_provider_horizon', $short_horizon_provider->fetch($request)->reason(), 'Adapter-specific horizon reason changed.');
	oras_ai_assert_same(ORAS_AI_Current_Data_Result::UNKNOWN, $long_horizon_provider->fetch($request)->status(), 'Provider-neutral request imposed a shorter horizon than the adapter supports.');
});

oras_ai_test('M6 current-data request derives identity and site only from trusted server objects', function (): void {
	$authorized = oras_ai_test_authorized_request(804, 'Where is Saturn tonight?');
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
	$request = ORAS_AI_Current_Data_Request::from_authorized_request(
		$authorized,
		$clock,
		array(ORAS_AI_Current_Data_Request::PLANET_POSITION),
		null,
		null,
		'planet:saturn'
	);

	oras_ai_assert_same(804, $request->user_id(), 'Authorized identity did not survive the trusted boundary.');
	oras_ai_assert_same($authorized, $request->authorized_request(), 'Current-data request must retain the typed authorization origin.');
	oras_ai_assert_same(ORAS_AI_Observing_Site::oras_observatory()->to_array(), $request->site()->to_array(), 'Request did not force the authoritative ORAS site.');
	oras_ai_assert_same('planet:saturn', $request->target_identity(), 'Normalized target identity changed.');
	oras_ai_assert_false(method_exists(ORAS_AI_Current_Data_Request::class, 'from_array'), 'Browser arrays must not construct a trusted current-data request.');
	oras_ai_assert_false(method_exists($request, 'provider'), 'Client-selectable provider must not exist in the request contract.');
});

oras_ai_test('M6 current-data request accepts only deterministic fact types and target identities', function (): void {
	$authorized = oras_ai_test_authorized_request(805, 'Where is the target?');
	$clock = new ORAS_AI_Test_Fixed_Clock(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));

	foreach (
		array(
			array(array('https://evil.example/api'), ''),
			array(array('arbitrary_provider_payload'), ''),
			array(array(ORAS_AI_Current_Data_Request::TARGET_POSITION), 'https://evil.example/target'),
		) as $case
	) {
		oras_ai_test_expect_invalid_argument(
			static function () use ($authorized, $clock, $case): void {
				ORAS_AI_Current_Data_Request::from_authorized_request($authorized, $clock, $case[0], null, null, $case[1]);
			},
			'Untrusted current-data selector was accepted.'
		);
	}
});
