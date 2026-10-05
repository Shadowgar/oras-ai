<?php
declare(strict_types=1);

/** Exercise the actual runner against a disposable, minimal test repository. */
function oras_ai_test_isolated_runner(string $fixture): array {
	$root = tempnam(sys_get_temp_dir(), 'oras-ai-runner-');
	if (false === $root) {
		throw new RuntimeException('Unable to create runner fixture.');
	}
	unlink($root);
	mkdir($root);
	mkdir($root . '/tools');
	mkdir($root . '/tests');
	try {
		copy(dirname(__DIR__, 2) . '/tools/run-tests.php', $root . '/tools/run-tests.php');
		file_put_contents($root . '/tests/bootstrap.php', '<?php $GLOBALS["oras_ai_tests"] = array();');
		file_put_contents($root . '/tests/WarningTest.php', '<?php ' . $fixture);
		$output = array();
		$code = 0;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/run-tests.php') . ' 2>&1', $output, $code);
		return array($code, implode("\n", $output));
	} finally {
		foreach (array('/tests/WarningTest.php', '/tests/bootstrap.php', '/tools/run-tests.php') as $file) {
			if (file_exists($root . $file)) { unlink($root . $file); }
		}
		rmdir($root . '/tests');
		rmdir($root . '/tools');
		rmdir($root);
	}
}

foreach (array(
	'E_WARNING' => 'fopen(__DIR__ . "/missing-file", "r");',
	'E_USER_WARNING' => 'trigger_error("Unexpected fixture warning", E_USER_WARNING);',
	'caught E_USER_WARNING' => 'try { trigger_error("Unexpected fixture warning", E_USER_WARNING); } catch (Throwable $error) {}',
) as $label => $body) {
	oras_ai_test('M9 runner rejects unexpected ' . $label, static function () use ($body): void {
		list($code, $output) = oras_ai_test_isolated_runner('$GLOBALS["oras_ai_tests"]["warning fixture"] = static function () { ' . $body . ' };');
		oras_ai_assert_same(1, $code, 'Unexpected warning did not fail the isolated run: ' . $output);
		oras_ai_assert_contains('FAIL warning fixture', $output, 'Warning was not attributed to its test.');
		oras_ai_assert_contains('1 test(s) failed.', $output, 'Warning failure was omitted from the run result.');
	});
}

oras_ai_test('M9 runner permits an explicitly handled warning and leaves deprecation policy unchanged', static function (): void {
	$fixture = <<<'PHP'
$GLOBALS['oras_ai_tests']['expected warning'] = static function () {
    $seen = false;
    set_error_handler(static function () use (&$seen) { $seen = true; return true; }, E_USER_WARNING);
    try { trigger_error('Expected fixture warning', E_USER_WARNING); }
    finally { restore_error_handler(); }
    if (!$seen) { throw new RuntimeException('Expected warning handler was not called.'); }
    trigger_error('Tolerated fixture deprecation', E_USER_DEPRECATED);
};
$GLOBALS['oras_ai_tests']['following test'] = static function () {};
PHP;
	list($code, $output) = oras_ai_test_isolated_runner($fixture);
	oras_ai_assert_same(0, $code, 'Expected warning or deprecation failed the run: ' . $output);
	oras_ai_assert_contains('2 test(s) passed.', $output, 'Runner did not continue after the handled warning.');
});

foreach (array('success' => '', 'failure' => 'throw new RuntimeException("Fixture failure");') as $label => $body) {
	oras_ai_test('M9 runner restores its caller error handler after ' . $label, static function () use ($label, $body): void {
		$fixture = 'set_error_handler(static function () { echo "CALLER_HANDLER_RESTORED\n"; return true; }, E_USER_WARNING);'
			. 'register_shutdown_function(static function () { trigger_error("Caller shutdown warning", E_USER_WARNING); });'
			. '$GLOBALS["oras_ai_tests"]["restoration fixture"] = static function () { ' . $body . ' };';
		list($code, $output) = oras_ai_test_isolated_runner($fixture);
		oras_ai_assert_same('success' === $label ? 0 : 1, $code, 'Unexpected isolated fixture result: ' . $output);
		oras_ai_assert_contains('CALLER_HANDLER_RESTORED', $output, 'Runner left its scoped error handler installed.');
	});
}
