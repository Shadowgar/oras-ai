<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');

if (false === $root) {
	fwrite(STDERR, "Unable to resolve repository root.\n");
	exit(1);
}

require_once $root . '/tests/bootstrap.php';

$testFiles = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if (
		$file instanceof SplFileInfo &&
		$file->isFile() &&
		preg_match('/Test\.php$/', $file->getFilename())
	) {
		$testFiles[] = $file->getPathname();
	}
}

sort($testFiles);

foreach ($testFiles as $testFile) {
	require_once $testFile;
}

$tests = $GLOBALS['oras_ai_tests'] ?? array();

if (empty($tests)) {
	fwrite(STDERR, "No tests registered.\n");
	exit(1);
}

$failures = 0;

foreach ($tests as $name => $callback) {
	$warnings = array();
	set_error_handler(static function ($severity, $message, $file, $line) use (&$warnings): void {
		$warnings[] = $message . ' in ' . $file . ':' . $line;
		throw new ErrorException($message, 0, $severity, $file, $line);
	}, E_WARNING | E_USER_WARNING);
	try {
		$callback();
		// A production catch must not hide an unexpected warning from qualification.
		if ($warnings) {
			throw new RuntimeException(implode("\n", $warnings));
		}
		echo "PASS {$name}\n";
	} catch (Throwable $throwable) {
		$failures++;
		echo "FAIL {$name}\n";
		echo $throwable->getMessage() . "\n";
	} finally {
		restore_error_handler();
	}
}

if ($failures > 0) {
	fwrite(STDERR, "{$failures} test(s) failed.\n");
	exit(1);
}

echo count($tests) . " test(s) passed.\n";
