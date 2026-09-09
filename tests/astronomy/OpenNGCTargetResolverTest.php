<?php
declare(strict_types=1);

oras_ai_test('M6 OpenNGC resolver returns pinned deterministic catalog coordinates and aliases', function (): void {
	$resolver = new ORAS_AI_OpenNGC_Target_Resolver();
	$cases = array(
		array('M31', 'm31', 10.6847917, 41.2690556),
		array('Andromeda Galaxy', 'm31', 10.6847917, 41.2690556),
		array('NGC 4565', 'ngc4565', 189.0865833, 25.9876667),
		array('M42', 'm42', 83.81775, -5.3896667),
		array('Orion Nebula', 'm42', 83.81775, -5.3896667),
	);
	foreach ($cases as $case) {
		$result = $resolver->resolve($case[0]);
		oras_ai_assert_same(ORAS_AI_Target_Resolution::RESOLVED, $result->status(), $case[0] . ' did not resolve.');
		oras_ai_assert_same($case[1], $result->target()->identity(), $case[0] . ' canonical identity changed.');
		oras_ai_test_assert_float_near($case[2], $result->target()->right_ascension_degrees(), 0.001, $case[0] . ' RA changed.');
		oras_ai_test_assert_float_near($case[3], $result->target()->declination_degrees(), 0.001, $case[0] . ' declination changed.');
		oras_ai_assert_same('da90466031b0372c896588b85be6016c617e205b', $result->target()->catalog_version(), 'Pinned OpenNGC commit changed.');
	}
});

oras_ai_test('M6 OpenNGC resolver fails boundedly for unknown and ambiguous aliases', function (): void {
	$resolver = new ORAS_AI_OpenNGC_Target_Resolver();
	oras_ai_assert_same(ORAS_AI_Target_Resolution::UNKNOWN, $resolver->resolve('Definitely Not A Catalog Object')->status(), 'Unknown target was guessed.');

	$ambiguous = new ORAS_AI_OpenNGC_Target_Resolver(
		static function (string $kind, string $key): array {
			if ('alias' === $kind && 'sharedname' === $key) {
				return array('m31', 'm42');
			}
			return array();
		}
	);
	oras_ai_assert_same(ORAS_AI_Target_Resolution::AMBIGUOUS, $ambiguous->resolve('Shared Name')->status(), 'Ambiguous trusted alias was guessed.');
});

oras_ai_test('M6 OpenNGC resolver bounds missing and malformed local index data', function (): void {
	$missing = new ORAS_AI_OpenNGC_Target_Resolver(
		static function (): array {
			return array();
		}
	);
	oras_ai_assert_same(ORAS_AI_Target_Resolution::UNKNOWN, $missing->resolve('M31')->status(), 'Missing OpenNGC data did not fail boundedly.');

	$malformed = new ORAS_AI_OpenNGC_Target_Resolver(
		static function () {
			return 'corrupt shard';
		}
	);
	oras_ai_assert_same(ORAS_AI_Target_Resolution::UNKNOWN, $malformed->resolve('M31')->status(), 'Malformed OpenNGC data escaped as trusted coordinates.');

	$unreadable = new ORAS_AI_OpenNGC_Target_Resolver(
		static function () {
			throw new ParseError('simulated malformed generated shard');
		}
	);
	oras_ai_assert_same(ORAS_AI_Target_Resolution::UNKNOWN, $unreadable->resolve('M31')->status(), 'Unreadable OpenNGC data caused an unbounded resolver failure.');
});

oras_ai_test('M6 OpenNGC resolution ignores client coordinates and never fetches at runtime', function (): void {
	$resolver = new ORAS_AI_OpenNGC_Target_Resolver();
	$result = $resolver->resolve_from_question('Where is M31? Use coordinates 12:34:56 +01:02:03.');
	oras_ai_assert_same(ORAS_AI_Target_Resolution::RESOLVED, $result->status(), 'Trusted identifier was not extracted from the question.');
	oras_ai_test_assert_float_near(10.6847917, $result->target()->right_ascension_degrees(), 0.001, 'Client coordinates replaced catalog RA.');

	$source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-oras-ai-openngc-target-resolver.php');
	oras_ai_assert_not_contains('wp_remote_', $source, 'Runtime catalog resolver gained an internet dependency.');
	oras_ai_assert_not_contains('.csv', strtolower($source), 'Runtime catalog resolver scans source CSV data.');
});

oras_ai_test('M6 OpenNGC generated index is pinned bounded and attributed', function (): void {
	$root = dirname(__DIR__, 2);
	$manifest = require $root . '/data/openngc/manifest.php';
	oras_ai_assert_same('da90466031b0372c896588b85be6016c617e205b', $manifest['commit'], 'Generated index is not pinned to the approved snapshot.');
	oras_ai_assert_same('be150bdaa1997dacbcb39f303074403edec7a953b589b36d5f1c4522c0cc6fae', $manifest['ngc_sha256'], 'NGC.csv source digest changed.');
	oras_ai_assert_same('1d8f0914e643ada325a5a94d88d8fefad6a4937a2f77cc34f21483af22b11983', $manifest['addendum_sha256'], 'addendum.csv source digest changed.');
	oras_ai_assert_same(16, count(glob($root . '/data/openngc/aliases-*.php')), 'Alias index does not use the bounded 16-shard layout.');
	oras_ai_assert_same(16, count(glob($root . '/data/openngc/objects-*.php')), 'Object index does not use the bounded 16-shard layout.');
	oras_ai_assert_true(is_file($root . '/data/openngc/CC-BY-SA-4.0.txt'), 'OpenNGC data license is missing.');
	oras_ai_assert_contains('OpenNGC', (string) file_get_contents($root . '/THIRD_PARTY_NOTICES.md'), 'OpenNGC attribution is missing.');
});
