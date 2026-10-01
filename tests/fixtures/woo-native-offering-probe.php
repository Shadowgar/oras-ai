<?php
declare(strict_types=1);

// Run in a separate PHP process so these external Woo doubles cannot affect missing-provider tests.
final class ORAS_AI_Native_Woo_Probe_Product {
	private array $fields;
	public function __construct(array $fields) { $this->fields = $fields; }
	public function get_id() { if ('id-throw' === ($GLOBALS['argv'][1] ?? '') && 2201 === $this->fields['id']) { throw new RuntimeException('private ID error'); } return $this->fields['id']; }
	public function get_name() { return $this->fields['name']; }
	public function get_slug() { return $this->fields['slug']; }
	public function get_type() { return $this->fields['type']; }
	public function get_status() { return 'publish'; }
	public function get_price() { if (in_array($GLOBALS['argv'][1] ?? '', array('throw', 'known-duplicate'), true) && 2201 === $this->fields['id']) { throw new RuntimeException('private vendor error'); } return '12.50'; }
	public function get_regular_price() { return '12.50'; }
	public function get_sale_price() { return ''; }
	public function is_on_sale() { return false; }
	public function get_stock_status() { return 'instock'; }
	public function is_in_stock() { return true; }
	public function is_purchasable() { return true; }
	public function get_permalink() { return 'https://oras.org/product/daily-observer-pass/'; }
	public function get_children() { if ('children-throw' === ($GLOBALS['argv'][1] ?? '')) { throw new RuntimeException('private child list error'); } return array(2101, 2102); }
}
function wc_get_products(array $query): array {
	$GLOBALS['probe_queries'][] = $query['title'];
	if ('Observer Pass' === $query['title'] && in_array($GLOBALS['argv'][1] ?? '', array('child', 'child-throw', 'children-throw'), true)) {
		return array(new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2000, 'name' => 'Observer Pass', 'slug' => 'observer-pass', 'type' => 'variable')));
	}
	if ('Annual Observer Pass' === $query['title']) {
		if (in_array($GLOBALS['argv'][1] ?? '', array('child', 'child-throw', 'children-throw'), true)) { return array(); }
		if ('known-duplicate' === ($GLOBALS['argv'][1] ?? '')) { return array(new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2200, 'name' => 'Annual Observer Pass', 'slug' => 'annual-observer-pass', 'type' => 'simple')), new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2201, 'name' => 'Annual Observer Pass', 'slug' => 'annual-observer-pass', 'type' => 'simple'))); }
		if (in_array($GLOBALS['argv'][1] ?? '', array('throw', 'id-throw'), true)) { return array(new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2201, 'name' => 'Annual Observer Pass', 'slug' => 'annual-observer-pass', 'type' => 'simple'))); }
		return 'methods' === ($GLOBALS['argv'][1] ?? '')
			? array(new class { public function get_id() { return 2201; } })
			: array(null);
	}
	return 'Daily Observer Pass' === $query['title'] && !in_array($GLOBALS['argv'][1] ?? '', array('child', 'child-throw'), true)
		? array(new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2102, 'name' => 'Daily Observer Pass', 'slug' => 'daily-observer-pass', 'type' => 'simple')))
		: array();
}
function wc_get_product($id) {
	if ('child-throw' === ($GLOBALS['argv'][1] ?? '') && 2101 === $id) { throw new RuntimeException('private variation error'); }
	return 2101 === $id ? null : new ORAS_AI_Native_Woo_Probe_Product(array('id' => 2102, 'name' => 'Daily', 'slug' => 'daily', 'type' => 'variation'));
}
function get_woocommerce_currency(): string { return 'USD'; }
require dirname(__DIR__) . '/bootstrap.php';
$request = ORAS_AI_Live_Request::from_authorized_request(new ORAS_AI_Authorized_Request(91, 'known-duplicate' === ($GLOBALS['argv'][1] ?? '') ? 'What Observer Passes are available?' : 'Is a Daily Observer Pass available?', array('public', 'members'), false), 'current');
$result = (new ORAS_AI_WooCommerce_Connector())->fetch($request);
echo json_encode(array('status' => $result->status(), 'reason' => $result->reason(), 'keys' => $result->fact_keys(), 'failures' => $result->failed_fact_keys(), 'queries' => $GLOBALS['probe_queries']));
