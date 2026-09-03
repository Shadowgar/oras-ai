<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Sources {

	const POST_TYPE     = 'oras_ai_source';
	const META_EXCLUDED = '_oras_ai_excluded';
	private $source_classifier;
	private $classification_rules;

	public function __construct( ?ORAS_AI_Source_Classifier_Interface $source_classifier = null, ?ORAS_AI_Source_Classification_Rules $classification_rules = null ) {
		$this->source_classifier = $source_classifier ?: new ORAS_AI_OpenAI_Source_Classifier();
		$this->classification_rules = $classification_rules ?: new ORAS_AI_Source_Classification_Rules();

		add_action( 'init', array( $this, 'register_source_type' ) );

		add_action( 'admin_post_oras_ai_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_oras_ai_source_action', array( $this, 'handle_source_action' ) );
		add_action( 'admin_post_oras_ai_review_action', array( $this, 'handle_review_action' ) );

		add_action( 'wp_ajax_oras_ai_discover_sources', array( $this, 'ajax_discover_sources' ) );
		add_action( 'wp_ajax_oras_ai_process_source', array( $this, 'ajax_process_source' ) );
		add_action( 'wp_ajax_oras_ai_complete_scan', array( $this, 'ajax_complete_scan' ) );
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

	public static function is_source_excluded( $source_id ) {
		return '1' === get_post_meta( absint( $source_id ), self::META_EXCLUDED, true );
	}

	public function handle_source_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ORAS AI sources.', 'oras-ai-assistant' ) );
		}

		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		if ( ! $source_id || self::POST_TYPE !== get_post_type( $source_id ) ) {
			wp_die( esc_html__( 'Invalid knowledge source.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( 'oras_ai_source_action_' . $source_id, 'oras_ai_source_action_nonce' );

		$action = isset( $_POST['source_action'] ) ? sanitize_key( wp_unslash( $_POST['source_action'] ) ) : '';
		if ( ! in_array( $action, array( 'exclude', 'unexclude' ), true ) ) {
			wp_die( esc_html__( 'Invalid source action.', 'oras-ai-assistant' ) );
		}

		if ( 'exclude' === $action ) {
			update_post_meta( $source_id, self::META_EXCLUDED, '1' );
			update_post_meta( $source_id, '_oras_ai_scan_status', 'excluded' );
			$this->retire_managed_artifacts( $source_id );
		} else {
			delete_post_meta( $source_id, self::META_EXCLUDED );
			update_post_meta( $source_id, '_oras_ai_scan_status', 'pending' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-sources' ) );
		exit;
	}

	public function handle_review_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to review ORAS AI knowledge.', 'oras-ai-assistant' ) );
		}

		$source_id   = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$artifact_id = isset( $_POST['artifact_id'] ) ? absint( $_POST['artifact_id'] ) : 0;
		if (
			! $source_id || self::POST_TYPE !== get_post_type( $source_id ) ||
			! $artifact_id || ORAS_AI_Knowledge_Base::POST_TYPE !== get_post_type( $artifact_id )
		) {
			wp_die( esc_html__( 'Invalid review item.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( 'oras_ai_review_artifact_' . $artifact_id, 'oras_ai_review_action_nonce' );

		$action = isset( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : '';
		if ( ! in_array( $action, array( 'approve', 'retire' ), true ) ) {
			wp_die( esc_html__( 'Invalid review action.', 'oras-ai-assistant' ) );
		}

		if ( ! $this->is_managed_artifact_for_source( $artifact_id, $source_id ) ) {
			wp_die( esc_html__( 'Only linked scanner-managed knowledge can use this review action.', 'oras-ai-assistant' ) );
		}

		if ( 'review' !== ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id ) ) {
			wp_die( esc_html__( 'This knowledge artifact is no longer awaiting review.', 'oras-ai-assistant' ) );
		}

		update_post_meta( $artifact_id, '_oras_ai_status', 'approve' === $action ? 'approved' : 'retired' );
		if ( 'approve' === $action ) {
			update_post_meta( $artifact_id, '_oras_ai_last_reviewed', current_time( 'Y-m-d' ) );
		}

		$this->refresh_source_review_status( $source_id );

		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-review' ) );
		exit;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$has_key = ORAS_AI_Config::has_openai_api_key();
		$model   = ORAS_AI_Config::get_openai_model();
		$member_ai_enabled = ORAS_AI_Config::member_ai_enabled();
		$audit_events = ORAS_AI_Audit_Log::recent_events();
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
							<th scope="row"><?php esc_html_e( 'Member AI Assistant', 'oras-ai-assistant' ); ?></th>
							<td>
								<label for="oras_ai_member_ai_enabled">
									<input
										type="checkbox"
										name="oras_ai_member_ai_enabled"
										id="oras_ai_member_ai_enabled"
										value="1"
										<?php checked( $member_ai_enabled ); ?>
									>
									<?php esc_html_e( 'Enable member AI assistant', 'oras-ai-assistant' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When disabled, member-facing AI requests are blocked. Administrative source scanning and knowledge management remain available.', 'oras-ai-assistant' ); ?></p>
							</td>
						</tr>
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
						<?php if ( $has_key && ! ORAS_AI_Config::is_openai_api_key_constant_defined() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Remove Saved Key', 'oras-ai-assistant' ); ?></th>
							<td><label><input type="checkbox" name="oras_ai_remove_key" value="1"> <?php esc_html_e( 'Remove the API key stored by this plugin', 'oras-ai-assistant' ); ?></label></td>
						</tr>
						<?php endif; ?>
					</table>

					<?php submit_button( __( 'Save AI Settings', 'oras-ai-assistant' ) ); ?>
				</form>
			</div>

			<div class="oras-ai-panel">
				<h2><?php esc_html_e( 'Recent Configuration Changes', 'oras-ai-assistant' ); ?></h2>
				<?php if ( empty( $audit_events ) ) : ?>
					<p><?php esc_html_e( 'No configuration changes have been recorded yet.', 'oras-ai-assistant' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date and time', 'oras-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'User', 'oras-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'Configuration', 'oras-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'Action', 'oras-ai-assistant' ); ?></th>
								<th><?php esc_html_e( 'Change', 'oras-ai-assistant' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $audit_events as $event ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $event['timestamp'] ) ? $event['timestamp'] : '' ); ?></td>
									<td><?php echo esc_html( $this->audit_actor_label( isset( $event['actor_user_id'] ) ? $event['actor_user_id'] : 0 ) ); ?></td>
									<td><?php echo esc_html( $this->audit_config_label( isset( $event['config_item'] ) ? $event['config_item'] : '' ) ); ?></td>
									<td><?php echo esc_html( $this->audit_action_label( isset( $event['action'] ) ? $event['action'] : '' ) ); ?></td>
									<td><?php echo esc_html( $this->audit_change_label( $event ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ORAS AI settings.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( 'oras_ai_save_settings', 'oras_ai_settings_nonce' );

		$old_member_ai_enabled = ORAS_AI_Config::member_ai_enabled();
		$member_ai_enabled = isset( $_POST['oras_ai_member_ai_enabled'] )
			? sanitize_key( wp_unslash( $_POST['oras_ai_member_ai_enabled'] ) )
			: '';
		ORAS_AI_Config::set_member_ai_enabled( '1' === $member_ai_enabled );
		$new_member_ai_enabled = ORAS_AI_Config::member_ai_enabled();

		if ( $old_member_ai_enabled !== $new_member_ai_enabled ) {
			ORAS_AI_Audit_Log::log_member_ai_changed( $old_member_ai_enabled, $new_member_ai_enabled );
		}

		$old_model = ORAS_AI_Config::get_openai_model();
		$model = isset( $_POST['oras_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['oras_ai_model'] ) ) : ORAS_AI_Config::DEFAULT_OPENAI_MODEL;
		ORAS_AI_Config::update_openai_model( $model );
		$new_model = ORAS_AI_Config::get_openai_model();

		if ( $old_model !== $new_model ) {
			ORAS_AI_Audit_Log::log_openai_model_changed( $old_model, $new_model );
		}

		if ( ! ORAS_AI_Config::is_openai_api_key_constant_defined() ) {
			$had_stored_key = ORAS_AI_Config::has_stored_openai_api_key();

			if ( ! empty( $_POST['oras_ai_remove_key'] ) ) {
				ORAS_AI_Config::delete_stored_openai_api_key();

				if ( $had_stored_key && ! ORAS_AI_Config::has_stored_openai_api_key() ) {
					ORAS_AI_Audit_Log::log_openai_api_key_changed( 'removed' );
				}
			} elseif ( ! empty( $_POST['oras_ai_api_key'] ) ) {
				$key = trim( sanitize_text_field( wp_unslash( $_POST['oras_ai_api_key'] ) ) );

				if ( '' !== $key ) {
					$key_was_unchanged = ORAS_AI_Config::stored_openai_api_key_matches( $key );
					ORAS_AI_Config::update_stored_openai_api_key( $key );

					if ( ! $key_was_unchanged && ORAS_AI_Config::stored_openai_api_key_matches( $key ) ) {
						ORAS_AI_Audit_Log::log_openai_api_key_changed( $had_stored_key ? 'replaced' : 'set' );
					}
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-settings&settings-updated=1' ) );
		exit;
	}

	private function audit_actor_label( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( $user && isset( $user->display_name ) && '' !== $user->display_name ) {
			return sprintf( '%s (#%d)', $user->display_name, $user_id );
		}

		return sprintf( 'User #%d', $user_id );
	}

	private function audit_config_label( $config_item ) {
		$labels = array(
			ORAS_AI_Audit_Log::CONFIG_OPENAI_MODEL   => __( 'OpenAI model', 'oras-ai-assistant' ),
			ORAS_AI_Audit_Log::CONFIG_MEMBER_AI      => __( 'Member AI Assistant', 'oras-ai-assistant' ),
			ORAS_AI_Audit_Log::CONFIG_OPENAI_API_KEY => __( 'Stored OpenAI API key', 'oras-ai-assistant' ),
		);

		return isset( $labels[ $config_item ] ) ? $labels[ $config_item ] : (string) $config_item;
	}

	private function audit_action_label( $action ) {
		$labels = array(
			'changed'  => __( 'Changed', 'oras-ai-assistant' ),
			'enabled'  => __( 'Enabled', 'oras-ai-assistant' ),
			'disabled' => __( 'Disabled', 'oras-ai-assistant' ),
			'set'      => __( 'Set', 'oras-ai-assistant' ),
			'replaced' => __( 'Replaced', 'oras-ai-assistant' ),
			'removed'  => __( 'Removed', 'oras-ai-assistant' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : (string) $action;
	}

	private function audit_change_label( $event ) {
		$old_state = array_key_exists( 'old_state', $event ) ? $event['old_state'] : null;
		$new_state = array_key_exists( 'new_state', $event ) ? $event['new_state'] : null;

		if ( null === $old_state || null === $new_state ) {
			return '—';
		}

		return sprintf( '%s → %s', $old_state, $new_state );
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
			'total'    => count( $sources ),
			'static'   => 0,
			'live'     => 0,
			'review'   => 0,
			'ignored'  => 0,
			'excluded' => 0,
			'pending'  => 0,
			'error'    => 0,
		);

		foreach ( $sources as $source ) {
			$kind = get_post_meta( $source->ID, '_oras_ai_source_kind', true );
			$status = get_post_meta( $source->ID, '_oras_ai_scan_status', true );

			if ( self::is_source_excluded( $source->ID ) ) {
				$stats['excluded']++;
			} elseif ( 'error' === $status ) {
				$stats['error']++;
			} elseif ( 'static_knowledge' === $kind ) {
				$stats['static']++;
			} elseif ( 'live_data' === $kind ) {
				$stats['live']++;
			} elseif ( 'review' === $kind ) {
				$stats['review']++;
			} elseif ( 'ignore' === $kind ) {
				$stats['ignored']++;
			} else {
				$stats['pending']++;
			}
		}

		$has_key = ORAS_AI_Config::has_openai_api_key();
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
				<div><strong><?php echo esc_html( $stats['excluded'] ); ?></strong><span><?php esc_html_e( 'Excluded', 'oras-ai-assistant' ); ?></span></div>
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
						<th><?php esc_html_e( 'Actions', 'oras-ai-assistant' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sources ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No sources have been discovered yet.', 'oras-ai-assistant' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sources as $source ) :
						$url        = get_post_meta( $source->ID, '_oras_ai_source_url', true );
						$wp_type    = get_post_meta( $source->ID, '_oras_ai_wp_post_type', true );
						$kind       = get_post_meta( $source->ID, '_oras_ai_source_kind', true );
						$category   = get_post_meta( $source->ID, '_oras_ai_source_category', true );
						$confidence    = get_post_meta( $source->ID, '_oras_ai_source_confidence', true );
						$classified_by = get_post_meta( $source->ID, '_oras_ai_classified_by', true );
						$reason        = get_post_meta( $source->ID, '_oras_ai_source_reason', true );
						$artifact_ids  = $this->linked_artifact_ids( $source->ID );
						$analyzed      = get_post_meta( $source->ID, '_oras_ai_last_analyzed', true );
						$error         = get_post_meta( $source->ID, '_oras_ai_last_error', true );
						$excluded      = self::is_source_excluded( $source->ID );
						$problem_count = absint( get_post_meta( $source->ID, '_oras_ai_problem_count', true ) );
						$problem_kind  = get_post_meta( $source->ID, '_oras_ai_last_problem_kind', true );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $source->post_title ); ?></strong>
								<?php if ( $excluded ) : ?><br><strong><?php esc_html_e( 'Excluded by administrator', 'oras-ai-assistant' ); ?></strong><?php endif; ?>
								<?php if ( $url ) : ?><br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open source', 'oras-ai-assistant' ); ?></a><?php endif; ?>
								<?php if ( $error ) : ?><div class="oras-ai-source-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
								<?php if ( $problem_count ) : ?><div class="oras-ai-source-reason"><?php echo esc_html( $this->problem_summary( $problem_count, $problem_kind ) ); ?></div><?php endif; ?>
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
								<?php if ( ! empty( $artifact_ids ) ) : ?>
									<?php foreach ( $artifact_ids as $artifact_id ) : ?>
										<?php if ( ORAS_AI_Knowledge_Base::POST_TYPE === get_post_type( $artifact_id ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( $artifact_id ) ); ?>"><?php echo esc_html( 'KB-' . str_pad( (string) $artifact_id, 5, '0', STR_PAD_LEFT ) ); ?></a><br>
											<span class="oras-ai-source-reason"><?php echo esc_html( $this->artifact_admin_label( $artifact_id ) ); ?></span><br>
										<?php else : ?>
											<span><?php echo esc_html( 'KB-' . str_pad( (string) $artifact_id, 5, '0', STR_PAD_LEFT ) . ' — Missing record' ); ?></span><br>
										<?php endif; ?>
									<?php endforeach; ?>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $analyzed ?: '—' ); ?></td>
							<td><?php $this->render_source_action_form( $source->ID, $excluded ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_review_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$all_sources = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$sources = array();
		foreach ( $all_sources as $source ) {
			if ( $this->source_needs_review_attention( $source->ID ) ) {
				$sources[] = $source;
			}
		}
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Needs Review', 'oras-ai-assistant' ); ?></h1>
			<p class="oras-ai-lead"><?php esc_html_e( 'Review uncertain scanner results with their source, provenance, ownership, and repeated-problem context before approving or retiring scanner-managed knowledge.', 'oras-ai-assistant' ); ?></p>

			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'No sources currently need review.', 'oras-ai-assistant' ); ?></p>
			<?php else : ?>
				<table class="widefat striped oras-ai-source-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'oras-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'Classification', 'oras-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'Provenance / freshness', 'oras-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'Knowledge / disposition', 'oras-ai-assistant' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sources as $source ) :
						$source_id      = (int) $source->ID;
						$url            = get_post_meta( $source_id, '_oras_ai_source_url', true );
						$kind           = get_post_meta( $source_id, '_oras_ai_source_kind', true );
						$category       = get_post_meta( $source_id, '_oras_ai_source_category', true );
						$confidence     = get_post_meta( $source_id, '_oras_ai_source_confidence', true );
						$reason         = get_post_meta( $source_id, '_oras_ai_source_reason', true );
						$status         = get_post_meta( $source_id, '_oras_ai_scan_status', true );
						$source_hash    = get_post_meta( $source_id, '_oras_ai_source_hash', true );
						$modified       = get_post_meta( $source_id, '_oras_ai_wp_modified_gmt', true );
						$analyzed       = get_post_meta( $source_id, '_oras_ai_last_analyzed', true );
						$problem_count  = absint( get_post_meta( $source_id, '_oras_ai_problem_count', true ) );
						$problem_kind   = get_post_meta( $source_id, '_oras_ai_last_problem_kind', true );
						$problem_detail = get_post_meta( $source_id, '_oras_ai_last_problem', true );
						$problem_at     = get_post_meta( $source_id, '_oras_ai_last_problem_at', true );
						$artifact_ids   = $this->linked_artifact_ids( $source_id );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $source->post_title ); ?></strong><br>
								<?php echo esc_html( $this->source_status_label( $status ) ); ?>
								<?php if ( $url ) : ?><br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a><?php endif; ?>
								<?php if ( $problem_count ) : ?><br><strong><?php echo esc_html( $this->problem_summary( $problem_count, $problem_kind ) ); ?></strong><?php endif; ?>
								<?php if ( $problem_detail ) : ?><br><?php echo esc_html( $problem_detail ); ?><?php endif; ?>
								<?php if ( $problem_at ) : ?><br><span class="description"><?php echo esc_html( $problem_at ); ?></span><?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $this->kind_label( $kind ) ); ?><br>
								<?php echo esc_html( $category ?: '—' ); ?> · <?php echo esc_html( $confidence ? ucfirst( $confidence ) : '—' ); ?>
								<?php if ( $reason ) : ?><br><?php echo esc_html( $reason ); ?><?php endif; ?>
							</td>
							<td>
								<?php esc_html_e( 'Hash:', 'oras-ai-assistant' ); ?> <?php echo esc_html( $source_hash ?: '—' ); ?><br>
								<?php esc_html_e( 'Source modified:', 'oras-ai-assistant' ); ?> <?php echo esc_html( $modified ?: '—' ); ?><br>
								<?php esc_html_e( 'Last analyzed:', 'oras-ai-assistant' ); ?> <?php echo esc_html( $analyzed ?: '—' ); ?>
							</td>
							<td>
							<?php if ( empty( $artifact_ids ) ) : ?>
								—
							<?php else : ?>
								<?php foreach ( $artifact_ids as $artifact_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $artifact_id ) ); ?>"><?php echo esc_html( 'KB-' . str_pad( (string) $artifact_id, 5, '0', STR_PAD_LEFT ) ); ?></a><br>
									<?php echo esc_html( $this->artifact_admin_label( $artifact_id ) ); ?>
									<?php if ( ORAS_AI_Knowledge_Base::is_scanner_managed( $artifact_id ) && 'review' === ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id ) ) : ?>
										<?php $this->render_review_action_form( $source_id, $artifact_id, 'approve', __( 'Approve', 'oras-ai-assistant' ) ); ?>
										<?php $this->render_review_action_form( $source_id, $artifact_id, 'retire', __( 'Retire', 'oras-ai-assistant' ) ); ?>
									<?php endif; ?>
									<br>
								<?php endforeach; ?>
							<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_source_action_form( $source_id, $excluded ) {
		$action = $excluded ? 'unexclude' : 'exclude';
		$label  = $excluded ? __( 'Include source', 'oras-ai-assistant' ) : __( 'Exclude source', 'oras-ai-assistant' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oras_ai_source_action">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( $source_id ); ?>">
			<input type="hidden" name="source_action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'oras_ai_source_action_' . $source_id, 'oras_ai_source_action_nonce' ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_review_action_form( $source_id, $artifact_id, $action, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="oras_ai_review_action">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( $source_id ); ?>">
			<input type="hidden" name="artifact_id" value="<?php echo esc_attr( $artifact_id ); ?>">
			<input type="hidden" name="review_action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'oras_ai_review_artifact_' . $artifact_id, 'oras_ai_review_action_nonce' ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function source_needs_review_attention( $source_id ) {
		$status = get_post_meta( $source_id, '_oras_ai_scan_status', true );
		if ( in_array( $status, array( 'review', 'error', 'missing' ), true ) ) {
			return true;
		}

		foreach ( $this->linked_artifact_ids( $source_id ) as $artifact_id ) {
			if ( 'review' === ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id ) ) {
				return true;
			}
		}

		return false;
	}

	private function source_status_label( $status ) {
		$labels = array(
			'review'  => __( 'Needs Review', 'oras-ai-assistant' ),
			'error'   => __( 'Processing Error', 'oras-ai-assistant' ),
			'missing' => __( 'Missing Source', 'oras-ai-assistant' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	private function problem_summary( $count, $kind ) {
		$format = 1 === (int) $count
			? __( '%1$d %2$s occurrence', 'oras-ai-assistant' )
			: __( '%1$d %2$s occurrences', 'oras-ai-assistant' );

		return sprintf(
			$format,
			$count,
			'error' === $kind ? __( 'error', 'oras-ai-assistant' ) : __( 'review', 'oras-ai-assistant' )
		);
	}

	private function kind_label( $kind ) {
		$labels = array(
			'static_knowledge' => __( 'Knowledge', 'oras-ai-assistant' ),
			'live_data'        => __( 'Live Data', 'oras-ai-assistant' ),
			'mixed'            => __( 'Mixed — Needs Review', 'oras-ai-assistant' ),
			'ignore'           => __( 'Ignored', 'oras-ai-assistant' ),
			'review'           => __( 'Needs Review', 'oras-ai-assistant' ),
		);

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'Pending', 'oras-ai-assistant' );
	}

	public function ajax_discover_sources() {
		$this->verify_ajax();

		$mode  = isset( $_POST['scan_mode'] ) ? sanitize_key( wp_unslash( $_POST['scan_mode'] ) ) : 'changed';
		$mode  = 'rebuild' === $mode ? 'rebuild' : 'changed';
		$force = 'rebuild' === $mode;
		$run_id = ORAS_AI_Scan_Runs::start(
			$mode,
			$this->classification_rules->version(),
			ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION,
			ORAS_AI_Config::get_openai_model()
		);

		$result = $this->discover_wordpress_sources( $force );

		if ( is_wp_error( $result ) ) {
			ORAS_AI_Scan_Runs::record_outcome( $run_id, 'error' );
			ORAS_AI_Scan_Runs::complete( $run_id );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		ORAS_AI_Scan_Runs::record_discovery( $run_id, $result );
		$result['run_id'] = $run_id;
		$result['mode'] = $mode;
		wp_send_json_success( $result );
	}

	public function ajax_process_source() {
		$this->verify_ajax();

		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$run_id    = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

		if ( ! $source_id || self::POST_TYPE !== get_post_type( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid knowledge source.', 'oras-ai-assistant' ) ) );
		}

		if ( ! $run_id || ! ORAS_AI_Scan_Runs::find( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan run.', 'oras-ai-assistant' ) ) );
		}

		$result = $this->process_source( $source_id );

		if ( is_wp_error( $result ) ) {
			ORAS_AI_Scan_Runs::record_outcome( $run_id, 'error' );
			ORAS_AI_Scan_Runs::complete( $run_id );
			wp_send_json_error(
				array(
					'message'   => $result->get_error_message(),
					'source_id' => $source_id,
				)
			);
		}

		$outcomes = array(
			'static_knowledge' => 'static',
			'mixed'            => 'mixed',
			'live_data'        => 'live',
			'ignore'           => 'ignored',
			'review'           => 'review',
			'excluded'         => 'excluded',
		);
		$kind   = isset( $result['kind'] ) ? $result['kind'] : '';
		$status = get_post_meta( $source_id, '_oras_ai_scan_status', true );
		ORAS_AI_Scan_Runs::record_outcome(
			$run_id,
			isset( $outcomes[ $kind ] ) ? $outcomes[ $kind ] : 'review',
			'review' === $status
		);

		wp_send_json_success( $result );
	}

	public function ajax_complete_scan() {
		$this->verify_ajax();

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		if ( ! $run_id || ! ORAS_AI_Scan_Runs::find( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan run.', 'oras-ai-assistant' ) ) );
		}

		ORAS_AI_Scan_Runs::complete( $run_id );
		wp_send_json_success( array( 'run_id' => $run_id ) );
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
		$excluded   = 0;

		foreach ( $posts as $post ) {
			if ( ORAS_AI_Knowledge_Base::POST_TYPE === $post->post_type || self::POST_TYPE === $post->post_type ) {
				continue;
			}

			$source_id = $this->find_source_by_wp_post_id( $post->ID );
			if ( $source_id && self::is_source_excluded( $source_id ) ) {
				$discovered[] = $source_id;
				$excluded++;
				update_post_meta( $source_id, '_oras_ai_scan_status', 'excluded' );
				continue;
			}

			$content = $this->extract_post_content( $post );

			if ( '' === trim( $content ) ) {
				continue;
			}

			$url  = get_permalink( $post );
			$hash = hash( 'sha256', $post->post_title . '|' . $url . '|' . $content );

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
			$stored_rule_version = get_post_meta( $source_id, ORAS_AI_Source_Classification_Rules::META_RULE_VERSION, true );
			$effective_rule_version = $this->classification_rules->effective_version( $stored_rule_version );
			$stored_extraction_version = absint( get_post_meta( $source_id, '_oras_ai_extraction_version', true ) );

			if ( $force || $hash !== $old_hash || $effective_rule_version !== $this->classification_rules->version() || ORAS_AI_Source_Classification_Result::EXTRACTION_VERSION !== $stored_extraction_version || ! in_array( $status, array( 'complete', 'ignored', 'live', 'review' ), true ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'pending' );
				$queue[] = $source_id;
			}
		}

		$missing = $this->retire_missing_sources( $discovered );
		$queue   = array_values( array_unique( $queue ) );

		return array(
			'found'      => count( $discovered ),
			'discovered' => count( $discovered ),
			'queue'      => $queue,
			'unchanged'  => max( 0, count( $discovered ) - count( $queue ) - $excluded ),
			'excluded'   => $excluded,
			'missing'    => $missing['missing'],
			'retired'    => $missing['retired'],
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

	private function linked_artifact_ids( $source_id ) {
		$artifact_ids = array();
		$primary_id   = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );
		$stored_ids   = get_post_meta( $source_id, '_oras_ai_kb_entry_ids', true );
		$stored_ids   = is_array( $stored_ids ) ? $stored_ids : array();

		if ( $primary_id ) {
			$artifact_ids[] = $primary_id;
		}

		foreach ( $stored_ids as $artifact_id ) {
			$artifact_id = absint( $artifact_id );
			if ( $artifact_id && ! in_array( $artifact_id, $artifact_ids, true ) ) {
				$artifact_ids[] = $artifact_id;
			}
		}

		$backlinked_ids = get_posts(
			array(
				'post_type'      => ORAS_AI_Knowledge_Base::POST_TYPE,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_oras_ai_source_record_id',
				'meta_value'     => absint( $source_id ),
				'no_found_rows'  => true,
			)
		);

		foreach ( $backlinked_ids as $artifact_id ) {
			$artifact_id = absint( $artifact_id );
			if ( $artifact_id && ! in_array( $artifact_id, $artifact_ids, true ) ) {
				$artifact_ids[] = $artifact_id;
			}
		}

		return $artifact_ids;
	}

	private function artifact_admin_label( $artifact_id ) {
		$status_labels = array(
			'draft'    => __( 'Draft', 'oras-ai-assistant' ),
			'approved' => __( 'Approved', 'oras-ai-assistant' ),
			'review'   => __( 'Needs Review', 'oras-ai-assistant' ),
			'retired'  => __( 'Retired', 'oras-ai-assistant' ),
		);
		$status = ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id );
		$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : __( 'Inactive', 'oras-ai-assistant' );
		$owner_label = ORAS_AI_Knowledge_Base::is_scanner_managed( $artifact_id )
			? __( 'Scanner-managed', 'oras-ai-assistant' )
			: __( 'Manual', 'oras-ai-assistant' );

		return $status_label . ' — ' . $owner_label;
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

		$retired = 0;
		foreach ( $missing as $source_id ) {
			update_post_meta( $source_id, '_oras_ai_scan_status', 'missing' );
			$retired += $this->retire_managed_artifacts( $source_id );
		}

		return array(
			'missing' => count( $missing ),
			'retired' => $retired,
		);
	}


	private function deterministic_classification( $source ) {
		$post_type = get_post_meta( $source->ID, '_oras_ai_wp_post_type', true );
		$url       = (string) get_post_meta( $source->ID, '_oras_ai_source_url', true );
		$title     = (string) $source->post_title;

		return $this->classification_rules->classify( $post_type, $title, $url );
	}

	private function deterministic_category( $title, $url, $fallback = 'General FAQ' ) {
		return $this->classification_rules->category_for( $title, $url, $fallback );
	}

	private function should_auto_approve( $post_type, $classification ) {
		if ( ! $classification instanceof ORAS_AI_Source_Classification_Result ) {
			return false;
		}

		if ( 'static_knowledge' !== $classification->source_kind() ) {
			return false;
		}

		if ( 'high' !== $classification->confidence() || $classification->requires_review() ) {
			return false;
		}

		/*
		 * Normal pages and ORAS speaker records are safe to synchronize
		 * automatically. Other model-judged source types require review.
		 */
		return in_array( $post_type, array( 'page', 'oras_speaker' ), true );
	}

	private function is_managed_artifact_for_source( $entry_id, $source_id ) {
		$entry_id  = absint( $entry_id );
		$source_id = absint( $source_id );

		if ( ! ORAS_AI_Knowledge_Base::is_scanner_managed( $entry_id ) ) {
			return false;
		}

		$artifact_source_id = absint( get_post_meta( $entry_id, '_oras_ai_source_record_id', true ) );
		if ( $artifact_source_id ) {
			return $source_id === $artifact_source_id;
		}

		$primary_id = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );
		$stored_ids = get_post_meta( $source_id, '_oras_ai_kb_entry_ids', true );
		$stored_ids = is_array( $stored_ids ) ? array_map( 'absint', $stored_ids ) : array();

		return $entry_id === $primary_id || in_array( $entry_id, $stored_ids, true );
	}

	private function managed_artifact_ids( $source_id ) {
		$stored_ids = get_post_meta( $source_id, '_oras_ai_kb_entry_ids', true );
		$stored_ids = is_array( $stored_ids ) ? $stored_ids : array();
		$legacy_id  = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );

		if ( $legacy_id && ! in_array( $legacy_id, $stored_ids, true ) ) {
			array_unshift( $stored_ids, $legacy_id );
		}

		foreach ( $this->linked_artifact_ids( $source_id ) as $entry_id ) {
			if ( ! in_array( $entry_id, $stored_ids, true ) ) {
				$stored_ids[] = $entry_id;
			}
		}

		$managed_ids = array();
		foreach ( $stored_ids as $entry_id ) {
			$entry_id = absint( $entry_id );
			if ( $this->is_managed_artifact_for_source( $entry_id, $source_id ) && ! in_array( $entry_id, $managed_ids, true ) ) {
				$managed_ids[] = $entry_id;
			}
		}

		return $managed_ids;
	}

	private function retire_managed_artifacts( $source_id, $keep_ids = array() ) {
		$keep_ids = array_map( 'absint', $keep_ids );
		$retired  = 0;

		foreach ( $this->managed_artifact_ids( $source_id ) as $entry_id ) {
			if ( ! in_array( $entry_id, $keep_ids, true ) && 'retired' !== ORAS_AI_Knowledge_Base::lifecycle_status( $entry_id ) ) {
				update_post_meta( $entry_id, '_oras_ai_status', 'retired' );
				$retired++;
			}
		}

		return $retired;
	}

	private function refresh_source_review_status( $source_id ) {
		foreach ( $this->managed_artifact_ids( $source_id ) as $artifact_id ) {
			if ( 'review' === ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'review' );
				return;
			}
		}

		if ( 'review' === get_post_meta( $source_id, '_oras_ai_scan_status', true ) ) {
			update_post_meta( $source_id, '_oras_ai_scan_status', 'complete' );
		}
	}

	private function record_processing_problem( $source_id, $kind, $detail ) {
		$count = absint( get_post_meta( $source_id, '_oras_ai_problem_count', true ) ) + 1;
		update_post_meta( $source_id, '_oras_ai_problem_count', $count );
		update_post_meta( $source_id, '_oras_ai_last_problem_kind', 'error' === $kind ? 'error' : 'review' );
		update_post_meta( $source_id, '_oras_ai_last_problem', sanitize_textarea_field( $detail ) );
		update_post_meta( $source_id, '_oras_ai_last_problem_at', current_time( 'mysql' ) );
	}

	private function artifact_provenance( $source_id, $result, $fragment_index = null ) {
		return array(
			'source_wp_post_id'       => get_post_meta( $source_id, '_oras_ai_wp_post_id', true ),
			'source_wp_post_type'     => get_post_meta( $source_id, '_oras_ai_wp_post_type', true ),
			'source_hash'             => get_post_meta( $source_id, '_oras_ai_source_hash', true ),
			'source_modified_gmt'     => get_post_meta( $source_id, '_oras_ai_wp_modified_gmt', true ),
			'synced_at'               => current_time( 'mysql' ),
			'rule_version'            => $this->classification_rules->version(),
			'extraction_version'      => $result->extraction_version(),
			'source_classification'   => $result->source_kind(),
			'source_confidence'       => $result->confidence(),
			'historical_event'        => $result->is_historical_event(),
			'fragment_index'          => $fragment_index,
			'excluded_dynamic_claims' => $result->excluded_dynamic_claims(),
			'dynamic_fact_types'      => $result->dynamic_fact_types(),
			'extraction_reason'       => $result->reason(),
		);
	}

	private function sync_mixed_artifacts( $source, $result, $url ) {
		$source_id    = (int) $source->ID;
		$existing_ids = $this->managed_artifact_ids( $source_id );
		$artifact_ids = array();

		foreach ( $result->stable_fragments() as $index => $fragment ) {
			$entry_id = isset( $existing_ids[ $index ] ) ? $existing_ids[ $index ] : 0;
			$entry_id = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
				array_merge(
					array(
						'entry_id'        => $entry_id,
						'source_id'       => $source_id,
						'title'           => $fragment['stable_title'],
						'content'         => $fragment['stable_content'],
						'category'        => $result->category(),
						'visibility'      => $result->visibility(),
						'status'          => 'review',
						'source_label'    => 'ORAS Website – ' . $source->post_title,
						'source_url'      => $url,
						'internal_notes'  => 'Automatically extracted stable knowledge from a Mixed source. Review required. ' . $result->reason(),
						'lookup_existing' => false,
					),
					$this->artifact_provenance( $source_id, $result, $index )
				)
			);

			if ( is_wp_error( $entry_id ) ) {
				return $entry_id;
			}

			$artifact_ids[] = (int) $entry_id;
		}

		$this->retire_managed_artifacts( $source_id, $artifact_ids );

		update_post_meta( $source_id, '_oras_ai_kb_entry_ids', $artifact_ids );
		update_post_meta( $source_id, '_oras_ai_kb_entry_id', $artifact_ids[0] );

		return $artifact_ids;
	}

	private function process_source( $source_id ) {
		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'oras_ai_source_missing', __( 'Source record not found.', 'oras-ai-assistant' ) );
		}

		if ( self::is_source_excluded( $source_id ) ) {
			update_post_meta( $source_id, '_oras_ai_scan_status', 'excluded' );
			return array(
				'source_id'     => $source_id,
				'title'         => $source->post_title,
				'kind'          => 'excluded',
				'category'      => get_post_meta( $source_id, '_oras_ai_source_category', true ),
				'confidence'    => get_post_meta( $source_id, '_oras_ai_source_confidence', true ),
				'classified_by' => 'admin',
				'kb_id'         => absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) ),
				'kb_ids'        => $this->managed_artifact_ids( $source_id ),
			);
		}

		$url       = get_post_meta( $source_id, '_oras_ai_source_url', true );
		$post_type = get_post_meta( $source_id, '_oras_ai_wp_post_type', true );
		$content   = trim( (string) $source->post_content );

		$result = $this->deterministic_classification( $source );

		if ( null === $result ) {
			$result = $this->source_classifier->classify_source(
				$source->post_title,
				$url,
				$post_type,
				$content
			);

			if ( is_wp_error( $result ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $result->get_error_message() );
				$this->record_processing_problem( $source_id, 'error', $result->get_error_message() );
				return $result;
			}
		}

		if ( ! $result instanceof ORAS_AI_Source_Classification_Result ) {
			$error = new WP_Error(
				'oras_ai_invalid_classification_result',
				__( 'Source classifier returned an invalid application result.', 'oras-ai-assistant' )
			);
			update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
			update_post_meta( $source_id, '_oras_ai_last_error', $error->get_error_message() );
			$this->record_processing_problem( $source_id, 'error', $error->get_error_message() );
			return $error;
		}

		$kind          = $result->source_kind();
		$category      = $result->category();
		$visibility    = $result->visibility();
		$confidence    = $result->confidence();
		$title         = $result->knowledge_title();
		$reason        = $result->reason();
		$classified_by = $result->classified_by();

		update_post_meta( $source_id, '_oras_ai_source_kind', $kind );
		update_post_meta( $source_id, '_oras_ai_source_category', $category );
		update_post_meta( $source_id, '_oras_ai_source_visibility', $visibility );
		update_post_meta( $source_id, '_oras_ai_source_confidence', $confidence );
		update_post_meta( $source_id, '_oras_ai_source_reason', $reason );
		update_post_meta( $source_id, '_oras_ai_classified_by', $classified_by );
		update_post_meta( $source_id, ORAS_AI_Source_Classification_Rules::META_RULE_VERSION, $this->classification_rules->version() );
		update_post_meta( $source_id, '_oras_ai_last_analyzed', current_time( 'mysql' ) );
		delete_post_meta( $source_id, '_oras_ai_last_error' );

		$kb_id  = absint( get_post_meta( $source_id, '_oras_ai_kb_entry_id', true ) );
		$kb_ids = array();

		if ( 'static_knowledge' === $kind ) {
			$knowledge_status = $this->should_auto_approve( $post_type, $result ) ? 'approved' : 'review';

			$kb_id = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
				array_merge(
					array(
						'entry_id'       => $this->is_managed_artifact_for_source( $kb_id, $source_id ) ? $kb_id : 0,
						'source_id'      => $source_id,
						'title'          => $title ?: $source->post_title,
						'content'        => $content,
						'category'       => $category,
						'visibility'     => $visibility,
						'status'         => $knowledge_status,
						'source_label'   => 'ORAS Website – ' . $source->post_title,
						'source_url'     => $url,
						'internal_notes' => 'Automatically managed by the ORAS AI website scanner. Classified by ' . ( 'rule' === $classified_by ? 'WordPress rule' : 'AI' ) . '. ' . $reason,
					),
					$this->artifact_provenance( $source_id, $result )
				)
			);

			if ( is_wp_error( $kb_id ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $kb_id->get_error_message() );
				$this->record_processing_problem( $source_id, 'error', $kb_id->get_error_message() );
				return $kb_id;
			}

			$this->retire_managed_artifacts( $source_id, array( $kb_id ) );
			update_post_meta( $source_id, '_oras_ai_kb_entry_id', $kb_id );
			delete_post_meta( $source_id, '_oras_ai_kb_entry_ids' );
			$kb_ids = array( (int) $kb_id );
			update_post_meta( $source_id, '_oras_ai_scan_status', 'review' === $knowledge_status ? 'review' : 'complete' );
			if ( 'review' === $knowledge_status ) {
				$this->record_processing_problem( $source_id, 'review', $reason );
			}
		} elseif ( 'mixed' === $kind ) {
			$kb_ids = $this->sync_mixed_artifacts( $source, $result, $url );

			if ( is_wp_error( $kb_ids ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $kb_ids->get_error_message() );
				$this->record_processing_problem( $source_id, 'error', $kb_ids->get_error_message() );
				return $kb_ids;
			}

			$kb_id = $kb_ids[0];
			update_post_meta( $source_id, '_oras_ai_scan_status', 'review' );
			$this->record_processing_problem( $source_id, 'review', $reason );
		} elseif ( 'review' === $kind || $result->requires_review() ) {
			$kb_id = ORAS_AI_Knowledge_Base::upsert_scanned_entry(
				array_merge(
					array(
						'entry_id'       => $this->is_managed_artifact_for_source( $kb_id, $source_id ) ? $kb_id : 0,
						'source_id'      => $source_id,
						'title'          => $title ?: $source->post_title,
						'content'        => $content,
						'category'       => $category,
						'visibility'     => $visibility,
						'status'         => 'review',
						'source_label'   => 'ORAS Website – ' . $source->post_title,
						'source_url'     => $url,
						'internal_notes' => 'Scanner marked this source for review. Classified by ' . ( 'rule' === $classified_by ? 'WordPress rule' : 'AI' ) . '. ' . $reason,
					),
					$this->artifact_provenance( $source_id, $result )
				)
			);

			if ( is_wp_error( $kb_id ) ) {
				update_post_meta( $source_id, '_oras_ai_scan_status', 'error' );
				update_post_meta( $source_id, '_oras_ai_last_error', $kb_id->get_error_message() );
				$this->record_processing_problem( $source_id, 'error', $kb_id->get_error_message() );
				return $kb_id;
			}

			$this->retire_managed_artifacts( $source_id, array( $kb_id ) );
			update_post_meta( $source_id, '_oras_ai_kb_entry_id', $kb_id );
			delete_post_meta( $source_id, '_oras_ai_kb_entry_ids' );
			$kb_ids = array( (int) $kb_id );
			update_post_meta( $source_id, '_oras_ai_scan_status', 'review' );
			$this->record_processing_problem( $source_id, 'review', $reason );
		} else {
			/*
			 * Rebuild cleanup: if this source previously created a scanner-managed
			 * Knowledge Base record but is now Live Data or Ignored, retire it.
			 * Manual KB entries are never touched.
			 */
			$this->retire_managed_artifacts( $source_id );

			update_post_meta( $source_id, '_oras_ai_scan_status', 'live_data' === $kind ? 'live' : 'ignored' );
		}

		update_post_meta( $source_id, '_oras_ai_extraction_version', $result->extraction_version() );

		return array(
			'source_id'     => $source_id,
			'title'         => $source->post_title,
			'kind'          => $kind,
			'category'      => $category,
			'confidence'    => $confidence,
			'classified_by' => $classified_by,
			'kb_id'         => $kb_id,
			'kb_ids'        => $kb_ids,
		);
	}
}
