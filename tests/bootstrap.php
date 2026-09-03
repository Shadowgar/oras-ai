<?php
declare(strict_types=1);

define('ORAS_AI_TESTING', true);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/wordpress/');
}

if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}

final class ORAS_AI_Test_Json_Response extends RuntimeException {
	public bool $success;
	public $data;
	public ?int $status;

	public function __construct(bool $success, $data, ?int $status = null) {
		parent::__construct('WordPress JSON response intercepted.');
		$this->success = $success;
		$this->data = $data;
		$this->status = $status;
	}
}

final class ORAS_AI_Test_Nonce_Exception extends RuntimeException {}
final class ORAS_AI_Test_Die_Exception extends RuntimeException {}

final class ORAS_AI_Test_Redirect_Exception extends RuntimeException {
	public string $location;

	public function __construct(string $location) {
		parent::__construct('WordPress redirect intercepted.');
		$this->location = $location;
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct(string $code = '', string $message = '', $data = null) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['oras_ai_tests'] = array();
$GLOBALS['oras_ai_test_hooks'] = array();
$GLOBALS['oras_ai_test_activation_hooks'] = array();
$GLOBALS['oras_ai_test_deactivation_hooks'] = array();

function oras_ai_test_reset(): void {
	$GLOBALS['oras_ai_test_registered_post_types'] = array();
	$GLOBALS['oras_ai_test_registered_taxonomies'] = array();
	$GLOBALS['oras_ai_test_terms'] = array();
	$GLOBALS['oras_ai_test_next_term_id'] = 1;
	$GLOBALS['oras_ai_test_posts'] = array();
	$GLOBALS['oras_ai_test_next_post_id'] = 100;
	$GLOBALS['oras_ai_test_post_meta'] = array();
	$GLOBALS['oras_ai_test_post_terms'] = array();
	$GLOBALS['oras_ai_test_post_writes'] = array();
	$GLOBALS['oras_ai_test_meta_writes'] = array();
	$GLOBALS['oras_ai_test_term_writes'] = array();
	$GLOBALS['oras_ai_test_options'] = array();
	$GLOBALS['oras_ai_test_option_autoload'] = array();
	$GLOBALS['oras_ai_test_public_post_types'] = array(
		'post'              => 'post',
		'page'              => 'page',
		'attachment'        => 'attachment',
		'tribe_events'      => 'tribe_events',
		'product'           => 'product',
		'elementor_library' => 'elementor_library',
		'mailpoet_page'     => 'mailpoet_page',
		'gm_menu_block'     => 'gm_menu_block',
		'oras_speaker'      => 'oras_speaker',
		'oras_ai_knowledge' => 'oras_ai_knowledge',
		'oras_ai_source'    => 'oras_ai_source',
	);
	$GLOBALS['oras_ai_test_capabilities'] = array();
	$GLOBALS['oras_ai_test_default_capability'] = true;
	$GLOBALS['oras_ai_test_nonce_valid'] = true;
	$GLOBALS['oras_ai_test_nonce_verifications'] = array();
	$GLOBALS['oras_ai_test_ajax_nonce_checks'] = array();
	$GLOBALS['oras_ai_test_admin_nonce_checks'] = array();
	$GLOBALS['oras_ai_test_remote_responses'] = array();
	$GLOBALS['oras_ai_test_remote_calls'] = array();
	$GLOBALS['oras_ai_test_current_screen'] = null;
	$GLOBALS['oras_ai_test_enqueued_scripts'] = array();
	$GLOBALS['oras_ai_test_enqueued_styles'] = array();
	$GLOBALS['oras_ai_test_localized_scripts'] = array();
	$GLOBALS['oras_ai_test_redirects'] = array();
	$GLOBALS['oras_ai_test_scheduled_events'] = array();
	$GLOBALS['oras_ai_test_deleted_posts'] = array();
	$GLOBALS['oras_ai_test_current_user_id'] = 7;
	$GLOBALS['oras_ai_test_users'] = array(
		7 => (object) array('ID' => 7, 'display_name' => 'Test Administrator'),
	);
	$GLOBALS['oras_ai_test_now_mysql'] = '2026-08-27 12:34:56';
	$GLOBALS['oras_ai_test_now_date'] = '2026-08-27';
	$_POST = array();
	$_GET = array();
}

function oras_ai_test(string $name, callable $callback): void {
	$GLOBALS['oras_ai_tests'][$name] = $callback;
}

function oras_ai_assert_true($condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function oras_ai_assert_false($condition, string $message): void {
	oras_ai_assert_true(!$condition, $message);
}

function oras_ai_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function oras_ai_assert_contains(string $needle, string $haystack, string $message): void {
	if (false === strpos($haystack, $needle)) {
		throw new RuntimeException($message . " Missing {$needle}.");
	}
}

function oras_ai_assert_not_contains(string $needle, string $haystack, string $message): void {
	if (false !== strpos($haystack, $needle)) {
		throw new RuntimeException($message . " Unexpected {$needle}.");
	}
}

function oras_ai_assert_wp_error($value, string $code, string $message): void {
	oras_ai_assert_true(is_wp_error($value), $message . ' Expected WP_Error.');
	oras_ai_assert_same($code, $value->get_error_code(), $message . ' Error code mismatch.');
}

function oras_ai_hook_registered(string $hookName): bool {
	return !empty($GLOBALS['oras_ai_test_hooks'][$hookName]);
}

function oras_ai_invoke_private(object $object, string $method, array $arguments = array()) {
	$reflection = new ReflectionMethod($object, $method);
	return $reflection->invokeArgs($object, $arguments);
}

function oras_ai_test_add_post(array $post): int {
	return (int) wp_insert_post($post, true);
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['oras_ai_test_hooks'][(string) $hook_name][] = array(
		'type' => 'action',
		'callback' => $callback,
		'priority' => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['oras_ai_test_hooks'][(string) $hook_name][] = array(
		'type' => 'filter',
		'callback' => $callback,
		'priority' => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function register_activation_hook($file, $callback): void {
	$GLOBALS['oras_ai_test_activation_hooks'][] = array('file' => $file, 'callback' => $callback);
}

function register_deactivation_hook($file, $callback): void {
	$GLOBALS['oras_ai_test_deactivation_hooks'][] = array('file' => $file, 'callback' => $callback);
}

function plugin_dir_path($file): string {
	return rtrim(dirname((string) $file), '/\\') . DIRECTORY_SEPARATOR;
}

function plugin_dir_url($file): string {
	return 'https://example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/';
}

function __($text, $domain = null): string {
	return (string) $text;
}

function esc_html__($text, $domain = null): string {
	return (string) $text;
}

function esc_attr__($text, $domain = null): string {
	return (string) $text;
}

function esc_html_e($text, $domain = null): void {
	echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr_e($text, $domain = null): void {
	echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html($text): string {
	return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text): string {
	return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea($text): string {
	return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url): string {
	return (string) $url;
}

function esc_url_raw($url): string {
	return trim((string) $url);
}

function sanitize_text_field($value): string {
	return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value): string {
	return trim(strip_tags((string) $value));
}

function sanitize_key($value): string {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? '';
}

function wp_unslash($value) {
	if (is_array($value)) {
		return array_map('wp_unslash', $value);
	}
	return stripslashes((string) $value);
}

function wp_kses_post($value): string {
	return (string) $value;
}

function absint($value): int {
	return abs((int) $value);
}

function wp_parse_args($args, $defaults = array()): array {
	return array_merge($defaults, is_array($args) ? $args : array());
}

function register_post_type($post_type, $args) {
	$GLOBALS['oras_ai_test_registered_post_types'][(string) $post_type] = $args;
	return (object) array('name' => $post_type);
}

function register_taxonomy($taxonomy, $object_type, $args) {
	$GLOBALS['oras_ai_test_registered_taxonomies'][(string) $taxonomy] = array(
		'object_type' => $object_type,
		'args' => $args,
	);
	return (object) array('name' => $taxonomy);
}

function taxonomy_exists($taxonomy): bool {
	return isset($GLOBALS['oras_ai_test_registered_taxonomies'][(string) $taxonomy]);
}

function term_exists($term, $taxonomy = '') {
	$key = (string) $taxonomy . '|' . strtolower((string) $term);
	return $GLOBALS['oras_ai_test_terms'][$key] ?? 0;
}

function wp_insert_term($term, $taxonomy) {
	$key = (string) $taxonomy . '|' . strtolower((string) $term);
	if (isset($GLOBALS['oras_ai_test_terms'][$key])) {
		return array('term_id' => $GLOBALS['oras_ai_test_terms'][$key]);
	}
	$termId = $GLOBALS['oras_ai_test_next_term_id']++;
	$GLOBALS['oras_ai_test_terms'][$key] = $termId;
	return array('term_id' => $termId);
}

function wp_set_post_terms($post_id, $terms, $taxonomy, $append = false) {
	$GLOBALS['oras_ai_test_term_writes'][(int) $post_id] = ($GLOBALS['oras_ai_test_term_writes'][(int) $post_id] ?? 0) + 1;
	$GLOBALS['oras_ai_test_post_terms'][(int) $post_id][(string) $taxonomy] = array_map('intval', (array) $terms);
	return $GLOBALS['oras_ai_test_post_terms'][(int) $post_id][(string) $taxonomy];
}

function wp_get_post_terms($post_id, $taxonomy, $args = array()): array {
	return $GLOBALS['oras_ai_test_post_terms'][(int) $post_id][(string) $taxonomy] ?? array();
}

function get_the_terms($post_id, $taxonomy) {
	$ids = wp_get_post_terms($post_id, $taxonomy);
	$terms = array();
	foreach ($ids as $id) {
		$name = '';
		foreach ($GLOBALS['oras_ai_test_terms'] as $key => $termId) {
			if ($termId === $id) {
				$name = substr($key, strpos($key, '|') + 1);
				break;
			}
		}
		$terms[] = (object) array('term_id' => $id, 'name' => $name);
	}
	return $terms;
}

function wp_list_pluck($list, $field): array {
	return array_map(static function ($item) use ($field) {
		return is_object($item) ? $item->{$field} : $item[$field];
	}, $list);
}

function wp_insert_post($postarr, $wp_error = false) {
	$id = isset($postarr['ID']) ? (int) $postarr['ID'] : $GLOBALS['oras_ai_test_next_post_id']++;
	$GLOBALS['oras_ai_test_post_writes'][$id] = ($GLOBALS['oras_ai_test_post_writes'][$id] ?? 0) + 1;
	$existing = $GLOBALS['oras_ai_test_posts'][$id] ?? (object) array();
	$defaults = array(
		'ID' => $id,
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_title' => '',
		'post_content' => '',
		'post_excerpt' => '',
		'post_author' => 0,
		'post_parent' => 0,
		'post_date_gmt' => '2026-08-27 00:00:00',
		'post_modified_gmt' => '2026-08-27 00:00:00',
		'post_name' => 'post-' . $id,
	);
	$data = array_merge($defaults, get_object_vars($existing), $postarr, array('ID' => $id));
	$GLOBALS['oras_ai_test_posts'][$id] = (object) $data;
	return $id;
}

function wp_update_post($postarr, $wp_error = false) {
	if (empty($postarr['ID']) || !isset($GLOBALS['oras_ai_test_posts'][(int) $postarr['ID']])) {
		return $wp_error ? new WP_Error('missing_post', 'Post not found.') : 0;
	}
	return wp_insert_post($postarr, $wp_error);
}

function get_post($post_id) {
	return $GLOBALS['oras_ai_test_posts'][(int) $post_id] ?? null;
}

function get_post_type($post_id) {
	$post = get_post($post_id);
	return $post ? $post->post_type : false;
}

function wp_delete_post($post_id, $force_delete = false) {
	$post_id = (int) $post_id;
	$post = $GLOBALS['oras_ai_test_posts'][$post_id] ?? null;
	if (!$post) {
		return false;
	}
	$GLOBALS['oras_ai_test_deleted_posts'][] = $post_id;
	unset($GLOBALS['oras_ai_test_posts'][$post_id]);
	unset($GLOBALS['oras_ai_test_post_meta'][$post_id]);
	unset($GLOBALS['oras_ai_test_post_terms'][$post_id]);
	return $post;
}

function get_posts($args = array()): array {
	$args = wp_parse_args($args, array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5));
	$postTypes = (array) $args['post_type'];
	$postStatuses = (array) $args['post_status'];
	$posts = array_values($GLOBALS['oras_ai_test_posts']);
	$posts = array_values(array_filter($posts, static function ($post) use ($postTypes, $postStatuses, $args): bool {
		if (!in_array($post->post_type, $postTypes, true) || !in_array($post->post_status, $postStatuses, true)) {
			return false;
		}
		if (isset($args['post_parent']) && (int) $post->post_parent !== (int) $args['post_parent']) {
			return false;
		}
		if (isset($args['author']) && (int) $post->post_author !== (int) $args['author']) {
			return false;
		}
		if (!empty($args['meta_key'])) {
			$value = get_post_meta($post->ID, $args['meta_key'], true);
			if ((string) $value !== (string) ($args['meta_value'] ?? '')) {
				return false;
			}
		}
		return true;
	}));
	if (($args['orderby'] ?? '') === 'title') {
		usort($posts, static fn($a, $b): int => strcmp($a->post_title, $b->post_title));
	} else {
		usort($posts, static fn($a, $b): int => $a->ID <=> $b->ID);
	}
	if (($args['order'] ?? 'ASC') === 'DESC') {
		$posts = array_reverse($posts);
	}
	if ((int) $args['posts_per_page'] >= 0) {
		$posts = array_slice($posts, 0, (int) $args['posts_per_page']);
	}
	if (($args['fields'] ?? '') === 'ids') {
		return array_map(static fn($post): int => (int) $post->ID, $posts);
	}
	return $posts;
}

function get_post_types($args = array(), $output = 'names'): array {
	return $GLOBALS['oras_ai_test_public_post_types'];
}

function update_post_meta($post_id, $meta_key, $meta_value) {
	$GLOBALS['oras_ai_test_meta_writes'][(int) $post_id] = ($GLOBALS['oras_ai_test_meta_writes'][(int) $post_id] ?? 0) + 1;
	$GLOBALS['oras_ai_test_post_meta'][(int) $post_id][(string) $meta_key] = $meta_value;
	return true;
}

function get_post_meta($post_id, $meta_key = '', $single = false) {
	if ('' === $meta_key) {
		return $GLOBALS['oras_ai_test_post_meta'][(int) $post_id] ?? array();
	}
	return $GLOBALS['oras_ai_test_post_meta'][(int) $post_id][(string) $meta_key] ?? ($single ? '' : array());
}

function delete_post_meta($post_id, $meta_key): bool {
	unset($GLOBALS['oras_ai_test_post_meta'][(int) $post_id][(string) $meta_key]);
	return true;
}

function get_permalink($post): string {
	$post = is_object($post) ? $post : get_post($post);
	if (!$post) {
		return '';
	}
	return isset($post->permalink) ? (string) $post->permalink : 'https://example.test/' . $post->post_name . '/';
}

function get_option($name, $default = false) {
	return array_key_exists((string) $name, $GLOBALS['oras_ai_test_options'])
		? $GLOBALS['oras_ai_test_options'][(string) $name]
		: $default;
}

function update_option($name, $value, $autoload = null): bool {
	$GLOBALS['oras_ai_test_options'][(string) $name] = $value;
	if (null !== $autoload) {
		$GLOBALS['oras_ai_test_option_autoload'][(string) $name] = $autoload;
	}
	return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes'): bool {
	if (array_key_exists((string) $name, $GLOBALS['oras_ai_test_options'])) {
		return false;
	}

	$GLOBALS['oras_ai_test_options'][(string) $name] = $value;
	$GLOBALS['oras_ai_test_option_autoload'][(string) $name] = $autoload;
	return true;
}

function delete_option($name): bool {
	unset($GLOBALS['oras_ai_test_options'][(string) $name]);
	unset($GLOBALS['oras_ai_test_option_autoload'][(string) $name]);
	return true;
}

function wp_next_scheduled($hook, $args = array()) {
	foreach ($GLOBALS['oras_ai_test_scheduled_events'] as $event) {
		if ((string) $event['hook'] === (string) $hook && $event['args'] === $args) {
			return (int) $event['timestamp'];
		}
	}
	return false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
	$GLOBALS['oras_ai_test_scheduled_events'][] = array(
		'timestamp' => (int) $timestamp,
		'recurrence' => (string) $recurrence,
		'hook' => (string) $hook,
		'args' => $args,
	);
	return true;
}

function wp_clear_scheduled_hook($hook, $args = array(), $wp_error = false) {
	$before = count($GLOBALS['oras_ai_test_scheduled_events']);
	$GLOBALS['oras_ai_test_scheduled_events'] = array_values(
		array_filter(
			$GLOBALS['oras_ai_test_scheduled_events'],
			static function ($event) use ($hook, $args): bool {
				return (string) $event['hook'] !== (string) $hook || $event['args'] !== $args;
			}
		)
	);
	return $before - count($GLOBALS['oras_ai_test_scheduled_events']);
}

function is_wp_error($value): bool {
	return $value instanceof WP_Error;
}

function apply_filters($hook_name, $value) {
	return $value;
}

function strip_shortcodes($content): string {
	return preg_replace('/\[[^\]]+\]/', '', (string) $content) ?? '';
}

function wp_strip_all_tags($text, $remove_breaks = false): string {
	$text = strip_tags((string) $text);
	return $remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) ?? '' : $text;
}

function get_bloginfo($show = ''): string {
	return 'UTF-8';
}

function current_time($type, $gmt = 0): string {
	return 'Y-m-d' === $type ? $GLOBALS['oras_ai_test_now_date'] : $GLOBALS['oras_ai_test_now_mysql'];
}

function wp_parse_url($url, $component = -1) {
	return parse_url((string) $url, $component);
}

function wp_json_encode($value, $flags = 0, $depth = 512): string {
	return (string) json_encode($value, $flags, $depth);
}

function wp_remote_post($url, $args = array()) {
	$GLOBALS['oras_ai_test_remote_calls'][] = array('url' => $url, 'args' => $args);
	if (empty($GLOBALS['oras_ai_test_remote_responses'])) {
		return new WP_Error('oras_ai_test_unexpected_http', 'No mocked HTTP response was queued.');
	}
	return array_shift($GLOBALS['oras_ai_test_remote_responses']);
}

function wp_remote_retrieve_response_code($response): int {
	return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string {
	return (string) ($response['body'] ?? '');
}

function current_user_can($capability, ...$args): bool {
	return array_key_exists((string) $capability, $GLOBALS['oras_ai_test_capabilities'])
		? (bool) $GLOBALS['oras_ai_test_capabilities'][(string) $capability]
		: (bool) $GLOBALS['oras_ai_test_default_capability'];
}

function get_current_user_id(): int {
	return (int) $GLOBALS['oras_ai_test_current_user_id'];
}

function get_userdata($user_id) {
	return $GLOBALS['oras_ai_test_users'][(int) $user_id] ?? false;
}

function wp_verify_nonce($nonce, $action): bool {
	$GLOBALS['oras_ai_test_nonce_verifications'][] = array($nonce, $action);
	return (bool) $GLOBALS['oras_ai_test_nonce_valid'];
}

function check_ajax_referer($action, $query_arg = false, $stop = true) {
	$GLOBALS['oras_ai_test_ajax_nonce_checks'][] = array($action, $query_arg);
	if (!$GLOBALS['oras_ai_test_nonce_valid']) {
		throw new ORAS_AI_Test_Nonce_Exception('Invalid AJAX nonce.');
	}
	return 1;
}

function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
	$GLOBALS['oras_ai_test_admin_nonce_checks'][] = array($action, $query_arg);
	if (!$GLOBALS['oras_ai_test_nonce_valid']) {
		throw new ORAS_AI_Test_Nonce_Exception('Invalid admin nonce.');
	}
	return 1;
}

function wp_send_json_error($data = null, $status_code = null, $flags = 0): void {
	throw new ORAS_AI_Test_Json_Response(false, $data, null === $status_code ? null : (int) $status_code);
}

function wp_send_json_success($data = null, $status_code = null, $flags = 0): void {
	throw new ORAS_AI_Test_Json_Response(true, $data, null === $status_code ? null : (int) $status_code);
}

function wp_die($message = '', $title = '', $args = array()): void {
	throw new ORAS_AI_Test_Die_Exception((string) $message);
}

function get_current_screen() {
	return $GLOBALS['oras_ai_test_current_screen'];
}

function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false): void {
	$GLOBALS['oras_ai_test_enqueued_scripts'][(string) $handle] = compact('src', 'deps', 'ver', 'in_footer');
}

function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all'): void {
	$GLOBALS['oras_ai_test_enqueued_styles'][(string) $handle] = compact('src', 'deps', 'ver', 'media');
}

function wp_localize_script($handle, $object_name, $l10n): bool {
	$GLOBALS['oras_ai_test_localized_scripts'][(string) $handle] = array('object_name' => $object_name, 'data' => $l10n);
	return true;
}

function wp_create_nonce($action = -1): string {
	return 'nonce-for-' . $action;
}

function admin_url($path = '', $scheme = 'admin'): string {
	return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress'): bool {
	$GLOBALS['oras_ai_test_redirects'][] = $location;
	throw new ORAS_AI_Test_Redirect_Exception((string) $location);
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true): string {
	$field = '<input type="hidden" name="' . esc_attr($name) . '" value="nonce-for-' . esc_attr($action) . '">';
	if ($display) {
		echo $field;
	}
	return $field;
}

function selected($selected, $current = true, $display = true): string {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ($display) {
		echo $result;
	}
	return $result;
}

function checked($checked, $current = true, $display = true): string {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ($display) {
		echo $result;
	}
	return $result;
}

function disabled($disabled, $current = true, $display = true): string {
	$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
	if ($display) {
		echo $result;
	}
	return $result;
}

function submit_button($text = null, $type = 'primary large', $name = 'submit', $wrap = true, $other_attributes = null): void {
	echo '<input type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($text ?? 'Save Changes') . '">';
}

function wp_count_posts($type = 'post', $perm = '') {
	$counts = new stdClass();
	foreach ($GLOBALS['oras_ai_test_posts'] as $post) {
		if ($post->post_type === $type) {
			$counts->{$post->post_status} = ($counts->{$post->post_status} ?? 0) + 1;
		}
	}
	return $counts;
}

function is_admin(): bool {
	return true;
}

function flush_rewrite_rules($hard = true): void {}
function add_menu_page(...$args): string { return 'toplevel_page_oras-ai-assistant'; }
function add_submenu_page(...$args): string { return 'oras-ai_page'; }
function add_meta_box(...$args): void {}
function remove_meta_box(...$args): void {}
function wp_dropdown_categories($args = array()): void {}
function wp_editor($content, $editor_id, $settings = array()): void {}
function number_format_i18n($number, $decimals = 0): string { return number_format((float) $number, (int) $decimals); }
function get_edit_post_link($post_id, $context = 'display'): string { return admin_url('post.php?post=' . (int) $post_id); }

oras_ai_test_reset();

require_once dirname(__DIR__) . '/oras-ai-assistant.php';
