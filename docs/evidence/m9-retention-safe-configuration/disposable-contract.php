<?php
/** Run only with wp eval-file --skip-plugins --skip-themes in disposable tests CLI. */
if (!defined('WP_CLI') || !WP_CLI || 'http://192.168.50.47:8889' !== untrailingslashit(get_option('home'))) {
    throw new RuntimeException('Disposable tests site only.');
}
if (class_exists('ORAS_AI_Assistant', false)) { throw new RuntimeException('Plugin must be unloaded.'); }
$http = 0; $mail = 0; $GLOBALS['oras_ai_retention_probe_checks'] = 0;
add_filter('pre_http_request', static function () use (&$http) { ++$http; return new WP_Error('probe_blocked', 'Disposable HTTP blocked.'); }, PHP_INT_MAX);
add_filter('pre_wp_mail', static function () use (&$mail) { ++$mail; return false; }, PHP_INT_MAX);
function retention_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    ++$GLOBALS['oras_ai_retention_probe_checks']; echo 'PASS ' . $message . "\n";
}
function retention_run_cron() {
    // Advance only this fixture event to due; forcing a future event can unschedule
    // the same timestamp that WP-CLI just rescheduled. Preserve normal due behavior.
    wp_clear_scheduled_hook('oras_ai_prune_usage');
    wp_schedule_event(time() - 1, 'daily', 'oras_ai_prune_usage');
    $output = WP_CLI::runcommand('cron event run oras_ai_prune_usage', array('launch' => false, 'return' => 'all', 'exit_error' => false));
    if (0 !== $output->return_code || false === strpos($output->stdout, 'oras_ai_prune_usage')) { echo 'PROBE cron command result: ' . json_encode($output) . "\n"; }
    retention_check(0 === $output->return_code && false !== strpos($output->stdout, 'oras_ai_prune_usage'), 'native WP-CLI cron executed usage callback');
    retention_check(wp_next_scheduled('oras_ai_prune_usage') > time(), 'due cron execution retains next recurrence');
}
function retention_record($id, $status, $created, $resolved = 0) {
    return array('id' => $id, 'user_id' => 987654, 'member_question' => true, 'source' => 'answer',
        'created_at' => $created, 'dispatched_at' => $created + 1, 'model' => 'gpt-5.6-luna', 'status' => $status,
        'estimated_input_tokens' => 100, 'maximum_output_tokens' => 800, 'reserved_cost_microdollars' => 2500,
        'pricing' => array('input_microdollars_per_million_tokens' => 1000000, 'output_microdollars_per_million_tokens' => 3000000, 'unit' => 'per_million_tokens'),
        'actual_input_tokens' => null, 'actual_output_tokens' => null, 'actual_cost_microdollars' => 160,
        'conservative_cost_microdollars' => 2500, 'resolved_at' => $resolved);
}
global $wpdb;
$names = array('cron', 'active_plugins', 'oras_ai_version', 'rewrite_rules', 'oras_ai_member_ai_enabled',
    'oras_ai_usage_ledger', 'oras_ai_usage_ledger_lock', 'oras_ai_usage_ledger_fault', 'oras_ai_usage_maintenance');
