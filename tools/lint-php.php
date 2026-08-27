<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');

if (false === $root) {
	fwrite(STDERR, "Unable to resolve repository root.\n");
	exit(1);
}

$skipDirs = array(
	DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
);

$files = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if (!$file instanceof SplFileInfo || !$file->isFile()) {
		continue;
	}

	$path = $file->getPathname();

	foreach ($skipDirs as $skipDir) {
		if (false !== strpos($path, $skipDir)) {
			continue 2;
		}
	}

	if ('php' === strtolower($file->getExtension())) {
		$files[] = $path;
	}
}

sort($files);

if (empty($files)) {
	echo "No PHP files found.\n";
	exit(0);
}

$failures = 0;

foreach ($files as $file) {
	$command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
	$output = array();
	$code = 0;

	exec($command, $output, $code);

	$relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
	$relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

	if (0 === $code) {
		echo "OK   {$relative}\n";
		continue;
	}

	$failures++;
	echo "FAIL {$relative}\n";
	echo implode("\n", $output) . "\n";
}

if ($failures > 0) {
	fwrite(STDERR, "{$failures} PHP file(s) failed lint.\n");
	exit(1);
}

echo count($files) . " PHP file(s) passed lint.\n";
