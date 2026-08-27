<?php
declare(strict_types=1);

define('ORAS_AI_TESTING', true);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/wordpress/');
}

$GLOBALS['oras_ai_tests'] = array();
$GLOBALS['oras_ai_test_hooks'] = array();
$GLOBALS['oras_ai_test_activation_hooks'] = array();

function oras_ai_test(string $name, callable $callback): void {
	$GLOBALS['oras_ai_tests'][$name] = $callback;
}

function oras_ai_assert_true($condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function oras_ai_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function oras_ai_hook_registered(string $hookName): bool {
	return !empty($GLOBALS['oras_ai_test_hooks'][$hookName]);
}

if (!function_exists('add_action')) {
	function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
		$GLOBALS['oras_ai_test_hooks'][(string) $hook_name][] = array(
			'type' => 'action',
			'callback' => $callback,
			'priority' => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if (!function_exists('add_filter')) {
	function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
		$GLOBALS['oras_ai_test_hooks'][(string) $hook_name][] = array(
			'type' => 'filter',
			'callback' => $callback,
			'priority' => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if (!function_exists('register_activation_hook')) {
	function register_activation_hook($file, $callback): void {
		$GLOBALS['oras_ai_test_activation_hooks'][] = array(
			'file' => $file,
			'callback' => $callback,
		);
	}
}

if (!function_exists('plugin_dir_path')) {
	function plugin_dir_path($file): string {
		return rtrim(dirname((string) $file), '/\\') . DIRECTORY_SEPARATOR;
	}
}

if (!function_exists('plugin_dir_url')) {
	function plugin_dir_url($file): string {
		return 'https://example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/';
	}
}