$snapshots = array();
foreach ($names as $name) {
    $snapshots[$name] = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
}
// Configuration is compared in memory only; no credentials or private routing enter logs.
$config_before = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'oras_ai_%' ORDER BY option_name", OBJECT_K);
$plugin = 'oras-ai-m9-retention-probe/oras-ai-assistant.php';
try {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    update_option('oras_ai_member_ai_enabled', '0', false);
    $activation = activate_plugin($plugin, '', false, false);
    retention_check(!is_wp_error($activation) && is_plugin_active($plugin), 'native activation succeeds after explicit OFF preseed');
    retention_check('0' === get_option('oras_ai_member_ai_enabled') && !ORAS_AI_Config::member_ai_enabled(), 'preseeded OFF survives activation');
    retention_check('0.2.1' === ORAS_AI_VERSION, 'version remains 0.2.1');
    ORAS_AI_Usage_Maintenance::schedule_cleanup(); ORAS_AI_Usage_Maintenance::schedule_cleanup(); ORAS_AI_Assistant::activate();
    $event_count = 0;
    foreach (_get_cron_array() as $events) { $event_count += count($events['oras_ai_prune_usage'] ?? array()); }
    retention_check(1 === $event_count, 'repeated activation and scheduling retain exactly one usage event');
    retention_check('daily' === wp_get_schedule('oras_ai_prune_usage'), 'usage event recurs daily');
    $now = time();
    $records = array('old' => retention_record('old', 'released', 1), 'current' => retention_record('current', 'reconciled', $now, $now), 'unresolved' => retention_record('unresolved', 'dispatched', 1));
    update_option('oras_ai_usage_ledger', array('next_id' => 4, 'reservations' => $records, 'burst' => array(), 'rejections' => array()), false);
    delete_option('oras_ai_usage_ledger_lock'); delete_option('oras_ai_usage_ledger_fault');
    // First ledger action is the actual cron command: no admission or admin-page visit.
    retention_run_cron();
    $state = get_option('oras_ai_usage_ledger');
    retention_check(!isset($state['reservations']['old']), 'cron removes eligible old fixture without AI or admin access');
    retention_check($records['current'] === $state['reservations']['current'], 'cron retains current record unchanged');
    retention_check(isset($state['reservations']['unresolved']) && !isset($state['reservations']['unresolved']['user_id']) && !isset($state['reservations']['unresolved']['created_at']), 'expired unresolved exposure survives without member identity or exact request time');
    $ledger = new ORAS_AI_Usage_Ledger();
    $totals = $ledger->summary(987654);
    retention_check(2500 === $totals['site_month_reserved_microdollars'] && 160 === $totals['site_month_actual_microdollars'] && 1 === $totals['member_day_allowed'], 'cleanup preserves exposure current spend and current member quota');
    retention_check('success' === ORAS_AI_Usage_Maintenance::status()['outcome'] && ORAS_AI_Usage_Maintenance::status()['last_success'] > 0, 'maintenance records bounded successful outcome');
    retention_run_cron();
    retention_check($totals === $ledger->summary(987654), 'repeat native cron leaves accounting totals unchanged');
    $before = get_option('oras_ai_usage_ledger');
    update_option('oras_ai_usage_ledger_lock', array('token' => 'native-other-writer', 'acquired_at' => time()), false);
    retention_run_cron();
    retention_check($before === get_option('oras_ai_usage_ledger') && 'native-other-writer' === get_option('oras_ai_usage_ledger_lock')['token'], 'busy native cleanup leaves ledger and competing lock untouched');
    retention_check('busy' === ORAS_AI_Usage_Maintenance::status()['outcome'], 'lock contention exposes bounded busy outcome');
    delete_option('oras_ai_usage_ledger_lock');
    // Emulate a settled writer committing after this process cached the option.
    $settled = $before; $settled['reservations']['fresh-settlement'] = retention_record('fresh-settlement', 'reconciled', $now, $now);
    $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($settled)), array('option_name' => 'oras_ai_usage_ledger'));
    retention_run_cron();
    retention_check(isset(get_option('oras_ai_usage_ledger')['reservations']['fresh-settlement']) && 320 === $ledger->summary()['site_month_actual_microdollars'], 'cleanup refreshes native stale cache and preserves concurrent settlement');
    $ledger->flag_settlement_failure('unresolved', 100, 20);
    $fault = get_option('oras_ai_usage_ledger_fault'); retention_run_cron();
    retention_check($fault === get_option('oras_ai_usage_ledger_fault') && !$ledger->summary()['accounting_available'], 'cron preserves accounting fault and fail-closed state');
    $before = get_option('oras_ai_usage_ledger');
    wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'oras_ai_m9_probe_unrelated');
    deactivate_plugins($plugin, false);
    retention_check(false === wp_next_scheduled('oras_ai_prune_usage') && false !== wp_next_scheduled('oras_ai_m9_probe_unrelated'), 'native deactivation removes usage schedule and preserves unrelated event');
    retention_check($before === get_option('oras_ai_usage_ledger') && '0' === get_option('oras_ai_member_ai_enabled'), 'deactivation preserves ledger and saved OFF');
    $activation = activate_plugin($plugin, '', false, false);
    retention_check(!is_wp_error($activation) && false !== wp_next_scheduled('oras_ai_prune_usage') && '0' === get_option('oras_ai_member_ai_enabled'), 'native reactivation restores schedule without enabling member AI');
    $config_after = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'oras_ai_%' ORDER BY option_name", OBJECT_K);
    foreach ($names as $name) { unset($config_before[$name], $config_after[$name]); }
    retention_check($config_before == $config_after, 'existing provider account and other ORAS AI configuration unchanged');
    retention_check(0 === $http && 0 === $mail, 'zero HTTP and mail attempts throughout native qualification');
} finally {
    foreach ($snapshots as $name => $row) {
        if (null === $row) { $wpdb->delete($wpdb->options, array('option_name' => $name)); }
        else { $wpdb->replace($wpdb->options, array('option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'])); }
        wp_cache_delete($name, 'options');
    }
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
    foreach ($snapshots as $name => $row) {
        $restored = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
        if ($restored !== $row) { throw new RuntimeException('Disposable option restoration failed.'); }
    }
    retention_check(true, 'all changed disposable options and autoload values restored');
}
echo 'PASS native retention contract: ' . $GLOBALS['oras_ai_retention_probe_checks'] . " checks\n";
