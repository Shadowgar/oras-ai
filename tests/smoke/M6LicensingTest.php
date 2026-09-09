<?php
declare(strict_types=1);

oras_ai_test('M6 licensing pins SunCalc and attributes generated OpenNGC data without tracking vendor', function (): void {
	$root = dirname(__DIR__, 2);
	$plugin = (string) file_get_contents($root . '/oras-ai-assistant.php');
	$notices = (string) file_get_contents($root . '/THIRD_PARTY_NOTICES.md');
	$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
	$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
	$packages = array_column($lock['packages'] ?? array(), null, 'name');
	$manifest = require $root . '/data/openngc/manifest.php';

	oras_ai_assert_contains('License: GPL-3.0-or-later', $plugin, 'Owner-approved plugin license declaration missing.');
	oras_ai_assert_contains('Requires PHP: 8.0', $plugin, 'Plugin header contradicts the approved PHP runtime floor.');
	oras_ai_assert_same('>=8.0', $composer['require']['php'] ?? '', 'Composer PHP requirement contradicts the plugin header.');
	oras_ai_assert_same('v1.0.1', $packages['tuxonice/suncalc-php']['version'] ?? '', 'SunCalc Composer version is not reproducibly locked.');
	oras_ai_assert_same('8b8ca60f8af20d38c00121615beab999ca5dcd55', $packages['tuxonice/suncalc-php']['source']['reference'] ?? '', 'SunCalc source commit changed.');
	oras_ai_assert_contains("if ( file_exists( ORAS_AI_PLUGIN_DIR . 'vendor/autoload.php' ) )", $plugin, 'Missing Composer autoload can fatal plugin loading.');
	oras_ai_assert_contains('not bundled or tracked', strtolower($notices), 'Composer vendor policy is not explicit.');
	oras_ai_assert_contains('vendor/', (string) file_get_contents($root . '/.gitignore'), 'Composer vendor directory is not ignored.');
	oras_ai_assert_contains('CC BY-SA 4.0', $notices, 'OpenNGC share-alike attribution is missing.');
	oras_ai_assert_true(is_file($root . '/data/openngc/CC-BY-SA-4.0.txt'), 'OpenNGC license text is missing.');
	oras_ai_assert_same('OpenNGC', $manifest['project'] ?? '', 'Generated catalog is not identified as OpenNGC-derived data.');
	oras_ai_assert_same('CC-BY-SA-4.0', $manifest['license'] ?? '', 'Generated catalog license metadata changed.');
	oras_ai_assert_same(1, $manifest['generated_format'] ?? null, 'Generated catalog format version is missing.');
});
