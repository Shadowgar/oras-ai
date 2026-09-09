<?php
declare(strict_types=1);

const ORAS_AI_OPENNGC_COMMIT = 'da90466031b0372c896588b85be6016c617e205b';
const ORAS_AI_OPENNGC_NGC_SHA256 = 'be150bdaa1997dacbcb39f303074403edec7a953b589b36d5f1c4522c0cc6fae';
const ORAS_AI_OPENNGC_ADDENDUM_SHA256 = '1d8f0914e643ada325a5a94d88d8fefad6a4937a2f77cc34f21483af22b11983';

if (4 !== $argc) {
	fwrite(STDERR, "Usage: php tools/build-openngc-index.php NGC.csv addendum.csv output-directory\n");
	exit(1);
}

[$script, $ngcPath, $addendumPath, $outputDirectory] = $argv;
foreach (array($ngcPath => ORAS_AI_OPENNGC_NGC_SHA256, $addendumPath => ORAS_AI_OPENNGC_ADDENDUM_SHA256) as $path => $digest) {
	if (!is_file($path) || hash_file('sha256', $path) !== $digest) {
		fwrite(STDERR, "OpenNGC source digest mismatch: {$path}\n");
		exit(1);
	}
}

$objects = array();
$aliases = array();
foreach (array($ngcPath, $addendumPath) as $path) {
	$handle = fopen($path, 'rb');
	$header = fgetcsv($handle, 0, ';', '"', '\\');
	$columns = array_flip($header);
	while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
		$name = trim((string) ($row[$columns['Name']] ?? ''));
		$ra = oras_ai_openngc_ra_degrees((string) ($row[$columns['RA']] ?? ''));
		$dec = oras_ai_openngc_dec_degrees((string) ($row[$columns['Dec']] ?? ''));
		if ('' === $name || null === $ra || null === $dec) {
			continue;
		}
		$messier = trim((string) ($row[$columns['M']] ?? ''));
		$identity = '' !== $messier ? 'm' . (int) $messier : oras_ai_openngc_catalog_identity($name);
		if ('' === $identity) {
			continue;
		}
		$display = '' !== $messier ? 'M' . (int) $messier : oras_ai_openngc_display_name($name);
		$objects[$identity] = array(
			'display_name' => $display,
			'ra_degrees'   => $ra,
			'dec_degrees'  => $dec,
		);

		$candidates = array($identity, $name, $display);
		if ('' !== $messier) {
			$candidates[] = 'M ' . (int) $messier;
		}
		foreach (array('NGC' => 'ngc', 'IC' => 'ic') as $column => $prefix) {
			foreach (preg_split('/\s*,\s*/', (string) ($row[$columns[$column]] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $number) {
				$candidates[] = $prefix . (int) $number;
				$candidates[] = $prefix . ' ' . (int) $number;
			}
		}
		foreach (preg_split('/\s*,\s*/', (string) ($row[$columns['Common names']] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $commonName) {
			$candidates[] = $commonName;
		}
		foreach ($candidates as $candidate) {
			$key = oras_ai_openngc_alias_key($candidate);
			if ('' !== $key) {
				$aliases[$key][$identity] = $identity;
			}
		}
	}
	fclose($handle);
}

ksort($objects, SORT_STRING);
ksort($aliases, SORT_STRING);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
	throw new RuntimeException('Unable to create OpenNGC index directory.');
}
$objectShards = array_fill_keys(str_split('0123456789abcdef'), array());
$aliasShards = array_fill_keys(str_split('0123456789abcdef'), array());
foreach ($objects as $identity => $record) {
	$objectShards[hash('sha256', $identity)[0]][$identity] = $record;
}
foreach ($aliases as $key => $identities) {
	$values = array_values($identities);
	sort($values, SORT_STRING);
	$aliasShards[hash('sha256', $key)[0]][$key] = $values;
}
foreach ($objectShards as $shard => $records) {
	oras_ai_openngc_write_php($outputDirectory . '/objects-' . $shard . '.php', $records);
}
foreach ($aliasShards as $shard => $records) {
	oras_ai_openngc_write_php($outputDirectory . '/aliases-' . $shard . '.php', $records);
}
oras_ai_openngc_write_php(
	$outputDirectory . '/manifest.php',
	array(
		'project'          => 'OpenNGC',
		'commit'           => ORAS_AI_OPENNGC_COMMIT,
		'ngc_sha256'       => ORAS_AI_OPENNGC_NGC_SHA256,
		'addendum_sha256'  => ORAS_AI_OPENNGC_ADDENDUM_SHA256,
		'object_count'     => count($objects),
		'alias_count'      => count($aliases),
		'license'          => 'CC-BY-SA-4.0',
		'generated_format' => 1,
	)
);
echo count($objects) . " objects and " . count($aliases) . " aliases generated.\n";

function oras_ai_openngc_alias_key(string $alias): string {
	$alias = strtolower(trim($alias));
	return (string) preg_replace('/[^a-z0-9]+/', '', $alias);
}

function oras_ai_openngc_catalog_identity(string $name): string {
	$key = oras_ai_openngc_alias_key($name);
	if (preg_match('/^(ngc|ic|b|c)0*([0-9]+)([a-z]*)$/', $key, $matches)) {
		return $matches[1] . (int) $matches[2] . $matches[3];
	}
	return $key;
}

function oras_ai_openngc_display_name(string $name): string {
	if (preg_match('/^(NGC|IC|B|C)0*([0-9]+)([A-Z]*)$/i', $name, $matches)) {
		return strtoupper($matches[1]) . (int) $matches[2] . strtoupper($matches[3]);
	}
	return $name;
}

function oras_ai_openngc_ra_degrees(string $rightAscension): ?float {
	if (!preg_match('/^(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)$/', trim($rightAscension), $matches)) {
		return null;
	}
	return 15.0 * ((int) $matches[1] + (int) $matches[2] / 60.0 + (float) $matches[3] / 3600.0);
}

function oras_ai_openngc_dec_degrees(string $declination): ?float {
	if (!preg_match('/^([+-])(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)$/', trim($declination), $matches)) {
		return null;
	}
	$value = (int) $matches[2] + (int) $matches[3] / 60.0 + (float) $matches[4] / 3600.0;
	return '-' === $matches[1] ? -$value : $value;
}

function oras_ai_openngc_write_php(string $path, array $data): void {
	ksort($data, SORT_STRING);
	$contents = "<?php\n// Generated from pinned OpenNGC data; do not edit manually.\nreturn " . var_export($data, true) . ";\n";
	if (false === file_put_contents($path, $contents)) {
		throw new RuntimeException('Unable to write OpenNGC index.');
	}
}
