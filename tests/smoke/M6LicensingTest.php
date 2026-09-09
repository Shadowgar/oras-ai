<?php
declare(strict_types=1);

oras_ai_test('M6 licensing declares GPL3-or-later without claiming deferred dependencies are bundled', function (): void {
	$root = dirname(__DIR__, 2);
	$plugin = (string) file_get_contents($root . '/oras-ai-assistant.php');
	$notices = (string) file_get_contents($root . '/THIRD_PARTY_NOTICES.md');

	oras_ai_assert_contains('License: GPL-3.0-or-later', $plugin, 'Owner-approved plugin license declaration missing.');
	oras_ai_assert_contains('not bundled', strtolower($notices), 'Deferred dependency status is not explicit.');
	oras_ai_assert_contains('tuxonice/suncalc-php', $notices, 'Planned SunCalc notice placeholder missing.');
	oras_ai_assert_contains('OpenNGC', $notices, 'Planned OpenNGC notice placeholder missing.');
	oras_ai_assert_not_contains('is bundled', strtolower($notices), 'Notice falsely claims a deferred dependency is bundled.');
});
