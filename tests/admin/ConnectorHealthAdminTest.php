<?php
declare(strict_types=1);

function oras_ai_test_connector_health_admin(): array {
	$observer = new ORAS_AI_Connector_Observability();
	$eventLookups = array();
	$wooLookups = array();
	$pmproLookups = array();
	$connectors = array(
		oras_ai_test_event_adapter(array(oras_ai_test_event_record()), $eventLookups),
		oras_ai_test_woo_connector(array(oras_ai_test_woo_record()), $wooLookups),
		oras_ai_test_pmpro_connector(array(), $pmproLookups),
	);

	return array(new ORAS_AI_Connector_Health_Admin($observer, $connectors), $observer);
}

oras_ai_test('Connector Health is a manage-options read-only page with safe fixed connector state', function (): void {
	oras_ai_test_reset();
	list($admin, $observer) = oras_ai_test_connector_health_admin();
	$observer->record_outcome('events_calendar', ORAS_AI_Live_Result::UNKNOWN, 'event_lookup_failed');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = false;
	ob_start();
	$admin->render_page();
	$unauthorized = (string) ob_get_clean();
	oras_ai_assert_same('', $unauthorized, 'Non-admin rendered connector health internals.');

	$GLOBALS['oras_ai_test_capabilities']['manage_options'] = true;
	ob_start();
	$admin->render_page();
	$html = (string) ob_get_clean();
	foreach (array('Connector Health', 'The Events Calendar', 'WooCommerce', 'PMPro', 'Available', 'event_lookup_failed', '1') as $expected) {
		oras_ai_assert_contains($expected, $html, 'Connector Health omitted required safe state: ' . $expected);
	}
	foreach (array('<form', '<button', 'admin-post.php', 'raw payload', 'user@example.test', 'API key', 'stack trace') as $forbidden) {
		oras_ai_assert_not_contains($forbidden, $html, 'Connector Health exposed mutation or sensitive material.');
	}
});

oras_ai_test('Connector Health submenu stays inside ORAS AI and requires manage_options', function (): void {
	oras_ai_test_reset();
	$assistant = new ORAS_AI_Assistant();
	$assistant->register_admin_menu();
	$healthPage = null;
	foreach ($GLOBALS['oras_ai_test_submenu_pages'] as $page) {
		if (($page[4] ?? '') === 'oras-ai-connector-health') {
			$healthPage = $page;
			break;
		}
	}

	oras_ai_assert_true(is_array($healthPage), 'Connector Health submenu was not registered.');
	oras_ai_assert_same('oras-ai-assistant', $healthPage[0], 'Connector Health is outside the established ORAS AI menu.');
	oras_ai_assert_same('manage_options', $healthPage[3], 'Connector Health capability is not manage_options.');
	oras_ai_assert_false(oras_ai_hook_registered('wp_ajax_oras_ai_connector_health'), 'A frontend connector-health endpoint was introduced.');
	oras_ai_assert_false(oras_ai_hook_registered('admin_post_oras_ai_connector_health'), 'A mutating connector-health endpoint was introduced.');
});
