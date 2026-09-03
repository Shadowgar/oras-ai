<?php
/**
 * Plugin Name: ORAS AI Assistant
 * Description: ORAS knowledge base, website knowledge scanner, and foundation for a members-only AI assistant.
 * Version: 0.2.1
 * Author: Oil Region Astronomical Society
 * Text Domain: oras-ai-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORAS_AI_VERSION', '0.2.1' );
define( 'ORAS_AI_PLUGIN_FILE', __FILE__ );
define( 'ORAS_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORAS_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-config.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-audit-log.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-access-guard.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/interface-oras-ai-membership-authorizer.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-pmpro-membership-authorizer.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-authorized-request.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-domain-result.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/interface-oras-ai-domain-classifier.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-openai-domain-classifier.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-domain-observability.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-domain-guard.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-guarded-request.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-capability-registry.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-url-policy.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-request-gateway.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-knowledge-base.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-source-classification-result.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/interface-oras-ai-source-classifier.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-openai.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-openai-source-classifier.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-source-classification-rules.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-scan-runs.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-sources.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-retrieval-request.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-evidence.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-evidence-packet.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/interface-oras-ai-retriever.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-source-precedence.php';
require_once ORAS_AI_PLUGIN_DIR . 'includes/class-oras-ai-wordpress-retriever.php';

final class ORAS_AI_Assistant {

	private $sources;
	private $request_gateway;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		new ORAS_AI_Knowledge_Base();
		$this->sources = new ORAS_AI_Sources();
		$this->request_gateway = new ORAS_AI_Request_Gateway( new ORAS_AI_PMPro_Membership_Authorizer() );
	}

	public static function activate() {
		update_option( 'oras_ai_version', ORAS_AI_VERSION, false );
		flush_rewrite_rules();
	}

	public function maybe_upgrade() {
		$installed = get_option( 'oras_ai_version', '0.0.0' );

		if ( version_compare( $installed, ORAS_AI_VERSION, '<' ) ) {
			ORAS_AI_Knowledge_Base::seed_default_categories();
			update_option( 'oras_ai_version', ORAS_AI_VERSION, false );
		}
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'ORAS AI', 'oras-ai-assistant' ),
			__( 'ORAS AI', 'oras-ai-assistant' ),
			'edit_posts',
			'oras-ai-assistant',
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			26
		);

		add_submenu_page(
			'oras-ai-assistant',
			__( 'ORAS AI Dashboard', 'oras-ai-assistant' ),
			__( 'Dashboard', 'oras-ai-assistant' ),
			'edit_posts',
			'oras-ai-assistant',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'oras-ai-assistant',
			__( 'Knowledge Sources', 'oras-ai-assistant' ),
			__( 'Knowledge Sources', 'oras-ai-assistant' ),
			'manage_options',
			'oras-ai-sources',
			array( $this->sources, 'render_sources_page' )
		);

		add_submenu_page(
			'oras-ai-assistant',
			__( 'Needs Review', 'oras-ai-assistant' ),
			__( 'Needs Review', 'oras-ai-assistant' ),
			'manage_options',
			'oras-ai-review',
			array( $this->sources, 'render_review_page' )
		);

		add_submenu_page(
			'oras-ai-assistant',
			__( 'AI Settings', 'oras-ai-assistant' ),
			__( 'AI Settings', 'oras-ai-assistant' ),
			'manage_options',
			'oras-ai-settings',
			array( $this->sources, 'render_settings_page' )
		);
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$counts = wp_count_posts( ORAS_AI_Knowledge_Base::POST_TYPE );
		$total  = 0;

		if ( $counts ) {
			$visible_statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );

			foreach ( $visible_statuses as $post_status ) {
				if ( isset( $counts->{$post_status} ) ) {
					$total += (int) $counts->{$post_status};
				}
			}
		}

		$active_total  = ORAS_AI_Knowledge_Base::count_active_artifacts();
		$review_total  = ORAS_AI_Knowledge_Base::count_artifacts_by_lifecycle( 'review' );
		$retired_total = ORAS_AI_Knowledge_Base::count_artifacts_by_lifecycle( 'retired' );
		$source_count = ORAS_AI_Sources::count_sources();
		$api_ready    = ORAS_AI_Config::has_openai_api_key();
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'ORAS AI Assistant', 'oras-ai-assistant' ); ?></h1>
			<p class="oras-ai-lead">
				<?php esc_html_e( 'Authoritative ORAS knowledge used by the future member assistant.', 'oras-ai-assistant' ); ?>
			</p>

			<div class="oras-ai-cards">
				<div class="oras-ai-card">
					<h2><?php esc_html_e( 'Knowledge Base', 'oras-ai-assistant' ); ?></h2>
					<p class="oras-ai-number"><?php echo esc_html( number_format_i18n( $active_total ) ); ?></p>
					<p><?php esc_html_e( 'Active Approved knowledge', 'oras-ai-assistant' ); ?></p>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								__( '%1$s Needs Review · %2$s Retired · %3$s total records', 'oras-ai-assistant' ),
								number_format_i18n( $review_total ),
								number_format_i18n( $retired_total ),
								number_format_i18n( $total )
							)
						);
						?>
					</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ORAS_AI_Knowledge_Base::POST_TYPE ) ); ?>">
							<?php esc_html_e( 'Open Knowledge Base', 'oras-ai-assistant' ); ?>
						</a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=oras-ai-review' ) ); ?>">
							<?php esc_html_e( 'Needs Review', 'oras-ai-assistant' ); ?>
						</a>
						<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . ORAS_AI_Knowledge_Base::POST_TYPE ) ); ?>">
							<?php esc_html_e( 'Add Manual Entry', 'oras-ai-assistant' ); ?>
						</a>
					</p>
				</div>

				<div class="oras-ai-card">
					<h2><?php esc_html_e( 'Website Sources', 'oras-ai-assistant' ); ?></h2>
					<p class="oras-ai-number"><?php echo esc_html( number_format_i18n( $source_count ) ); ?></p>
					<p><?php esc_html_e( 'WordPress sources discovered by the scanner', 'oras-ai-assistant' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=oras-ai-sources' ) ); ?>">
							<?php esc_html_e( 'Knowledge Sources', 'oras-ai-assistant' ); ?>
						</a>
					</p>
				</div>

				<div class="oras-ai-card">
					<h2><?php esc_html_e( 'AI Connection', 'oras-ai-assistant' ); ?></h2>
					<p class="oras-ai-status <?php echo $api_ready ? 'is-ready' : 'is-warning'; ?>">
						<?php echo esc_html( $api_ready ? __( 'API key configured', 'oras-ai-assistant' ) : __( 'API key not configured', 'oras-ai-assistant' ) ); ?>
					</p>
					<p><?php esc_html_e( 'GPT-5.6 Luna is the default model used to classify website content.', 'oras-ai-assistant' ); ?></p>
					<p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=oras-ai-settings' ) ); ?>">
							<?php esc_html_e( 'AI Settings', 'oras-ai-assistant' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	public function enqueue_admin_assets() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_oras_ai_screen =
			false !== strpos( (string) $screen->id, 'oras-ai' ) ||
			ORAS_AI_Knowledge_Base::POST_TYPE === $screen->post_type;

		if ( ! $is_oras_ai_screen ) {
			return;
		}

		wp_enqueue_style(
			'oras-ai-admin',
			ORAS_AI_PLUGIN_URL . 'assets/admin.css',
			array(),
			ORAS_AI_VERSION
		);

		if ( isset( $_GET['page'] ) && 'oras-ai-sources' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_enqueue_script(
				'oras-ai-scanner',
				ORAS_AI_PLUGIN_URL . 'assets/scanner.js',
				array( 'jquery' ),
				ORAS_AI_VERSION,
				true
			);

			wp_localize_script(
				'oras-ai-scanner',
				'ORAS_AI_SCAN',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'oras_ai_scan' ),
					'settings'  => admin_url( 'admin.php?page=oras-ai-settings' ),
					'strings'   => array(
						'starting'   => __( 'Discovering WordPress content…', 'oras-ai-assistant' ),
						'processing' => __( 'Analyzing source', 'oras-ai-assistant' ),
						'complete'   => __( 'Scan complete. Reloading results…', 'oras-ai-assistant' ),
						'failed'     => __( 'The scan stopped because of an error.', 'oras-ai-assistant' ),
					),
				)
			);
		}
	}
}

register_activation_hook( __FILE__, array( 'ORAS_AI_Assistant', 'activate' ) );

new ORAS_AI_Assistant();
