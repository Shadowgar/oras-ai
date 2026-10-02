<?php
if (!defined('WP_CLI') || !WP_CLI || 'http://192.168.50.47:8889' !== untrailingslashit(get_option('home'))) {
    throw new RuntimeException('Disposable tests site only.');
}
$attempts = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$attempts) {
    ++$attempts;
    return new WP_Error('oras_ai_probe_network_blocked', 'All external HTTP blocked in disposable contract.');
}, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', static function () { return false; });
if (defined('ORAS_AI_OPENAI_API_KEY') || class_exists('ORAS_AI_Assistant', false)) {
    throw new RuntimeException('Probe requires unloaded plugin and no provider constant.');
}
define('ORAS_AI_OPENAI_API_KEY', 'synthetic-never-sent-m9-contract');
require '/tmp/oras-ai-m9-load/oras-ai-assistant.php';
function m9_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . $message . "\n";
}
$sentinel = new stdClass();
$snapshots = [];
foreach ([ORAS_AI_Cost_Config::OPTION, ORAS_AI_Usage_Ledger::OPTION, ORAS_AI_Usage_Ledger::LOCK_OPTION, ORAS_AI_Usage_Ledger::FAULT_OPTION] as $name) {
    $value = get_option($name, $sentinel);
    $snapshots[$name] = [$value !== $sentinel, $value];
}
try {
    m9_check('0.2.1' === ORAS_AI_VERSION, 'plugin 0.2.1 loads in native WordPress');
    $config = ORAS_AI_Cost_Config::defaults();
    m9_check(25 === $config['daily_quota'] && 150 === $config['monthly_quota'] && 5 === $config['burst_per_minute'] && 10000000 === $config['warning_microdollars'] && 20000000 === $config['hard_stop_microdollars'], 'frozen cost settings load');
    $model = ORAS_AI_Config::get_openai_model();
    $config['pricing'][$model] = [
        'input_microdollars_per_million_tokens' => 1000000,
        'output_microdollars_per_million_tokens' => 3000000,
        'unit' => 'per_million_tokens',
    ];
    update_option(ORAS_AI_Cost_Config::OPTION, $config, false);
    delete_option(ORAS_AI_Usage_Ledger::LOCK_OPTION);
    delete_option(ORAS_AI_Usage_Ledger::FAULT_OPTION);
    $now = time();
    update_option(ORAS_AI_Usage_Ledger::OPTION, [
        'next_id' => 2, 'burst' => [], 'rejections' => [],
        'reservations' => ['synthetic-budget-fixture' => [
            'id' => 'synthetic-budget-fixture', 'user_id' => 0, 'created_at' => $now,
            'model' => $model, 'status' => 'reconciled', 'source' => 'scanner_classification',
            'counts_question' => false, 'estimated_input_tokens' => 0,
            'maximum_output_tokens' => 0, 'reserved_cost_microdollars' => 20000000,
            'pricing' => $config['pricing'][$model], 'actual_input_tokens' => 0,
            'actual_output_tokens' => 0, 'actual_cost_microdollars' => 20000000,
            'resolved_at' => $now,
        ]],
    ], false);
    $ledger = new ORAS_AI_Usage_Ledger();
    m9_check($ledger->budget_state($config)['hard_stop'], 'native ledger sees site-wide hard stop');
    $admitted = (new ORAS_AI_Execution_Controls($ledger))->admit(new ORAS_AI_Authorized_Request(1, 'Explain a nebula.', ['public'], true), $model, 16000);
    m9_check(!$admitted->allowed() && 'site_hard_stop' === $admitted->reason(), 'administrator answer admission denied');
    $context = new ORAS_AI_Grounded_Context('Explain astronomy.', 'Explain a nebula.', new ORAS_AI_Evidence_Packet([]), ORAS_AI_Grounded_Context::GENERAL_ASTRONOMY);
    $provider = new ORAS_AI_OpenAI_Answer_Provider();
    m9_check(!$provider->answer($context, 800, 30)->successful(), 'direct answer adapter denied');
    m9_check(!$provider->summarize_support_question('I need help with the ORAS website.', 160, 10)->successful(), 'direct support summary adapter denied');
    m9_check(is_wp_error((new ORAS_AI_OpenAI_Domain_Classifier())->classify('Explain this unfamiliar concept.')), 'domain classifier denied');
    m9_check(is_wp_error(ORAS_AI_OpenAI::classify_source('Synthetic source', 'https://example.invalid/source', 'page', 'The observatory offers astronomy education.')), 'scanner classification and extraction denied');
    m9_check(0 === $attempts, 'zero HTTP seam calls at hard stop across every paid family');
    // Emulate another request committing while this request holds a primed options cache.
    $hard_state = get_option(ORAS_AI_Usage_Ledger::OPTION);
    update_option(ORAS_AI_Usage_Ledger::OPTION, ['next_id' => 1, 'reservations' => [], 'burst' => [], 'rejections' => []], false);
    get_option(ORAS_AI_Usage_Ledger::OPTION);
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($hard_state)], ['option_name' => ORAS_AI_Usage_Ledger::OPTION]);
    $admitted = (new ORAS_AI_Execution_Controls(new ORAS_AI_Usage_Ledger()))->admit(new ORAS_AI_Authorized_Request(1, 'Explain a nebula.', ['public'], true), $model, 16000);
    m9_check(!$admitted->allowed() && 'site_hard_stop' === $admitted->reason(), 'admission refreshes stale WordPress ledger cache under lock');

    // Insert a competing lock after the absence check but immediately before SQL acquisition.
    $raced = false;
    $race = static function ($sql) use (&$raced, $wpdb) {
        if (!$raced && false !== stripos($sql, 'INSERT') && false !== strpos($sql, ORAS_AI_Usage_Ledger::LOCK_OPTION)) {
            $raced = true;
            $wpdb->insert($wpdb->options, ['option_name' => ORAS_AI_Usage_Ledger::LOCK_OPTION, 'option_value' => maybe_serialize(['token' => 'competing-writer-fixture', 'acquired_at' => time()]), 'autoload' => 'no']);
        }
        return $sql;
    };
    add_filter('query', $race);
    try {
        $admitted = (new ORAS_AI_Execution_Controls(new ORAS_AI_Usage_Ledger()))->admit(new ORAS_AI_Authorized_Request(1, 'Explain a nebula.', ['public'], true), $model, 16000);
        m9_check($raced && !$admitted->allowed() && 'ledger_unavailable' === $admitted->reason(), 'atomic lock acquisition denies competing insert');
        wp_cache_delete(ORAS_AI_Usage_Ledger::LOCK_OPTION, 'options');
        m9_check('competing-writer-fixture' === (get_option(ORAS_AI_Usage_Ledger::LOCK_OPTION)['token'] ?? ''), 'competing lock is never overwritten or released');
    } finally {
        remove_filter('query', $race);
    }

} finally {
    foreach ($snapshots as $name => [$exists, $value]) {
        if ($exists) { update_option($name, $value, false); }
        else { delete_option($name); }
    }
    foreach ($snapshots as $name => [$exists, $value]) {
        $restored = get_option($name, $sentinel);
        if (($exists && $restored !== $value) || (!$exists && $restored !== $sentinel)) {
            throw new RuntimeException('Failed to restore disposable option.');
        }
    }
    echo "PASS disposable options restored\n";
}
