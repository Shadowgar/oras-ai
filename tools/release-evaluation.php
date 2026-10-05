<?php
declare(strict_types=1);

// No WordPress bootstrap, secrets or network in the default fixture command.
$root = dirname(__DIR__);
$options = getopt('', array('live', 'config:', 'output:'));
$live = array_key_exists('live', $options);
$directory = $options['output'] ?? sys_get_temp_dir() . '/oras-ai-release-evaluation-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
if (!is_string($directory) || file_exists($directory) || !mkdir($directory, 0700, true)) { fwrite(STDERR, "Evidence directory must be new and writable.\n"); exit(2); }
umask(0077);
$journal = fopen($directory . '/events.jsonl', 'x');
$checkpoint = static function (array $record) use ($journal): void {
	$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if (fwrite($journal, $line) !== strlen($line) || !fflush($journal)) { throw new RuntimeException('evidence_write_failed'); }
};
$started = microtime(true);
try {
	if (!$live) {
		require_once $root . '/tests/bootstrap.php';
		require_once __DIR__ . '/evaluation/harness.php';
		$report = ORAS_AI_Release_Evaluation::fixture_run();
	} else {
		// Owner-created local marker and explicit JSON path are checked BEFORE executing WP.
		$path = $options['config'] ?? '';
		if (!is_string($path) || !is_file($path) || (fileperms($path) & 0077)) { throw new RuntimeException('live_configuration_missing'); }
		$configuration = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($configuration) || ($configuration['opt_in'] ?? '') !== 'I_AUTHORIZE_DISPOSABLE_PAID_EVALUATION') { throw new RuntimeException('live_configuration_missing'); }
		$bootstrap = realpath($configuration['bootstrap'] ?? '');
		$marker = $bootstrap ? dirname($bootstrap) . '/.oras-ai-disposable-evaluation' : '';
		if (!$bootstrap || basename($bootstrap) !== 'wp-load.php' || !is_file($marker) || trim((string) file_get_contents($marker)) !== 'ORAS_AI_SYNTHETIC_DISPOSABLE_ONLY') { throw new RuntimeException('explicit_disposable_bootstrap_marker_required'); }
		define('DISABLE_WP_CRON', true);
		define('WP_HTTP_BLOCK_EXTERNAL', true);
		// WordPress loads with all external HTTP blocked; OpenAI opens only after validation.
		ob_start();
		try { require_once $bootstrap; } finally { ob_end_clean(); }
		if (!function_exists('wp_get_environment_type') || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { throw new RuntimeException('disposable_environment_required'); }
		if (!class_exists('ORAS_AI_Config')) { require_once $root . '/oras-ai-assistant.php'; }
		if (realpath((new ReflectionClass('ORAS_AI_Config'))->getFileName()) !== realpath($root . '/includes/class-oras-ai-config.php')) { throw new RuntimeException('evaluation_requires_current_checkout_runtime'); }
		define('WP_ACCESSIBLE_HOSTS', 'api.openai.com');
		require_once __DIR__ . '/evaluation/harness.php';
		require_once __DIR__ . '/evaluation/live.php';
		$report = ORAS_AI_Evaluation_Live::run($configuration, $checkpoint);
	}
	$report['harness_seconds'] = microtime(true) - $started;
	$checkpoint(array('type' => 'completed', 'failed_cases' => $report['failed_cases']));
	$output = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if (file_put_contents($directory . '/report.json', $output, LOCK_EX) !== strlen($output)) { throw new RuntimeException('evidence_write_failed'); }
	fwrite(STDOUT, ($live ? 'DISPOSABLE LIVE' : 'FIXTURE ONLY') . ': ' . count($report['results']) . ' cases; ' . $report['failed_cases'] . " rule failures; quality unscored; model not qualified.\nEvidence: " . $directory . "/report.json\n");
	exit($report['failed_cases'] ? 1 : 0);
} catch (Throwable $error) {
	// Never echo exception text, WP/provider payloads, bootstrap paths or credentials.
	$reason = in_array($error->getMessage(), array('live_configuration_missing', 'explicit_disposable_bootstrap_marker_required', 'disposable_environment_required', 'disposable_host_required', 'live_key_missing', 'qualified_saved_pricing_missing', 'saved_runtime_contract_not_candidate', 'explicit_synthetic_identity_required', 'paid_plan_not_admitted', 'candidate_api_unavailable_or_discovery_failed', 'evaluation_requires_current_checkout_runtime', 'invalid_live_bounds', 'invalid_live_selection', 'evidence_write_failed'), true) ? $error->getMessage() : 'evaluation_failed';
	$blocked = array('status' => 'BLOCKED', 'reason' => $reason, 'quality' => null, 'model_recommendation' => 'NOT_QUALIFIED', 'partial_evidence' => 'events.jsonl');
	file_put_contents($directory . '/report.json', json_encode($blocked, JSON_PRETTY_PRINT) . "\n");
	fwrite(STDERR, "LIVE MODEL EVALUATION: BLOCKED BY CONFIGURATION OR EXECUTION\nReason: " . $reason . "\nEvidence: " . $directory . "/report.json\n");
	exit(2);
} finally { fclose($journal); }
