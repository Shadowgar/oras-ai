<?php
declare(strict_types=1);

oras_ai_test('plugin load registers baseline classes and hooks', function (): void {
	$root = dirname(__DIR__, 2);

	require_once $root . '/oras-ai-assistant.php';

	oras_ai_assert_same('0.2.1', ORAS_AI_VERSION, 'Plugin version constant should remain at the v0.2.1 baseline.');
	oras_ai_assert_true(class_exists('ORAS_AI_Assistant'), 'Main plugin class should load.');
	oras_ai_assert_true(class_exists('ORAS_AI_Knowledge_Base'), 'Knowledge Base class should load.');
	oras_ai_assert_true(class_exists('ORAS_AI_OpenAI'), 'OpenAI class should load.');
	oras_ai_assert_true(class_exists('ORAS_AI_Sources'), 'Sources class should load.');

	oras_ai_assert_true(oras_ai_hook_registered('admin_menu'), 'Admin menu hook should be registered.');
	oras_ai_assert_true(oras_ai_hook_registered('admin_enqueue_scripts'), 'Admin asset hook should be registered.');
	oras_ai_assert_true(oras_ai_hook_registered('admin_init'), 'Admin upgrade hook should be registered.');
	oras_ai_assert_true(oras_ai_hook_registered('init'), 'Init hooks should be registered.');
	oras_ai_assert_true(
		oras_ai_hook_registered('wp_ajax_oras_ai_discover_sources'),
		'Source discovery AJAX hook should be registered.'
	);
	oras_ai_assert_true(
		oras_ai_hook_registered('wp_ajax_oras_ai_process_source'),
		'Source processing AJAX hook should be registered.'
	);

	oras_ai_assert_true(
		!empty($GLOBALS['oras_ai_test_activation_hooks']),
		'Plugin activation hook should be registered.'
	);
});
