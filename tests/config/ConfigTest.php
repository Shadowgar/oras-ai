<?php
declare(strict_types=1);

oras_ai_test('configuration preserves exact OpenAI option names', function (): void {
	oras_ai_assert_same(
		'oras_ai_openai_api_key',
		ORAS_AI_Config::OPTION_OPENAI_API_KEY,
		'OpenAI API-key option name changed.'
	);
	oras_ai_assert_same(
		'oras_ai_openai_model',
		ORAS_AI_Config::OPTION_OPENAI_MODEL,
		'OpenAI model option name changed.'
	);
});

oras_ai_test('configuration returns the current default OpenAI model', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_same('gpt-5.6-luna', ORAS_AI_Config::get_openai_model(), 'Default OpenAI model changed.');
});

oras_ai_test('configuration accepts every current OpenAI model', function (): void {
	foreach (array('gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol') as $model) {
		oras_ai_test_reset();
		update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, $model);
		oras_ai_assert_same($model, ORAS_AI_Config::get_openai_model(), "Allowed model {$model} changed.");
	}
});

oras_ai_test('configuration resolves an invalid stored model to the v0.2.1 default', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_Config::OPTION_OPENAI_MODEL, 'gpt-unapproved');
	oras_ai_assert_same('gpt-5.6-luna', ORAS_AI_Config::get_openai_model(), 'Invalid model fallback changed.');
});

oras_ai_test('configuration retrieves the existing stored OpenAI API key', function (): void {
	oras_ai_test_reset();
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, '  stored-test-key  ');
	oras_ai_assert_same('stored-test-key', ORAS_AI_Config::get_openai_api_key(), 'Stored API-key retrieval changed.');
	oras_ai_assert_true(ORAS_AI_Config::has_openai_api_key(), 'Stored API key should be reported as configured.');
});

oras_ai_test('configuration preserves blank OpenAI API-key behavior', function (): void {
	oras_ai_test_reset();
	oras_ai_assert_same('', ORAS_AI_Config::get_openai_api_key(), 'Missing API key should resolve to an empty string.');
	oras_ai_assert_false(ORAS_AI_Config::has_openai_api_key(), 'Missing API key should not be reported as configured.');
	update_option(ORAS_AI_Config::OPTION_OPENAI_API_KEY, '   ');
	oras_ai_assert_same('', ORAS_AI_Config::get_openai_api_key(), 'Whitespace-only API key should remain blank.');
});

oras_ai_test('configuration settings helpers preserve stored option writes and removal', function (): void {
	oras_ai_test_reset();
	ORAS_AI_Config::update_openai_model('gpt-5.6-terra');
	ORAS_AI_Config::update_stored_openai_api_key('saved-key');
	oras_ai_assert_same('gpt-5.6-terra', get_option('oras_ai_openai_model'), 'Model helper should write the existing option.');
	oras_ai_assert_same('saved-key', get_option('oras_ai_openai_api_key'), 'API-key helper should write the existing option.');
	ORAS_AI_Config::delete_stored_openai_api_key();
	oras_ai_assert_same(false, get_option('oras_ai_openai_api_key'), 'API-key helper should remove the existing option.');
});
