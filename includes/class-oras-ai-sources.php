<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Sources {

	const POST_TYPE = 'oras_ai_source';

	public function __construct() {
		add_action( 'init', array( $this, 'register_source_type' ) );

		add_action( 'admin_post_oras_ai_save_settings', array( $this, 'save_settings' ) );

		add_action( 'wp_ajax_oras_ai_discover_sources', array( $this, 'ajax_discover_sources' ) );
		add_action( 'wp_ajax_oras_ai_process_source', array( $this, 'ajax_process_source' ) );
	}

	public function register_source_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'AI Sources', 'oras-ai-assistant' ),
					'singular_name' => __( 'AI Source', 'oras-ai-assistant' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'rewrite'            => false,
				'supports'           => array( 'title', 'editor' ),
			)
		);
	}

	public static function count_sources() {
		$counts = wp_count_posts( self::POST_TYPE );
		return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$has_key = ORAS_AI_OpenAI::has_api_key();
		$model   = ORAS_AI_OpenAI::get_model();
		$saved   = isset( $_GET['settings-updated'] );
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'ORAS AI Settings', 'oras-ai-assistant' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ORAS AI settings saved.', 'oras-ai-assistant' ); ?></p></div>
			<?php endif; ?>

			<div class="oras-ai-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oras_ai_save_settings">
					<?php wp_nonce_field( 'oras_ai_save_settings', 'oras_ai_settings_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="oras_ai_api_key"><?php esc_html_e( 'OpenAI API Key', 'oras-ai-assistant' ); ?></label></th>
							<td>
								<input
									type="password"
									class="regular-text"
									name="oras_ai_api_key"
									id="oras_ai_api_key"
									value=""
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $has_key ? 'Key saved — enter a new key only to replace it' : 'sk-...' ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'Stored on the WordPress server and never sent to visitors. You can alternatively define ORAS_AI_OPENAI_API_KEY in wp-config.php.', 'oras-ai-assistant' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oras_ai_model"><?php esc_html_e( 'Scanning Model', 'oras-ai-assistant' ); ?></label></th>
							<td>
								<select name="oras_ai_model" id="oras_ai_model">
									<option value="gpt-5.6-luna" <?php selected( $model, 'gpt-5.6-luna' ); ?>>GPT-5.6 Luna</option>
									<option value="gpt-5.6-terra" <?php selected( $model, 'gpt-5.6-terra' ); ?>>GPT-5.6 Terra</option>
									<option value="gpt-5.6-sol" <?php selected( $model, 'gpt-5.6-sol' ); ?>>GPT-5.6 Sol</option>
								</select>
								<p class="description"><?php esc_html_e( 'Luna is recommended for high-volume website classification because this task does not normally require the strongest model.', 'oras-ai-assistant' ); ?></p>
							</td>
						</tr>
						<?php if ( $has_key && ! defined( 'ORAS_AI_OPENAI_API_KEY' ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Remove Saved Key', 'oras-ai-assistant' ); ?></th>
							<td><label><input type="checkbox" name="oras_ai_remove_key" value="1"> <?php esc_html_e( 'Remove the API key stored by this plugin', 'oras-ai-assistant' ); ?></label></td>
						</tr>
						<?php endif; ?>
					</table>

					<?php submit_button( __( 'Save AI Settings', 'oras-ai-assistant' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ORAS AI settings.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( 'oras_ai_save_settings', 'oras_ai_settings_nonce' );

		$model = isset( $_POST['oras_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['oras_ai_model'] ) ) : 'gpt-5.6-luna';
		$allowed_models = array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol' );

		if ( ! in_array( $model, $allowed_models, true ) ) {
			$model = 'gpt-5.6-luna';
		}

		update_option( ORAS_AI_OpenAI::OPTION_MODEL, $model, false );

		if ( ! defined( 'ORAS_AI_OPENAI_API_KEY' ) ) {
			if ( ! empty( $_POST['oras_ai_remove_key'] ) ) {
				delete_option( ORAS_AI_OpenAI::OPTION_API_KEY );
			} elseif ( ! empty( $_POST['oras_ai_api_key'] ) ) {
				$key = trim( sanitize_text_field( wp_unslash( $_POST['oras_ai_api_key'] ) ) );
				update_option( ORAS_AI_OpenAI::OPTION_API_KEY, $key, false );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-settings&settings-updated=1' ) );
		exit;
	}

	public function render_sources_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sources = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$stats = array(
			'total'   => count( $sources ),
			'static'  => 0,
			'live'    => 0,
			'review'  => 0,
			'ignored' => 0,
			'pending' => 0,
			'error'   => 0,
		);

		foreach ( $sources as $source ) {
			$kind = get_post_meta( $source->ID, '_oras_ai_source_kind', true );
			$status = get_post_meta( $source->ID, '_oras_ai_scan_status', true );

			if ( 'static_knowledge' === $kind ) {
				$stats['static']++;
			} elseif ( 'live_data' === $kind ) {
				$stats['live']++;
			} elseif ( 'review' === $kind ) {
				$stats['review']++;
			} elseif ( 'ignore' === $kind ) {
				$stats['ignored']++;
			} elseif ( 'error' === $status ) {
				$stats['error']++;
			} else {
				$stats['pending']++;
			}
		}

		$has_key = ORAS_AI_OpenAI::has_api_key();
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Knowledge Sources', 'oras-ai-assistant' ); ?></h1>
			<p class="oras-ai-lead">
				<?php esc_html_e( 'The scanner reads published WordPress content. WordPress source-type rules are applied first; AI is used only when judgment is actually needed. Stable sources create or update managed Knowledge Base entries.', 'oras-ai-assistant' ); ?>
			</p>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'OpenAI API key required.', 'oras-ai-assistant' ); ?></strong>
						<?php esc_html_e( 'Configure the API key before running the AI scan.', 'oras-ai-assistant' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=oras-ai-settings' ) ); ?>"><?php esc_html_e( 'Open AI Settings', 'oras-ai-assistant' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="oras-ai-stats">
				<div><strong><?php echo esc_html( $stats['total'] ); ?></strong><span><?php esc_html_e( 'Sources', 'oras-ai-assistant' ); ?></span></div>
				<div><strong><?php echo esc_html( $stats['static'] ); ?></strong><span><?php esc_html_e( 'Knowledge', 'oras-ai-assistant' ); ?></span></div>
				<div><strong><?php echo esc_html( $stats['live'] ); ?></strong><span><?php esc_html_e( 'Live Data', 'oras-ai-assistant' ); ?></span></div>
				<div><strong><?php echo esc_html( $stats['review'] ); ?></strong><span><?php esc_html_e( 'Review', 'oras-ai-assistant' ); ?></span></div>
				<div><strong><?php echo esc_html( $stats['ignored'] ); ?></strong><span><?php esc_html_e( 'Ignored', 'oras-ai-assistant' ); ?></span></div>
				<div><strong><?php echo esc_html( $stats['pending'] ); ?></strong><span><?php esc_html_e( 'Pending', 'oras-ai-assistant' ); ?></span></div>
			</div>

			<div class="oras-ai-scan-actions">
				<button type="button" class="button button-primary button-hero oras-ai-scan-button" data-scan-mode="changed" <?php disabled( ! $has_key ); ?>>
					<?php esc_html_e( 'Scan Website & Build Knowledge Base', 'oras-ai-assistant' ); ?>
				</button>
				<button type="button" class="button button-secondary button-hero oras-ai-scan-button" data-scan-mode="rebuild" <?php disabled( ! $has_key ); ?>>
					<?php esc_html_e( 'Rebuild All Classifications', 'oras-ai-assistant' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Normal Scan processes only new or changed sources. Rebuild re-evaluates every source and automatically retires scanner-managed knowledge that should now be Live Data or Ignored.', 'oras-ai-assistant' ); ?>
				</p>
			</div>

			<div id="oras-ai-scan-progress" class="oras-ai-progress-wrap" hidden>
				<div class="oras-ai-progress"><span id="oras-ai-progress-bar"></span></div>
				<p id="oras-ai-progress-text"></p>
				<div id="oras-ai-scan-log" class="oras-ai-log"></div>
			</div>

			<h2><?php esc_html_e( 'Discovered Sources', 'oras-ai-assistant' ); ?></h2>

			<table class="widefat striped oras-ai-source-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'WP Type', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'Classification', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'Category', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'Confidence', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'Knowledge Entry', 'oras-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'Last Analyzed', 'oras-ai-assistant' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sources ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No sources have been discovered yet.', 'oras-ai-assistant' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sources as $source ) :
						$url        = get_post_meta( $source->ID, '_oras_ai_source_url', true );
						$wp_type    = get_post_meta( $source->ID, '_oras_ai_wp_post_type', true );
						$kind       = get_post_meta( $source->ID, '_oras_ai_source_kind', true );
						$category   = get_post_meta( $source->ID, '_oras_ai_source_category', true );
						$confidence    = get_post_meta( $source->ID, '_oras_ai_source_confidence', true );
						$classified_by = get_post_meta( $source->ID, '_oras_ai_classified_by', true );
						$reason        = get_post_meta( $source->ID, '_oras_ai_source_reason', true );
						$kb_id         = absint( get_post_meta( $source->ID, '_oras_ai_kb_entry_id', true ) );
						$analyzed      = get_post_meta( $source->ID, '_oras_ai_last_analyzed', true );
						$error         = get_post_meta( $source->ID, '_oras_ai_last_error', true );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $source->post_title ); ?></strong>
								<?php if ( $url ) : ?><br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open source', 'oras-ai-assistant' ); ?></a><?php endif; ?>
								<?php if ( $error ) : ?><div class="oras-ai-source-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
							</td>
							<td><?php echo esc_html( $wp_type ?: '—' ); ?></td>
							<td>
								<?php echo esc_html( $this->kind_label( $kind ) ); ?>
								<?php if ( $classified_by ) : ?><br><span class="oras-ai-source-method"><?php echo esc_html( 'via ' . ( 'rule' === $classified_by ? 'WordPress rule' : 'AI' ) ); ?></span><?php endif; ?>
								<?php if ( $reason ) : ?><br><span class="oras-ai-source-reason"><?php echo esc_html( $reason ); ?></span><?php endif; ?>
							</td>
							<td><?php echo esc_html( $category ?: '—' ); ?></td>
							<td><?php echo esc_html( $confidence ? ucfirst( $confidence ) : '—' ); ?></td>
							<td>
								<?php if ( $kb_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $kb_id ) ); ?>"><?php echo esc_html( 'KB-' . str_pad( (string) $kb_id, 5, '0', STR_PAD_LEFT ) ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $analyzed ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function kind_label( $kind ) {
		$labels = array(
			'static_knowledge' => __( 'Knowledge', 'oras-ai-assistant' ),
			'live_data'        => __( 'Live Data', 'oras-ai-assistant' ),
			'ignore'           => __( 'Ignored', 'oras-ai-assistant' ),
			'review'           => __( 'Needs Review', 'oras-ai-assistant' ),
		);

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'Pending', 'oras-ai-assistant' );
	}

	public function ajax_discover_sources() {
		$this->verify_ajax();

		$mode  = isset( $_POST['scan_mode'] ) ? sanitize_key( wp_unslash( $_POST['scan_mode'] ) ) : 'changed';
		$force = 'rebuild' === $mode;

		$result = $this->discover_wordpress_sources( $force );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['mode'] = $mode;
		wp_send_json_success( $result );
	}

	public function ajax_process_source() {
		$this->verify_ajax();

		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;

		if ( ! $source_id || self::POST_TYPE !== get_post_type( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid knowledge source.', 'oras-ai-assistant' ) ) );
		}

		$result = $this->process_source( $source_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'   => $result->get_error_message(),
					'source_id' => $source_id,
				)
			);
		}

		wp_send_json_success( $result );
	}

	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'oras-ai-assistant' ) ), 403 );
		}

		check_ajax_referer( 'oras_ai_scan', 'nonce' );
	}

	private function discover_wordpress_sources( $force = false ) {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		$posts = get_posts(
			array(
				'post_type'              => array_values( $post_types ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$queue      = array();
		$discovered = array();

		foreach ( $posts as $post ) {
			if ( ORAS_AI_Knowledge_Base::POST_TYPE === $post->post_type || self::POST_TYPE === $post->post_type ) {
				continue;
			}

			$content = $this->extract_post_content( $post );

			if ( '' === trim( $content ) ) {
				continue;
			}

			$url  = get_permalink( $post );
			$hash = hash( 'sha256', $post->post_title . '|' . $url . '|' . $content );

			$source_id = $this->find_source_by_wp_post_id( $post->ID );

			if ( ! $source_id ) {
				$source_id = wp_insert_post(
					array(
						'post_type'    => self::POST_TYPE,
						'post_status'  => 'publish',
						'post_title'   => $post->post_title,
						'post_content' => $content,
					),
					true
				);

				if ( is_wp_error( $source_id ) ) {
					continue;
				}

				update_post_meta( $source_id, '_oras_ai_scan_status', 'pending' );
			} else {
				wp_update_post(
					array(
						'ID'           => $source_id,
						'post_title'   => $post->post_title,
						'post_content' => $content,
					)
				);
			}

			$old_hash = get_post_meta( $source_id, '_oras_ai_source_hash', true );

			update_post_meta( $source_id, '_oras_ai_wp_post_id', $post->ID );
			update_post_meta( $source_id, '_oras_ai_wp_post_type', $post->post_type );
			update_post_meta( $source_id, '_oras_ai_source_url', esc_url_raw( $url ) );
			update_post_meta( $source_id, '_oras_ai_source_hash', $hash );
			update_post_meta( $source_id, '_oras_ai_wp_modified_gmt', $post->post_modified_gmt );

			$discovered[] = $source_id;

			$status = get_post_meta( $source_id, '_oras_ai_scan_status', true );

			if ( $force || $hash !== $old_hash || ! in_array( $status, array( 'complete', 'ignored', 'live', 'review' ), true ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'pending' );
				$queue[] = $source_id;
			}
		}

		$this->retire_missing_sources( $discovered );

		return array(
			'found' => count( $discovered ),
			'queue' => array_values( array_unique( $queue ) ),
		);
	}

	private function extract_post_content( $post ) {
		$content = (string) $post->post_content;

		/*
		 * Run the normal content filters so common page builders and shortcodes
		 * can expose their rendered text to the scanner.
		 */
		$content = apply_filters( 'the_content', $content );
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = preg_replace( '/\R{3,}/', "\n\n", $content );

		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );

		if ( $excerpt && false === strpos( $content, $excerpt ) ) {
			$content = $excerpt . "\n\n" . $content;
		}

		return trim( $content );
	}

	private function find_source_by_wp_post_id( $wp_post_id ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_oras_ai_wp_post_id',
				'meta_value'     => (int) $wp_post_id,
				'no_found_rows'  => true,
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	private function retire_missing_sources( $discovered_ids ) {
		$all_ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$missing = array_diff( $all_ids, $discovered_ids );

		foreach ( $missing as $source_id ) {
			update_post_meta( $source_id, '_oras_ai_scan_status', 'missing' );

			$kb_id = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );

			if ( $kb_id && ORAS_AI_Knowledge_Base::POST_TYPE === get_post_type( $kb_id ) ) {
				update_post_meta( $kb_id, '_oras_ai_status', 'retired' );
			}
		}
	}


	private function deterministic_classification( $source ) {
		$post_type = get_post_meta( $source->ID, '_oras_ai_wp_post_type', true );
		$url       = (string) get_post_meta( $source->ID, '_oras_ai_source_url', true );
		$title     = (string) $source->post_title;

		/*
		 * WordPress already tells us what these records are. Do not spend AI
		 * tokens deciding whether a WooCommerce product or Calendar event is
		 * changing data.
		 */
		if ( 'tribe_events' === $post_type ) {
			return array(
				'source_kind'     => 'live_data',
				'category'        => $this->deterministic_category( $title, $url, 'Events' ),
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'The Events Calendar event records are time-sensitive and must be queried live.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'product' === $post_type ) {
			return array(
				'source_kind'     => 'live_data',
				'category'        => $this->deterministic_category( $title, $url, 'Events' ),
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'WooCommerce product price, availability, ticket inventory, and purchase state can change.',
				'classified_by'   => 'rule',
			);
		}

		if ( in_array( $post_type, array( 'elementor_library', 'mailpoet_page', 'gm_menu_block' ), true ) ) {
			return array(
				'source_kind'     => 'ignore',
				'category'        => 'Website / Technical Help',
				'visibility'      => 'admin',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'This is a WordPress/plugin template or utility record, not an authoritative end-user knowledge source.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'oras_speaker' === $post_type ) {
			return array(
				'source_kind'     => 'static_knowledge',
				'category'        => 'Events',
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'ORAS speaker biography records are useful reference information and remain synchronized to WordPress changes.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'page' === $post_type && $this->is_utility_page( $url ) ) {
			return array(
				'source_kind'     => 'ignore',
				'category'        => $this->deterministic_category( $title, $url, 'Website / Technical Help' ),
				'visibility'      => 'admin',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'This is an account, checkout, form, confirmation, test, or other utility page rather than durable ORAS knowledge.',
				'classified_by'   => 'rule',
			);
		}

		/*
		 * tribe_event_series can contain durable explanatory information, and
		 * normal pages can be informational or dynamic. Let the model judge.
		 */
		return null;
	}

	private function deterministic_category( $title, $url, $fallback = 'General FAQ' ) {
		$haystack = strtolower( wp_strip_all_tags( $title . ' ' . $url ) );

		if ( false !== strpos( $haystack, 'astroblast' ) ) {
			return 'AstroBlast';
		}

		if ( false !== strpos( $haystack, 'public-night' ) || false !== strpos( $haystack, 'public night' ) ) {
			return 'Public Nights';
		}

		if ( false !== strpos( $haystack, 'observer-pass' ) || false !== strpos( $haystack, 'observer pass' ) ) {
			return 'Observer Passes';
		}

		if ( false !== strpos( $haystack, 'member' ) ) {
			return 'Membership';
		}

		if ( false !== strpos( $haystack, 'equipment' ) || false !== strpos( $haystack, 'telescope' ) ) {
			return 'Telescopes & Equipment';
		}

		if ( false !== strpos( $haystack, 'donat' ) || false !== strpos( $haystack, 'billing' ) || false !== strpos( $haystack, 'payment' ) ) {
			return 'Payments / Treasurer';
		}

		if ( false !== strpos( $haystack, 'observatory' ) ) {
			return 'Observatory Access';
		}

		return $fallback;
	}

	private function is_utility_page( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? strtolower( trim( $path, '/' ) ) : '';

		$exact = array(
			'cart',
			'login',
			'register-2',
			'my-account',
			'membership-account',
			'membership-account/membership-billing',
			'membership-account/membership-cancel',
			'membership-account/membership-orders',
			'membership-account/your-profile',
			'membership-checkout',
			'membership-checkout/membership-confirmation',
			'manage-group',
			'verify-waiting-email',
			'opt-out-preferences',
			'donation-list',
			'paidmemberships',
			'woo-hub',
			'wpsd-thank-you',
			'support',
			'support-portal',
			'website-feedback',
			'members-hub/equipment-exchange/listing',
			'members-hub/equipment-exchange/list-equipment',
			'members-hub/equipment-exchange/my-listings'
		);

		if ( in_array( $path, $exact, true ) ) {
			return true;
		}

		$contains = array(
			'/checkout',
			'/confirmation',
			'/thank-you',
		);

		$slash_path = '/' . $path;

		foreach ( $contains as $needle ) {
			if ( false !== strpos( $slash_path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function should_auto_approve( $post_type, $classification ) {
		if ( 'static_knowledge' !== $classification['source_kind'] ) {
			return false;
		}

		if ( 'high' !== $classification['confidence'] ) {
			return false;
		}

		/*
		 * Normal pages and ORAS speaker records are safe to synchronize
		 * automatically. Other model-judged source types require review.
		 */
		return in_array( $post_type, array( 'page', 'oras_speaker' ), true );
	}

	private function process_source( $source_id ) {
		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'oras_ai_source_missing', __( 'Source record not found.', 'oras-ai-assistant' ) );
		}

		$url       = get_post_meta( $source_id, '_oras_ai_source_url', true );
		$post_type = get_post_meta( $source_id, '_oras_ai_wp_post_type', true );
		$content   = trim( (string) $source->post_content );

		$result = $this->deterministic_classification( $source );

		if ( null === $result ) {
			$result = ORAS_AI_OpenAI::classify_source(
				$source->post_title,
				$url,
				$post_type,
				$content
			);

			if ( is_wp_error( $result ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $result->get_error_message() );
				return $result;
			}

			$result['classified_by'] = 'ai';
		}

		$kind          = sanitize_key( $result['source_kind'] );
		$category      = sanitize_text_field( $result['category'] );
		$visibility    = sanitize_key( $result['visibility'] );
		$confidence    = sanitize_key( $result['confidence'] );
		$title         = sanitize_text_field( $result['knowledge_title'] );
		$reason        = sanitize_textarea_field( $result['reason'] );
		$classified_by = isset( $result['classified_by'] ) ? sanitize_key( $result['classified_by'] ) : 'ai';

		update_post_meta( $source_id, '_oras_ai_source_kind', $kind );
		update_post_meta( $source_id, '_oras_ai_source_category', $category );
		update_post_meta( $source_id, '_oras_ai_source_visibility', $visibility );
		update_post_meta( $source_id, '_oras_ai_source_confidence', $confidence );
		update_post_meta( $source_id, '_oras_ai_source_reason', $reason );
		update_post_meta( $source_id, '_oras_ai_classified_by', $classified_by );
		update_post_meta( $source_id, '_oras_ai_last_analyzed', current_time( 'mysql' ) );
		delete_post_meta( $source_id, '_oras_ai_last_error' );

		$kb_id = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );

		if ( 'static_knowledge' === $kind ) {
			$knowledge_status = $this->should_auto_approve( $post_type, $result ) ? 'approved' : 'review';

			$kb_id = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
				array(
					'entry_id'       => $kb_id,
					'source_id'      => $source_id,
					'title'          => $title ?: $source->post_title,
					'content'        => $content,
					'category'       => $category,
					'visibility'     => $visibility,
					'status'         => $knowledge_status,
					'source_label'   => 'ORAS Website – ' . $source->post_title,
					'source_url'     => $url,
					'internal_notes' => 'Automatically managed by the ORAS AI website scanner. Classified by ' . ( 'rule' === $classified_by ? 'WordPress rule' : 'AI' ) . '. ' . $reason,
				)
			);

			if ( is_wp_error( $kb_id ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $kb_id->get_error_message() );
				return $kb_id;
			}

			update_post_meta( $source_id, '_oras_ai_kb_entry_id', $kb_id );
			update_post_meta( $source_id, '_oras_ai_scan_status', 'complete' );
		} elseif ( 'review' === $kind ) {
			$kb_id = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
				array(
					'entry_id'       => $kb_id,
					'source_id'      => $source_id,
					'title'          => $title ?: $source->post_title,
					'content'        => $content,
					'category'       => $category,
					'visibility'     => $visibility,
					'status'         => 'review',
					'source_label'   => 'ORAS Website – ' . $source->post_title,
					'source_url'     => $url,
					'internal_notes' => 'Scanner marked this source for review. Classified by ' . ( 'rule' === $classified_by ? 'WordPress rule' : 'AI' ) . '. ' . $reason,
				)
			);

			if ( ! is_wp_error( $kb_id ) ) {
				update_post_meta( $source_id, '_oras_ai_kb_entry_id', $kb_id );
			}

			update_post_meta( $source_id, '_oras_ai_scan_status', 'review' );
		} else {
			/*
			 * Rebuild cleanup: if this source previously created a scanner-managed
			 * Knowledge Base record but is now Live Data or Ignored, retire it.
			 * Manual KB entries are never touched.
			 */
			if ( $kb_id && ORAS_AI_Knowledge_Base::POST_TYPE === get_post_type( $kb_id ) ) {
				$managed = get_post_meta( $kb_id, '_oras_ai_managed_by_scan', true );

				if ( '1' === $managed ) {
					update_post_meta( $kb_id, '_oras_ai_status', 'retired' );
				}
			}

			update_post_meta( $source_id, '_oras_ai_scan_status', 'live_data' === $kind ? 'live' : 'ignored' );
		}

		return array(
			'source_id'     => $source_id,
			'title'         => $source->post_title,
			'kind'          => $kind,
			'category'      => $category,
			'confidence'    => $confidence,
			'classified_by' => $classified_by,
			'kb_id'         => $kb_id,
		);
	}
}
